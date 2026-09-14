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
use App\Support\Xlsx;

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
            'other_deductions' => round($slips->sum('other_deductions'), 2),
            'reimbursements' => round($slips->sum('reimbursements'), 2),
            'net' => round($slips->sum('net_salary'), 2),
            // What the payroll actually costs the company.
            'ctc' => round($slips->sum(fn (SalarySlip $s) => $this->ctc($s)), 2),
            'incentive' => round($slips->sum('incentive_amount'), 2),
            'net_without_incentive' => round($slips->sum(fn ($s) => (float) ($s->net_without_incentive ?? $s->net_salary)), 2),
            'paid' => round($slips->where('status', 'paid')->sum('net_salary'), 2),
            'pending' => round($slips->where('status', 'pending')->sum('net_salary'), 2),
        ], 'year' => $year, 'month' => $month, 'manages' => $manages, 'can_export' => $this->canExport($me)]);
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
                if ((float) $slip->additions != 0 || (float) $slip->other_deductions != 0
                    || $slip->addition_note || $slip->other_deduction_note) {
                    $manual[$slip->member_id] = $this->manualMoney($slip);
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

                $keep = $manual[$member->id] ?? $this->manualMoney(null);
                // Approved office-money claims still owed to them.
                $claims = $this->reimbursable($org->id, $member, $month);
                $reimb = round((float) $claims->sum('amount'), 2);

                $slip = SalarySlip::create([
                    'organization_id' => $org->id,
                    'member_id' => $member->id,
                    'year' => $data['year'],
                    'month' => $data['month'],
                    'additions' => $keep['additions'],
                    'addition_note' => $keep['addition_note'],
                    'other_deductions' => $keep['other_deductions'],
                    'other_deduction_note' => $keep['other_deduction_note'],
                    'reimbursements' => $reimb,
                    'reimbursement_lines' => $this->reimbursementLines($claims),
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
                    'net_salary' => round($calc['net_salary'] + $keep['additions'] - $keep['other_deductions'] + $reimb, 2),
                    'net_without_incentive' => round($calc['net_without_incentive'] + $keep['additions'] - $keep['other_deductions'] + $reimb, 2),
                    'bank_name' => $member->bank_name,
                    'account_holder' => $member->bank_account_name,
                    'account_no' => $member->bank_account_no,
                    'ifsc' => $member->bank_ifsc,
                    'created_by' => $request->user()->id,
                ]);

                // What the slip recovers is written into the loan's own book,
                // so the balance falls with the payroll and never twice.
                if ($claims->isNotEmpty()) {
                    \App\Models\Crm\Approval::whereIn('id', $claims->pluck('id'))
                        ->update(['reimbursed_slip_id' => $slip->id]);
                }

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
            'addition_note' => ['nullable', 'string', 'max:512'],
            // The statutory total - only for a slip with no computed lines;
            // hand-typed money held back goes in other_deductions.
            'deductions' => ['nullable', 'numeric', 'min:0'],
            'other_deductions' => ['nullable', 'numeric', 'min:0'],
            'other_deduction_note' => ['nullable', 'string', 'max:512'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_holder' => ['nullable', 'string', 'max:255'],
            'account_no' => ['nullable', 'string', 'max:64'],
            'ifsc' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', Rule::in(['pending', 'paid'])],
            'paid_on' => ['nullable', 'date'],
            'payment_mode' => ['nullable', 'string', 'max:64'],
        ]);

        $slip->fill(array_filter($data, fn ($v) => $v !== null));

        // A note sent empty is a note taken away, not one left as it was.
        foreach (['addition_note', 'other_deduction_note'] as $note) {
            if ($request->exists($note)) {
                $slip->{$note} = $data[$note] ?? null;
            }
        }
        if ($request->exists('other_deductions') && $data['other_deductions'] === null) {
            $slip->other_deductions = 0;
        }

        // Net is always arithmetic — and the incentive-free reading moves
        // with it, so the two figures never drift apart under manual edits.
        $slip->net_salary = round(
            (float) $slip->payable + (float) $slip->additions + (float) $slip->reimbursements
                - (float) $slip->deductions - (float) $slip->other_deductions,
            2,
        );
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
                'additions' => (float) $slip->additions ?: null,
                'addition_note' => $slip->addition_note,
                'other_deductions' => (float) $slip->other_deductions ?: null,
                'other_deduction_note' => $slip->other_deduction_note,
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
        $keep = $this->manualMoney($slip);

        $newSlip = DB::transaction(function () use ($org, $slip, $member, $year, $month, $request, $keep) {
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

            // The unwind above released this slip's claims; take them up again.
            $claims = $this->reimbursable($org->id, $member, $monthStart);
            $reimb = round((float) $claims->sum('amount'), 2);

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
                'additions' => $keep['additions'],
                'addition_note' => $keep['addition_note'],
                'other_deductions' => $keep['other_deductions'],
                'other_deduction_note' => $keep['other_deduction_note'],
                'reimbursements' => $reimb,
                'reimbursement_lines' => $this->reimbursementLines($claims),
                'deductions' => $calc['total_deductions'],
                'net_salary' => round($calc['net_salary'] + $keep['additions'] - $keep['other_deductions'] + $reimb, 2),
                'net_without_incentive' => round($calc['net_without_incentive'] + $keep['additions'] - $keep['other_deductions'] + $reimb, 2),
                'bank_name' => $member->bank_name,
                'account_holder' => $member->bank_account_name,
                'account_no' => $member->bank_account_no,
                'ifsc' => $member->bank_ifsc,
                'created_by' => $request->user()->id,
            ]);

            if ($claims->isNotEmpty()) {
                \App\Models\Crm\Approval::whereIn('id', $claims->pluck('id'))
                    ->update(['reimbursed_slip_id' => $fresh->id]);
            }

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
        // Claims this slip paid back are owed again until another slip pays them.
        \App\Models\Crm\Approval::where('reimbursed_slip_id', $slip->id)->update(['reimbursed_slip_id' => null]);
        $slip->delete();
    }

    /**
     * Cost to company: the net that reaches the bank plus everything held
     * back on the way - the statutory lines (both halves of PF, ESI, the
     * welfare fund, EDLI) and the other deductions. The same as the gross
     * payable with its additions and incentive.
     */
    private function ctc(SalarySlip $s): float
    {
        return round((float) $s->net_salary + (float) $s->deductions + (float) $s->other_deductions, 2);
    }

    /** The Admin, or somebody the Admin named with salary.export. */
    private function canExport(Member $me): bool
    {
        return $me->crm_role === 'admin'
            || in_array('salary.export', (array) ($me->capabilities ?? []), true);
    }

    /**
     * The salary register as Excel.
     *
     * Every slip of the month or the period - or one person's - on its own
     * row: each earning, then PF, ESI and the welfare fund with the
     * employee's share and the employer's apart, EDLI, professional tax, TDS,
     * loans, the other deductions with their notes, net, and CTC. A totals
     * row closes it.
     *
     * The slip keeps each scheme as one combined deduction and the employer's
     * share as an earning; the employee's share is the difference.
     */
    public function export(Request $request)
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        abort_unless(
            $this->canExport($me),
            403,
            'The salary register is the Admin’s, plus the people the Admin has named.',
        );

        $query = SalarySlip::with('member.user:id,name')->where('organization_id', $org->id);

        $from = $request->query('month_from');
        $to = $request->query('month_to');
        if ($from && $to) {
            abort_if($to < $from, 422, 'The last month cannot come before the first.');
            $code = fn (string $ym) => (int) str_replace('-', '', $ym);
            $query->whereRaw('(year * 100 + month) between ? and ?', [$code($from), $code($to)]);
            $period = \Carbon\Carbon::parse($from . '-01')->format('M Y') . ' to ' . \Carbon\Carbon::parse($to . '-01')->format('M Y');
            $fileRange = $from . '-to-' . $to;
        } else {
            $year = (int) $request->query('year', now()->year);
            $month = (int) $request->query('month', now()->month);
            abort_unless($month >= 1 && $month <= 12, 422, 'Month must be 1-12.');
            $query->where('year', $year)->where('month', $month);
            $period = \Carbon\Carbon::create($year, $month, 1)->format('F Y');
            $fileRange = sprintf('%04d-%02d', $year, $month);
        }

        $person = null;
        if ($uuid = $request->query('member')) {
            $query->whereHas('member', fn ($m) => $m->where('uuid', $uuid));
        }

        $slips = $query->orderBy('year')->orderBy('month')->orderBy('id')->get();
        abort_if($slips->isEmpty(), 422, 'There are no salary slips for that selection.');
        if ($request->query('member')) {
            $person = $slips->first()->member?->user?->name;
        }

        // The employer's statutory money sits in the earnings; it gets
        // columns of its own below, beside the employee's share.
        $statutory = ['employer_pf', 'edli', 'employer_esi', 'welfare_employer', 'incentive'];
        $components = [];
        foreach ($slips as $slip) {
            foreach ((array) ($slip->earnings ?? []) as $line) {
                if (! in_array($line['key'], $statutory, true) && ! isset($components[$line['key']])) {
                    $components[$line['key']] = $line['label'];
                }
            }
        }
        if ($slips->contains(fn (SalarySlip $s) => empty($s->earnings))) {
            $components['__payable'] = 'Payable (no breakdown)';
        }

        $header = [
            'Employee', 'Employee code', 'Salary month', 'Released in', 'Monthly gross', 'Days in month',
            'Payable days', 'Days without pay',
            ...array_values($components),
            'Incentive', 'Additions', 'Addition note', 'Reimbursements', 'Gross payable',
            'PF — employee', 'PF — employer', 'EDLI — employer', 'ESI — employee', 'ESI — employer',
            'Welfare fund — employee', 'Welfare fund — employer', 'Professional tax', 'TDS',
            'Loans & advances', 'Other statutory lines', 'Statutory deductions',
            'Other deductions', 'Other deduction note', 'Total deductions',
            'Net without incentive', 'Net salary', 'Employer contributions', 'CTC (cost to company)',
            'Status', 'Paid on', 'Payment mode', 'Bank', 'Account holder', 'Account no.', 'IFSC',
        ];
        $text = ['Employee', 'Employee code', 'Salary month', 'Released in', 'Addition note', 'Other deduction note',
            'Status', 'Paid on', 'Payment mode', 'Bank', 'Account holder', 'Account no.', 'IFSC'];
        $count = ['Days in month', 'Payable days', 'Days without pay'];

        $rows = [];
        $totals = array_fill(0, count($header), 0.0);

        foreach ($slips as $slip) {
            $earn = collect((array) ($slip->earnings ?? []))->groupBy('key')->map(fn ($g) => (float) $g->sum('amount'));
            $ded = collect((array) ($slip->deduction_lines ?? []))->groupBy('key')->map(fn ($g) => (float) $g->sum('amount'));
            $e = fn (string $k) => (float) ($earn[$k] ?? 0);
            $d = fn (string $k) => (float) ($ded[$k] ?? 0);

            $pfEr = $e('employer_pf');
            $esiEr = $e('employer_esi');
            $welfareEr = $e('welfare_employer');
            $edli = $d('edli') ?: $e('edli');
            $loans = (float) $ded->filter(fn ($v, $k) => str_starts_with((string) $k, 'loan_'))->sum();
            $otherStatutory = (float) $ded->reject(fn ($v, $k) => in_array($k, ['pf', 'edli', 'esi', 'welfare', 'pt', 'tds'], true)
                || str_starts_with((string) $k, 'loan_'))->sum();

            $month = \Carbon\Carbon::create($slip->year, $slip->month, 1);
            $net = (float) $slip->net_salary;
            $statutoryTotal = (float) $slip->deductions;
            $other = (float) $slip->other_deductions;

            $values = [
                $slip->member?->user?->name ?? $slip->account_holder,
                $slip->member?->employee_code,
                $month->format('M Y'),
                $month->copy()->addMonthNoOverflow()->format('M Y'),
                (float) $slip->monthly_salary,
                $slip->month_days,
                $slip->payable_days !== null ? (float) $slip->payable_days : null,
                (float) $slip->lop_days,
            ];
            foreach (array_keys($components) as $key) {
                $values[] = $key === '__payable' ? (empty($slip->earnings) ? (float) $slip->payable : 0.0) : $e($key);
            }
            array_push(
                $values,
                (float) $slip->incentive_amount,
                (float) $slip->additions,
                $slip->addition_note,
                (float) $slip->reimbursements,
                round((float) $slip->payable + (float) $slip->additions + (float) $slip->reimbursements, 2),
                max(0, round($d('pf') - $pfEr, 2)),
                $pfEr,
                $edli,
                max(0, round($d('esi') - $esiEr, 2)),
                $esiEr,
                max(0, round($d('welfare') - $welfareEr, 2)),
                $welfareEr,
                $d('pt'),
                $d('tds'),
                $loans,
                $otherStatutory,
                $statutoryTotal,
                $other,
                $slip->other_deduction_note,
                round($statutoryTotal + $other, 2),
                (float) ($slip->net_without_incentive ?? $slip->net_salary),
                $net,
                round($pfEr + $edli + $esiEr + $welfareEr, 2),
                $this->ctc($slip),
                $slip->status === 'paid' ? 'Paid' : 'Pending',
                $slip->paid_on?->format('d M Y'),
                $slip->payment_mode,
                $slip->bank_name,
                $slip->account_holder,
                $slip->account_no,
                $slip->ifsc,
            );

            $row = [];
            foreach ($values as $i => $value) {
                $name = $header[$i];
                if (in_array($name, $text, true)) {
                    $row[] = $value;
                } elseif (in_array($name, $count, true)) {
                    $row[] = $value;
                } else {
                    $row[] = Xlsx::money($value);
                    $totals[$i] += (float) $value;
                }
            }
            $rows[] = $row;
        }

        $totalRow = [];
        foreach ($header as $i => $name) {
            if ($i === 0) {
                $totalRow[] = Xlsx::cell('Total (' . $slips->count() . ' slip' . ($slips->count() === 1 ? '' : 's') . ')', Xlsx::BOLD);
            } elseif (in_array($name, $text, true) || in_array($name, $count, true) || $name === 'Monthly gross') {
                $totalRow[] = null;
            } else {
                $totalRow[] = Xlsx::money($totals[$i], true);
            }
        }

        $title = 'Salary register — ' . $org->name . ' — ' . $period . ($person ? ' — ' . $person : '');
        $sheet = [
            [Xlsx::cell($title, Xlsx::TITLE)],
            ['Generated ' . now()->format('d M Y H:i') . ' by ' . ($me->user?->name ?? 'Admin')
                . '. Amounts in the slip currency (INR). The employee share of each scheme is its deduction less the employer share.'],
            [],
            array_map(fn ($h) => Xlsx::cell($h, Xlsx::HEADER), $header),
            ...$rows,
            $totalRow,
        ];

        $widths = array_map(fn ($h) => match (true) {
            $h === 'Employee' => 24,
            str_contains($h, 'note') => 28,
            in_array($h, $count, true) => 11,
            default => max(13, min(26, mb_strlen($h) + 2)),
        }, $header);

        ActivityLog::record($me, $org->id, 'export.salary', $org, array_filter([
            'period' => $period,
            'employee' => $person,
            'slips' => $slips->count(),
        ]));

        $path = (new Xlsx())->sheet('Salary register', $sheet, $widths, 4)->toTempFile();
        $file = 'salary-register-' . ($person ? \Illuminate\Support\Str::slug($person) . '-' : '') . $fileRange . '.xlsx';

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, $file, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Approved office-money claims this person is still owed.
     *
     * General requests only (a recharge, a travel bill - not a price agreed
     * on an invoice), approved, with an amount, not yet paid by any slip, and
     * dated no later than the month being paid. A claim approved after last
     * month's run therefore rides in this month's.
     */
    private function reimbursable(int $orgId, Member $member, \Carbon\Carbon $month)
    {
        return \App\Models\Crm\Approval::where('organization_id', $orgId)
            ->where('requested_by', $member->id)
            ->where('scope', 'general')
            ->where('status', 'approved')
            ->where('amount', '>', 0)
            ->whereNull('reimbursed_slip_id')
            ->whereDate('approval_date', '<=', $month->copy()->endOfMonth()->toDateString())
            ->orderBy('approval_date')
            ->get();
    }

    private function reimbursementLines($claims): array
    {
        return $claims->map(fn ($a) => [
            'uuid' => $a->uuid,
            'type' => $a->type,
            'date' => $a->approval_date->toDateString(),
            'amount' => round((float) $a->amount, 2),
            'details' => $a->details ? \Illuminate\Support\Str::limit($a->details, 120) : null,
        ])->values()->all();
    }

    /**
     * The money an admin typed onto a slip, and why.
     *
     * Kept through a recalculation or a rebuild of the month: a bonus or a
     * canteen bill is a decision, not something the calendar knows.
     */
    private function manualMoney(?SalarySlip $slip): array
    {
        return [
            'additions' => (float) ($slip?->additions ?? 0),
            'addition_note' => $slip?->addition_note,
            'other_deductions' => (float) ($slip?->other_deductions ?? 0),
            'other_deduction_note' => $slip?->other_deduction_note,
        ];
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
            'addition_note' => $s->addition_note,
            'other_deductions' => $s->other_deductions,
            'other_deduction_note' => $s->other_deduction_note,
            'reimbursements' => $s->reimbursements,
            'reimbursement_lines' => $s->reimbursement_lines ?? [],
            'net_salary' => $s->net_salary,
            'ctc' => $this->ctc($s),
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
