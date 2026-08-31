<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Crm\Member;
use App\Models\Crm\Punch;
use App\Models\Crm\SalarySlip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Salary slips: one row per employee per month, generated from the salary
 * history on the employee profile with the bank details snapshotted in.
 * The month's punch summary rides along so whoever finalises payable can
 * see attendance next to the number — the old CRM made you look that up
 * in another tab. Employees always see their own slips; managing the run
 * needs the salary module right.
 */
class SalaryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        abort_unless($month >= 1 && $month <= 12, 422, 'Month must be 1-12.');

        // Salary is an individual matter: only the Admin/Subadmin see the
        // company run. A Team Workspace leader — or anyone granted salary
        // rights — still sees ONLY their own slips here.
        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);

        $query = SalarySlip::with('member.user:id,name')
            ->where('organization_id', $org->id);

        // Between dates: 'YYYY-MM' to 'YYYY-MM' reads a whole period at
        // once; without it, the single month as always.
        $from = $request->query('month_from');
        $to = $request->query('month_to');
        if ($from && $to) {
            abort_if($to < $from, 422, 'The last month cannot come before the first.');
            // year*100+month keeps this portable across MySQL and SQLite.
            $code = fn (string $ym) => (int) str_replace('-', '', $ym);
            $query->whereRaw('(year * 100 + month) between ? and ?', [$code($from), $code($to)]);
        } else {
            $query->where('year', $year)->where('month', $month);
        }
        if (! $manages) {
            $query->where('member_id', $me->id);
        } elseif ($memberFilter = $request->query('member')) {
            $query->whereHas('member', fn ($m) => $m->where('uuid', $memberFilter));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $slips = $query->orderBy('year')->orderBy('month')->orderBy('id')->get();

        // Attendance context for the month, one query for everyone shown.
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $punches = Punch::where('organization_id', $org->id)
            ->whereIn('member_id', $slips->pluck('member_id'))
            ->whereDate('work_date', '>=', $monthStart)
            ->whereDate('work_date', '<=', $monthEnd)
            ->get()
            ->groupBy('member_id');

        $rows = $slips->map(fn (SalarySlip $s) => $this->serialize($s, $punches[$s->member_id] ?? collect()));

        return response()->json(['data' => $rows, 'totals' => [
            'payable' => round($slips->sum('payable'), 2),
            'additions' => round($slips->sum('additions'), 2),
            'deductions' => round($slips->sum('deductions'), 2),
            'net' => round($slips->sum('net_salary'), 2),
            'incentive' => round($slips->sum('incentive_amount'), 2),
            'net_without_incentive' => round($slips->sum(fn ($s) => (float) ($s->net_without_incentive ?? $s->net_salary)), 2),
            'paid' => round($slips->where('status', 'paid')->sum('net_salary'), 2),
            'pending' => round($slips->where('status', 'pending')->sum('net_salary'), 2),
        ], 'year' => $year, 'month' => $month, 'manages' => $manages]);
    }

    /** Start the month: one slip per active employee, from their salary record. */
    public function generate(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            // Tear up the month's PENDING slips and compute them afresh from
            // the structures as they stand now. A slip generated before a
            // structure existed carries no breakdown, and this is the way
            // it gets one. Paid slips are history and are never touched.
            'refresh_pending' => ['nullable', 'boolean'],
        ]);

        $rebuilt = 0;
        // The admin's hand-entered money on the torn-up slips survives the
        // rebuild — a bonus is a decision, not something the calendar knows.
        $manual = [];
        if ($data['refresh_pending'] ?? false) {
            $pending = SalarySlip::where('organization_id', $org->id)
                ->where('year', $data['year'])->where('month', $data['month'])
                ->where('status', 'pending')
                ->get();
            foreach ($pending as $slip) {
                if ((float) $slip->additions != 0 || $slip->deduction_note) {
                    $manual[$slip->member_id] = [
                        'additions' => (float) $slip->additions,
                        'note' => $slip->deduction_note,
                    ];
                }
                $this->unwind($slip);
                $rebuilt++;
            }
        }

        $members = Member::visible()->with('user:id,name')
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->get();

        // One calendar read for the whole month, shared by every slip.
        $from = \Carbon\Carbon::create($data['year'], $data['month'], 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $calendar = new \App\Services\Crm\AttendanceCalendar($org);
        $attendance = $calendar->summarise($calendar->build($members, $from, $to))
            ->keyBy(fn ($row) => $members->firstWhere('uuid', $row['member_uuid'])?->id)
            ->map(fn ($row) => $row + ['month_days' => $from->daysInMonth])
            ->all();

        $created = 0;
        $skippedNoSalary = [];
        DB::transaction(function () use ($org, $data, $members, $request, $attendance, $manual, &$created, &$skippedNoSalary) {
            $calculator = new \App\Services\Crm\SalaryCalculator(
                $org,
                new \App\Services\Crm\IncentiveCalculator($org),
            );
            $month = \Carbon\Carbon::create($data['year'], $data['month'], 1);

            foreach ($members as $member) {
                $exists = SalarySlip::where('organization_id', $org->id)
                    ->where('member_id', $member->id)
                    ->where('year', $data['year'])->where('month', $data['month'])
                    ->exists();
                if ($exists) {
                    continue;
                }

                // A member with neither a CTC structure nor the old single
                // salary number has nothing to compute from.
                if (! $calculator->structureFor($member, $month) && ! $member->currentSalary()) {
                    $skippedNoSalary[] = $member->user?->name;
                    continue;
                }

                // The whole month — components prorated by the attendance
                // calendar, statutory money on both sides, the incentive the
                // plan releases this month, loans working their way back.
                $calc = $calculator->compute($member, $month, $attendance[$member->id] ?? null);

                $keep = $manual[$member->id] ?? ['additions' => 0.0, 'note' => null];

                $slip = SalarySlip::create([
                    'organization_id' => $org->id,
                    'member_id' => $member->id,
                    'year' => $data['year'],
                    'month' => $data['month'],
                    'additions' => $keep['additions'],
                    'deduction_note' => $keep['note'],
                    'monthly_salary' => $calc['monthly_salary'],
                    'month_days' => $calc['month_days'],
                    'payable_days' => $calc['payable_days'],
                    'lop_days' => $calc['lop_days'],
                    'earnings' => $calc['earnings'],
                    'deduction_lines' => $calc['deduction_lines'],
                    'incentive_amount' => $calc['incentive_amount'],
                    'incentive_breakdown' => $calc['incentive_breakdown'],
                    'incentive_month' => $calc['incentive_month'],
                    'payable' => $calc['gross_payable'],
                    'deductions' => $calc['total_deductions'],
                    'net_salary' => round($calc['net_salary'] + $keep['additions'], 2),
                    'net_without_incentive' => round($calc['net_without_incentive'] + $keep['additions'], 2),
                    'bank_name' => $member->bank_name,
                    'account_holder' => $member->bank_account_name,
                    'account_no' => $member->bank_account_no,
                    'ifsc' => $member->bank_ifsc,
                    'created_by' => $request->user()->id,
                ]);

                // What the slip recovers is written into the loan's own book,
                // so the balance falls with the payroll and never twice.
                foreach ($calc['loan_lines'] as $line) {
                    \App\Models\Crm\LoanRepayment::create([
                        'loan_id' => $line['loan_id'],
                        'salary_slip_id' => $slip->id,
                        'amount' => $line['amount'],
                        'repaid_on' => $month->copy()->endOfMonth()->toDateString(),
                        'note' => 'Recovered in ' . $month->format('F Y') . ' payroll',
                        'created_by' => $request->user()->id,
                    ]);
                    $loan = \App\Models\Crm\Loan::find($line['loan_id']);
                    if ($loan && $loan->balance() <= 0) {
                        $loan->update(['status' => 'closed']);
                    }
                }

                $created++;
            }
        });

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'salary.generated', $org, array_filter([
            'month' => sprintf('%04d-%02d', $data['year'], $data['month']),
            'created' => $created,
            'rebuilt' => $rebuilt ?: null,
        ]));

        $message = $created . ' slips generated.';
        if ($rebuilt > 0) {
            $message = $rebuilt . ' pending slip' . ($rebuilt === 1 ? '' : 's') . ' rebuilt from the current structures; ' . $message;
        }
        if ($skippedNoSalary !== []) {
            $message .= ' Skipped (no salary set): ' . implode(', ', array_filter($skippedNoSalary)) . '.';
        }

        return response()->json(['message' => $message]);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $slip = SalarySlip::where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'payable' => ['nullable', 'numeric', 'min:0'],
            'additions' => ['nullable', 'numeric', 'min:0'],
            'deductions' => ['nullable', 'numeric', 'min:0'],
            'deduction_note' => ['nullable', 'string', 'max:512'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_holder' => ['nullable', 'string', 'max:255'],
            'account_no' => ['nullable', 'string', 'max:64'],
            'ifsc' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', Rule::in(['pending', 'paid'])],
            'paid_on' => ['nullable', 'date'],
            'payment_mode' => ['nullable', 'string', 'max:64'],
        ]);

        $slip->fill(array_filter($data, fn ($v) => $v !== null));

        // Net is always arithmetic — and the incentive-free reading moves
        // with it, so the two figures never drift apart under manual edits.
        $slip->net_salary = round((float) $slip->payable + (float) $slip->additions - (float) $slip->deductions, 2);
        $slip->net_without_incentive = round((float) $slip->net_salary - (float) $slip->incentive_amount, 2);
        if (($data['status'] ?? null) === 'paid' && ! $slip->paid_on) {
            $slip->paid_on = now()->toDateString();
        }
        $slip->save();

        // Manual money always leaves a trace — the edit and, when it flips
        // to paid, the payout itself.
        ActivityLog::record($request->attributes->get('crm_member'), $org->id,
            ($data['status'] ?? null) === 'paid' ? 'salary.paid' : 'salary.adjusted', $slip, array_filter([
                'employee' => $slip->member?->user?->name ?? $slip->account_holder,
                'month' => sprintf('%04d-%02d', $slip->year, $slip->month),
                'net' => (float) $slip->net_salary,
                'note' => $data['deduction_note'] ?? null,
            ]));

        return response()->json(['message' => 'Slip saved.', 'data' => $this->serialize($slip->fresh()->load('member.user:id,name'), collect())]);
    }

    /**
     * Mark many pending slips paid in one act — the payout run itself.
     * Paid slips in the selection are left untouched; the trail carries one
     * entry with the count and the money, not thirty.
     */
    public function markPaid(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:200'],
            'uuids.*' => ['string'],
            'paid_on' => ['nullable', 'date'],
            'payment_mode' => ['nullable', 'string', 'max:64'],
        ]);

        $slips = SalarySlip::with('member.user:id,name')
            ->where('organization_id', $org->id)
            ->whereIn('uuid', $data['uuids'])
            ->where('status', 'pending')
            ->get();

        if ($slips->isEmpty()) {
            abort(422, 'Nothing pending in that selection.');
        }

        $paidOn = $data['paid_on'] ?? now()->toDateString();
        foreach ($slips as $slip) {
            $slip->update([
                'status' => 'paid',
                'paid_on' => $paidOn,
                'payment_mode' => $data['payment_mode'] ?? $slip->payment_mode,
            ]);
        }

        ActivityLog::record($me, $org->id, 'salary.bulk_paid', $slips->first(), [
            'month' => sprintf('%04d-%02d', $slips->first()->year, $slips->first()->month),
            'count' => $slips->count(),
            'total' => round((float) $slips->sum('net_salary'), 2),
            'employees' => $slips->map(fn ($s) => $s->member?->user?->name)->filter()->implode(', '),
            'paid_on' => $paidOn,
        ]);

        return response()->json([
            'message' => $slips->count() . ' slip' . ($slips->count() === 1 ? '' : 's') . ' marked paid — '
                . number_format((float) $slips->sum('net_salary'), 2) . ' in all.',
        ]);
    }

    /**
     * The payslip as a PDF — earnings and deductions line by line down to
     * the net, the way the company's sheet reads. An employee downloads
     * their own; anyone else's needs the salary right.
     */
    public function pdf(Request $request, string $uuid)
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $slip = SalarySlip::with('member.user:id,name')
            ->where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();

        // Salary stays individual: own slip, or the Admin/Subadmin.
        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);
        abort_unless($manages || $slip->member_id === $me->id, 403, 'Not your payslip.');

        $monthName = \Carbon\Carbon::create($slip->year, $slip->month, 1)->format('F Y');

        // The registered company salaries are paid from (the Billing Setup
        // tick) — its details and logo head the payslip.
        $paying = \App\Models\Crm\IssuingCompany::where('organization_id', $org->id)
            ->where('pays_salary', true)->first();
        $logoPath = $paying?->logo_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->path($paying->logo_path)
            : null;

        $pdf = Pdf::loadView('crm.payslip', [
            'slip' => $slip,
            'org' => $org,
            'company' => $paying,
            'logoPath' => $logoPath && is_file($logoPath) ? $logoPath : null,
            'monthName' => $monthName,
        ]);

        return $pdf->download(
            'payslip-' . str_replace(' ', '-', strtolower($slip->member?->user?->name ?? 'employee'))
            . '-' . sprintf('%04d-%02d', $slip->year, $slip->month) . '.pdf',
        );
    }

    /**
     * Recompute ONE person's slip from the world as it stands now — after
     * an admin removed a late, withdrew a leave, or fixed the structure.
     * The pending slip is torn up (its loan recoveries going back) and
     * built again off the attendance calendar; a paid slip is history and
     * is refused.
     */
    public function recalculate(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $slip = SalarySlip::with('member.user:id,name')
            ->where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();

        if ($slip->status === 'paid') {
            abort(422, 'This slip is already paid — history is not recomputed.');
        }

        $member = $slip->member;
        $year = $slip->year;
        $month = $slip->month;
        $oldNet = (float) $slip->net_salary;
        // Manual money survives the recompute: an admin's +1,000 bonus is a
        // decision, not something the calendar knows — only the COMPUTED
        // side (payable, statutory deductions, incentive) is rebuilt.
        $keepAdditions = (float) $slip->additions;
        $keepNote = $slip->deduction_note;

        $newSlip = DB::transaction(function () use ($org, $slip, $member, $year, $month, $request, $keepAdditions, $keepNote) {
            $this->unwind($slip);

            $monthStart = \Carbon\Carbon::create($year, $month, 1);
            $calendar = new \App\Services\Crm\AttendanceCalendar($org);
            $members = collect([$member]);
            $attendance = $calendar->summarise(
                $calendar->build($members, $monthStart->copy()->startOfMonth(), $monthStart->copy()->endOfMonth())
            )->first();

            $calc = (new \App\Services\Crm\SalaryCalculator(
                $org, new \App\Services\Crm\IncentiveCalculator($org),
            ))->compute($member, $monthStart, $attendance);

            $fresh = SalarySlip::create([
                'organization_id' => $org->id,
                'member_id' => $member->id,
                'year' => $year,
                'month' => $month,
                'monthly_salary' => $calc['monthly_salary'],
                'month_days' => $calc['month_days'],
                'payable_days' => $calc['payable_days'],
                'lop_days' => $calc['lop_days'],
                'earnings' => $calc['earnings'],
                'deduction_lines' => $calc['deduction_lines'],
                'incentive_amount' => $calc['incentive_amount'],
                'incentive_breakdown' => $calc['incentive_breakdown'],
                'incentive_month' => $calc['incentive_month'],
                'payable' => $calc['gross_payable'],
                'additions' => $keepAdditions,
                'deduction_note' => $keepNote,
                'deductions' => $calc['total_deductions'],
                'net_salary' => round($calc['net_salary'] + $keepAdditions, 2),
                'net_without_incentive' => round($calc['net_without_incentive'] + $keepAdditions, 2),
                'bank_name' => $member->bank_name,
                'account_holder' => $member->bank_account_name,
                'account_no' => $member->bank_account_no,
                'ifsc' => $member->bank_ifsc,
                'created_by' => $request->user()->id,
            ]);

            foreach ($calc['loan_lines'] as $line) {
                \App\Models\Crm\LoanRepayment::create([
                    'loan_id' => $line['loan_id'],
                    'salary_slip_id' => $fresh->id,
                    'amount' => $line['amount'],
                    'repaid_on' => $monthStart->copy()->endOfMonth()->toDateString(),
                    'note' => 'Recovered in ' . $monthStart->format('F Y') . ' payroll',
                    'created_by' => $request->user()->id,
                ]);
                $loan = \App\Models\Crm\Loan::find($line['loan_id']);
                if ($loan && $loan->balance() <= 0) {
                    $loan->update(['status' => 'closed']);
                }
            }

            return $fresh;
        });

        $newNet = (float) $newSlip->net_salary;
        ActivityLog::record($me, $org->id, 'salary.recalculated', $newSlip, array_filter([
            'employee' => $member->user?->name,
            'month' => sprintf('%04d-%02d', $year, $month),
            'net_before' => $oldNet,
            'net_after' => $newNet,
            'moved_by' => round($newNet - $oldNet, 2) ?: null,
        ]));

        return response()->json([
            'message' => $newNet == $oldNet
                ? 'Recalculated — the net is unchanged at ' . number_format($newNet, 2) . '.'
                : 'Recalculated — net moved from ' . number_format($oldNet, 2) . ' to ' . number_format($newNet, 2) . '.',
            'data' => $this->serialize($newSlip->load('member.user:id,name'), collect()),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $slip = SalarySlip::where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();

        if ($slip->status === 'paid') {
            abort(422, 'A paid slip cannot be deleted.');
        }
        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'salary.slip_deleted', $slip, [
            'employee' => $slip->member?->user?->name,
            'month' => sprintf('%04d-%02d', $slip->year, $slip->month),
            'net' => (float) $slip->net_salary,
        ]);
        $this->unwind($slip);

        return response()->json(['message' => 'Slip removed.']);
    }

    /**
     * Remove a slip and everything it did: the loan money it recovered goes
     * back on the loans, and a loan it closed reopens. Deleting the slip
     * alone would leave the borrower marked as having repaid money that was
     * never actually held back.
     */
    private function unwind(SalarySlip $slip): void
    {
        $repayments = \App\Models\Crm\LoanRepayment::where('salary_slip_id', $slip->id)->get();
        foreach ($repayments as $repayment) {
            $loan = $repayment->loan;
            $repayment->delete();
            if ($loan && $loan->status === 'closed' && $loan->balance() > 0) {
                $loan->update(['status' => 'open']);
            }
        }
        $slip->delete();
    }

    private function serialize(SalarySlip $s, $punches): array
    {
        return [
            'uuid' => $s->uuid,
            'member' => $s->member ? ['uuid' => $s->member->uuid, 'name' => $s->member->user?->name, 'employee_code' => $s->member->employee_code] : null,
            'year' => $s->year,
            'month' => $s->month,
            'monthly_salary' => $s->monthly_salary,
            // How the payable figure was arrived at, so a slip explains itself.
            'month_days' => $s->month_days,
            'payable_days' => $s->payable_days,
            'lop_days' => $s->lop_days,
            'earnings' => $s->earnings ?? [],
            'deduction_lines' => $s->deduction_lines ?? [],
            'incentive_amount' => $s->incentive_amount,
            'incentive_breakdown' => $s->incentive_breakdown,
            'incentive_month' => $s->incentive_month,
            'net_without_incentive' => $s->net_without_incentive,
            'payable' => $s->payable,
            'additions' => $s->additions,
            'deductions' => $s->deductions,
            'deduction_note' => $s->deduction_note,
            'net_salary' => $s->net_salary,
            'bank_name' => $s->bank_name,
            'account_holder' => $s->account_holder,
            'account_no' => $s->account_no,
            'ifsc' => $s->ifsc,
            'status' => $s->status,
            'paid_on' => $s->paid_on?->toDateString(),
            'payment_mode' => $s->payment_mode,
            'attendance' => $punches->isEmpty() ? null : [
                'days' => $punches->count(),
                'present' => $punches->where('status', 'present')->count(),
                'late' => $punches->where('status', 'late')->count(),
                'half_day' => $punches->where('status', 'half_day')->count(),
                'holiday' => $punches->whereIn('status', ['holiday', 'sunday'])->count(),
            ],
        ];
    }
}
