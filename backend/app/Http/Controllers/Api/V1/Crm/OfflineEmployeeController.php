<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\OfflineEmployee;
use App\Models\Crm\OfflineSalary;
use App\Support\PayrollNotes;
use App\Support\QueryList;
use App\Support\Xlsx;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Offline Employees: people paid outside the payroll.
 *
 * Each has a salary structure and a slip a month, the way somebody on the
 * rolls does - prorated by the days paid, with additions and other
 * deductions, a PDF payslip and an Excel register. None of it touches
 * Salary: the nets add up in the P&L as Offline Salary, beside the payroll.
 *
 * The Company Admin's alone.
 */
class OfflineEmployeeController extends Controller
{
    // ---- People -------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $people = OfflineEmployee::withCount('salaries')
            ->withMax('salaries as last_year_month', DB::raw('year * 100 + month'))
            ->where('organization_id', $org->id)
            ->when(trim((string) $request->query('search')), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")->orWhere('employee_code', 'like', "%{$s}%")))
            ->when(QueryList::of($request, 'status'), fn ($q, $statuses) => $q->whereIn('status', $statuses))
            ->orderByRaw("case status when 'active' then 0 else 1 end")
            ->orderBy('name')
            ->get();

        $active = $people->where('status', 'active');

        return response()->json([
            'data' => $people->map(fn (OfflineEmployee $e) => $this->person($e))->values(),
            'totals' => [
                'active' => $active->count(),
                'monthly' => round((float) $active->sum('monthly_amount'), 2),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $person = OfflineEmployee::create($this->validatePerson($request, $org->id) + [
            'organization_id' => $org->id,
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record($me, $org->id, 'offline_employee.created', $person, [
            'employee_code' => $person->employee_code,
            'name' => $person->name,
            'monthly_amount' => (float) $person->monthly_amount,
        ]);

        return response()->json(['message' => $person->name . ' added.', 'data' => $this->person($person)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');
        $person = $this->findPerson($org->id, $uuid);

        $data = $this->validatePerson($request, $org->id, $person->id);
        $before = $person->only(array_keys($data));
        $person->update($data);

        $changed = collect($data)
            ->filter(fn ($value, $key) => json_encode($before[$key] instanceof \DateTimeInterface ? $before[$key]->format('Y-m-d') : ($before[$key] ?? null)) !== json_encode($value))
            ->map(fn ($value, $key) => $key === 'structure' ? 'changed' : ['from' => $before[$key] ?? null, 'to' => $value]);
        if ($changed->isNotEmpty()) {
            ActivityLog::record($me, $org->id, 'offline_employee.updated', $person, ['fields' => $changed->all()]);
        }

        return response()->json(['message' => 'Saved.', 'data' => $this->person($person->fresh())]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');
        $person = $this->findPerson($org->id, $uuid);
        $records = $person->salaries()->count();

        ActivityLog::record($me, $org->id, 'offline_employee.deleted', $org, array_filter([
            'employee_code' => $person->employee_code,
            'name' => $person->name,
            'monthly_records_deleted' => $records ?: null,
        ]));

        $person->delete();

        return response()->json([
            'message' => $person->name . ' deleted'
                . ($records ? ', with ' . $records . ' monthly record' . ($records === 1 ? '' : 's') : '') . '.',
        ]);
    }

    // ---- Monthly slips ----------------------------------------------------------

    public function salaries(Request $request): JsonResponse
    {
        $this->admin($request);
        $org = $request->attributes->get('crm_org');
        $rows = $this->records($request);

        /*
         * The month being read, which this register names as YYYY-MM rather
         * than as a year and a month.
         *
         * Asked for by name rather than taken from the rows, because a month
         * with no slips in it yet can still have been written about - "nobody
         * was paid in August, the run moved to September" is exactly the kind
         * of thing worth writing down, and reading it off the rows would have
         * meant it could never be seen again.
         */
        $from = (string) $request->query('month_from', now()->format('Y-m'));
        $to = (string) $request->query('month_to', $from);
        [$year, $month] = array_map('intval', explode('-', $from));

        // The same remarks the payroll keeps, for the people paid outside it:
        // why this amount, which account it went from, what was odd about the
        // month. Kept against the person and the month, not the slip.
        $notes = PayrollNotes::forPeriods(
            $org->id,
            PayrollNotes::OFFLINE,
            $rows->map(fn (OfflineSalary $s) => [$s->year, $s->month])->push([$year, $month]),
        );

        return response()->json([
            'data' => $rows->map(function (OfflineSalary $s) use ($notes) {
                $row = $this->record($s);
                $row['notes'] = $notes->get(PayrollNotes::key($s->year, $s->month, $s->offline_employee_id), []);

                return $row;
            })->values(),
            'totals' => $this->totals($rows),
            // What belongs to the month rather than to any one person. Only
            // for a single month: over a span there is no one month for a
            // remark to be about, and showing one would name the wrong one.
            'notes' => $from === $to ? $notes->get(PayrollNotes::key($year, $month, null), []) : [],
        ]);
    }

    /**
     * A remark against one person's month, several at once, or the month.
     *
     * Three shapes through one door because they are one act, and because
     * the useful one in bulk - "paid from the ICICI account" - is true of
     * some of a list and false of the rest.
     */
    public function storeNote(Request $request): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'year' => ['required_without:uuids', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required_without:uuids', 'integer', 'min:1', 'max:12'],
            'employee_uuid' => ['nullable', 'string'],
            // A selection of slips, each remarked against its own month.
            'uuids' => ['nullable', 'array', 'max:200'],
            'uuids.*' => ['string'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        if (! empty($data['uuids'])) {
            $slips = OfflineSalary::where('organization_id', $org->id)->whereIn('uuid', $data['uuids'])->get();
            abort_if($slips->isEmpty(), 422, 'Nothing in that selection.');

            $count = PayrollNotes::addMany(
                $org->id, PayrollNotes::OFFLINE,
                $slips->map(fn (OfflineSalary $s) => [$s->year, $s->month, $s->offline_employee_id]),
                $data['body'], $request->user()->id,
            );
            ActivityLog::record($me, $org->id, 'offline_salary.notes_added', $org, ['count' => $count]);

            return response()->json([
                'message' => 'Noted against ' . $count . ' ' . ($count === 1 ? 'salary' : 'salaries') . '.',
            ], 201);
        }

        $employee = ! empty($data['employee_uuid'])
            ? OfflineEmployee::where('organization_id', $org->id)->where('uuid', $data['employee_uuid'])->firstOrFail()
            : null;

        $id = PayrollNotes::add(
            $org->id, PayrollNotes::OFFLINE,
            $data['year'], $data['month'], $employee?->id,
            $data['body'], $request->user()->id,
        );
        ActivityLog::record($me, $org->id, 'offline_salary.note_added', $employee ?? $org, [
            'period' => sprintf('%04d-%02d', $data['year'], $data['month']),
        ]);

        return response()->json(['message' => 'Noted.', 'data' => ['id' => $id]], 201);
    }

    public function deleteNote(Request $request, int $id): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $note = PayrollNotes::find($org->id, $id, PayrollNotes::OFFLINE);
        abort_unless($note, 404, 'No such note.');

        PayrollNotes::remove($id);
        ActivityLog::record($me, $org->id, 'offline_salary.note_removed', $org, [
            'period' => sprintf('%04d-%02d', $note->year, $note->month),
        ]);

        return response()->json(['message' => 'Note removed.']);
    }

    /**
     * A month's slips for everybody active, from their structure.
     *
     * Somebody who already has that month is left as they are, so running it
     * twice - or after adding one person by hand - creates only what is missing.
     */
    public function generate(Request $request): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $created = 0;
        foreach (OfflineEmployee::where('organization_id', $org->id)->where('status', 'active')->get() as $person) {
            $record = OfflineSalary::firstOrNew([
                'offline_employee_id' => $person->id, 'year' => $data['year'], 'month' => $data['month'],
            ]);
            if ($record->exists) {
                continue;
            }

            $record->fill([
                'organization_id' => $org->id,
                'structure' => $this->snapshot($person),
                'lop_days' => 0,
                'additions' => 0,
                'other_deductions' => 0,
                'status' => 'pending',
                'created_by' => $request->user()->id,
            ]);
            $record->rebuild();
            $record->save();
            $created++;
        }

        $label = Carbon::create($data['year'], $data['month'], 1)->format('F Y');
        ActivityLog::record($me, $org->id, 'offline_salary.generated', $org, ['month' => $label, 'created' => $created]);

        return response()->json([
            'message' => $created
                ? $created . ' slip' . ($created === 1 ? '' : 's') . ' created for ' . $label . '.'
                : 'Everybody active already has a slip for ' . $label . '.',
            'created' => $created,
        ]);
    }

    public function storeSalary(Request $request): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'offline_employee_uuid' => ['required', 'string'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            // A plain amount instead of the structure, for a one-off month.
            'amount' => ['nullable', 'numeric', 'min:0'],
        ] + $this->slipRules());

        $person = $this->findPerson($org->id, $data['offline_employee_uuid']);
        $label = Carbon::create($data['year'], $data['month'], 1)->format('F Y');

        abort_if(
            OfflineSalary::where('offline_employee_id', $person->id)->where('year', $data['year'])->where('month', $data['month'])->exists(),
            422,
            $person->name . ' already has a slip for ' . $label . '. Edit that one instead.',
        );

        $record = new OfflineSalary([
            'organization_id' => $org->id,
            'offline_employee_id' => $person->id,
            'year' => $data['year'],
            'month' => $data['month'],
            'structure' => isset($data['amount']) ? $this->plainAmount((float) $data['amount']) : $this->snapshot($person),
            'created_by' => $request->user()->id,
        ]);
        $this->applySlipFields($record, $data);
        $record->rebuild();
        $record->save();

        ActivityLog::record($me, $org->id, 'offline_salary.created', $record, [
            'name' => $person->name, 'month' => $label, 'net' => (float) $record->amount,
        ]);

        return response()->json(['message' => 'Slip added for ' . $person->name . ', ' . $label . '.', 'data' => $this->record($record->load('employee'))], 201);
    }

    public function updateSalary(Request $request, string $uuid): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');
        $record = $this->findRecord($org->id, $uuid);

        $data = $request->validate([
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
        ] + $this->slipRules());

        $before = (float) $record->amount;
        if (array_key_exists('amount', $data)) {
            $record->structure = $this->plainAmount((float) $data['amount']);
        }
        $this->applySlipFields($record, $data);
        $record->rebuild();
        $record->save();

        ActivityLog::record($me, $org->id, 'offline_salary.updated', $record, array_filter([
            'name' => $record->employee?->name,
            'month' => Carbon::create($record->year, $record->month, 1)->format('F Y'),
            'net' => $before !== (float) $record->amount ? ['from' => $before, 'to' => (float) $record->amount] : null,
            'status' => $data['status'] ?? null,
        ]));

        return response()->json(['message' => 'Saved.', 'data' => $this->record($record->fresh('employee'))]);
    }

    /** Pay several slips in one act - the payout run itself. */
    public function markPaid(Request $request): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:500'],
            'uuids.*' => ['string'],
            'paid_on' => ['nullable', 'date'],
            'payment_mode' => ['nullable', 'string', 'max:64'],
        ]);

        $rows = OfflineSalary::with('employee')
            ->where('organization_id', $org->id)
            ->whereIn('uuid', $data['uuids'])
            ->where('status', '!=', 'paid')
            ->get();
        abort_if($rows->isEmpty(), 422, 'Nothing pending in that selection.');

        $paidOn = $data['paid_on'] ?? now()->toDateString();
        foreach ($rows as $row) {
            $row->update([
                'status' => 'paid',
                'paid_on' => $paidOn,
                'payment_mode' => $data['payment_mode'] ?? $row->payment_mode,
            ]);
        }

        $total = round((float) $rows->sum('amount'), 2);
        ActivityLog::record($me, $org->id, 'offline_salary.bulk_paid', $org, [
            'count' => $rows->count(),
            'total' => $total,
            'names' => $rows->map(fn ($r) => $r->employee?->name)->filter()->implode(', '),
            'paid_on' => $paidOn,
        ]);

        return response()->json([
            'message' => $rows->count() . ' slip' . ($rows->count() === 1 ? '' : 's') . ' marked paid — ' . number_format($total, 2) . ' in all.',
        ]);
    }

    public function destroySalary(Request $request, string $uuid): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');
        $record = $this->findRecord($org->id, $uuid);
        $label = Carbon::create($record->year, $record->month, 1)->format('F Y');

        ActivityLog::record($me, $org->id, 'offline_salary.deleted', $org, [
            'name' => $record->employee?->name, 'month' => $label, 'net' => (float) $record->amount,
        ]);

        $record->delete();

        return response()->json(['message' => 'Slip deleted.']);
    }

    /** The payslip as a PDF, laid out like an on-roll one. */
    public function pdf(Request $request, string $uuid)
    {
        $this->admin($request);
        $org = $request->attributes->get('crm_org');
        $record = $this->findRecord($org->id, $uuid);

        $paying = IssuingCompany::where('organization_id', $org->id)->where('pays_salary', true)->first();
        $logo = $paying?->logo_path ? Storage::disk('public')->path($paying->logo_path) : null;
        $month = Carbon::create($record->year, $record->month, 1);

        return Pdf::loadView('crm.offline-payslip', [
            'record' => $record,
            'person' => $record->employee,
            'earnings' => $this->earnings($record),
            'org' => $org,
            'company' => $paying,
            'logoPath' => $logo && is_file($logo) ? $logo : null,
            'monthName' => $month->format('F Y'),
        ])->download('payslip-' . Str::slug($record->employee?->name ?? 'offline-employee') . '-' . $month->format('Y-m') . '.pdf');
    }

    /** The register as Excel: every slip in the filters, line by line, with totals. */
    public function export(Request $request)
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');
        $rows = $this->records($request);
        abort_if($rows->isEmpty(), 422, 'There are no slips for that selection.');

        $earningLabels = $rows->flatMap(fn ($s) => collect($this->earnings($s))->pluck('label'))->unique()->values()->all();
        $deductionLabels = $rows->flatMap(fn ($s) => collect($s->deduction_lines ?? [])->pluck('label'))->unique()->values()->all();

        $header = [
            'Employee ID', 'Name', 'Designation', 'Month', 'Days in month', 'Payable days', 'Days without pay',
            ...$earningLabels,
            'Gross', 'Additions', 'Addition note',
            ...$deductionLabels,
            'Other deductions', 'Other deduction note', 'Total deductions', 'Net salary',
            'Status', 'Paid on', 'Payment mode', 'Bank', 'Account no.', 'IFSC',
        ];
        $text = ['Employee ID', 'Name', 'Designation', 'Month', 'Addition note', 'Other deduction note',
            'Status', 'Paid on', 'Payment mode', 'Bank', 'Account no.', 'IFSC'];
        $count = ['Days in month', 'Payable days', 'Days without pay'];

        $totals = array_fill(0, count($header), 0.0);
        $body = [];
        foreach ($rows as $s) {
            $earned = collect($this->earnings($s))->groupBy('label')->map(fn ($g) => (float) $g->sum('amount'));
            $held = collect($s->deduction_lines ?? [])->groupBy('label')->map(fn ($g) => (float) $g->sum('amount'));

            $values = [
                $s->employee?->employee_code, $s->employee?->name, $s->employee?->designation,
                Carbon::create($s->year, $s->month, 1)->format('M Y'),
                (int) $s->month_days, (float) $s->payable_days, (float) $s->lop_days,
            ];
            foreach ($earningLabels as $label) {
                $values[] = (float) ($earned[$label] ?? 0);
            }
            array_push($values, (float) $s->gross, (float) $s->additions, $s->addition_note);
            foreach ($deductionLabels as $label) {
                $values[] = (float) ($held[$label] ?? 0);
            }
            array_push(
                $values,
                (float) $s->other_deductions, $s->other_deduction_note,
                round((float) $s->deductions + (float) $s->other_deductions, 2),
                (float) $s->amount,
                $s->status === 'paid' ? 'Paid' : 'Pending',
                $s->paid_on?->format('d M Y'), $s->payment_mode,
                $s->employee?->bank_name, $s->employee?->account_no, $s->employee?->ifsc,
            );

            $row = [];
            foreach ($values as $i => $value) {
                if (in_array($header[$i], $text, true) || in_array($header[$i], $count, true)) {
                    $row[] = $value;
                } else {
                    $row[] = Xlsx::money($value);
                    $totals[$i] += (float) $value;
                }
            }
            $body[] = $row;
        }

        $totalRow = [];
        foreach ($header as $i => $name) {
            $totalRow[] = match (true) {
                $i === 0 => Xlsx::cell('Total (' . $rows->count() . ')', Xlsx::BOLD),
                in_array($name, $text, true) || in_array($name, $count, true) => null,
                default => Xlsx::money($totals[$i], true),
            };
        }

        $from = (string) $request->query('month_from', now()->format('Y-m'));
        $to = (string) $request->query('month_to', $from);
        $period = $from === $to ? Carbon::parse($from . '-01')->format('F Y')
            : Carbon::parse($from . '-01')->format('M Y') . ' to ' . Carbon::parse($to . '-01')->format('M Y');

        ActivityLog::record($me, $org->id, 'export.offline_salaries', $org, ['period' => $period, 'slips' => $rows->count()]);

        $path = (new Xlsx())->sheet('Offline salaries', [
            [Xlsx::cell('Offline salary register — ' . $org->name . ' — ' . $period, Xlsx::TITLE)],
            ['Generated ' . now()->format('d M Y H:i') . '. Paid outside the payroll; counted in the P&L as Offline Salary.'],
            [],
            array_map(fn ($h) => Xlsx::cell($h, Xlsx::HEADER), $header),
            ...$body,
            $totalRow,
        ], array_map(fn ($h) => $h === 'Name' ? 24 : (str_contains($h, 'note') ? 26 : max(12, min(24, mb_strlen($h) + 2))), $header), 4)->toTempFile();

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, 'offline-salaries-' . ($from === $to ? $from : $from . '-to-' . $to) . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // ---- Helpers ------------------------------------------------------------

    private function admin(Request $request): Member
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless($me->crm_role === 'admin', 403, 'Offline Employees is the Company Admin’s alone.');

        return $me;
    }

    private function findPerson(int $orgId, string $uuid): OfflineEmployee
    {
        return OfflineEmployee::where('organization_id', $orgId)->where('uuid', $uuid)->firstOrFail();
    }

    private function findRecord(int $orgId, string $uuid): OfflineSalary
    {
        return OfflineSalary::with('employee')->where('organization_id', $orgId)->where('uuid', $uuid)->firstOrFail();
    }

    /** The slips in the screen's filters: a month range, a name, people, paid or not. */
    private function records(Request $request): Collection
    {
        $org = $request->attributes->get('crm_org');

        $from = (string) $request->query('month_from', now()->format('Y-m'));
        $to = (string) $request->query('month_to', $from);
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}$/', $to), 422, 'Months are written YYYY-MM.');
        abort_if($to < $from, 422, 'The last month cannot come before the first.');
        $code = fn (string $ym) => (int) str_replace('-', '', $ym);

        return OfflineSalary::with('employee')
            ->where('organization_id', $org->id)
            ->whereRaw('(year * 100 + month) between ? and ?', [$code($from), $code($to)])
            ->when(trim((string) $request->query('search')), fn ($q, $s) => $q->whereHas('employee', fn ($e) => $e
                ->where('name', 'like', "%{$s}%")->orWhere('employee_code', 'like', "%{$s}%")))
            ->when(QueryList::of($request, 'employee'), fn ($q, $uuids) => $q->whereHas('employee', fn ($e) => $e->whereIn('uuid', $uuids)))
            ->when(QueryList::of($request, 'status'), fn ($q, $statuses) => $q->whereIn('status', $statuses))
            ->get()
            ->sortBy([
                fn ($a, $b) => ($b->year * 100 + $b->month) <=> ($a->year * 100 + $a->month),
                fn ($a, $b) => strcasecmp((string) $a->employee?->name, (string) $b->employee?->name),
            ])
            ->values();
    }

    private function totals(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'gross' => round((float) $rows->sum('gross'), 2),
            'additions' => round((float) $rows->sum('additions'), 2),
            'deductions' => round((float) $rows->sum(fn ($s) => (float) $s->deductions + (float) $s->other_deductions), 2),
            // `amount` is the net - the name the P&L and older screens read.
            'amount' => round((float) $rows->sum('amount'), 2),
            'paid' => round((float) $rows->where('status', 'paid')->sum('amount'), 2),
            'pending' => round((float) $rows->where('status', '!=', 'paid')->sum('amount'), 2),
            'by_month' => $rows->groupBy(fn ($s) => sprintf('%04d-%02d', $s->year, $s->month))
                ->map(fn ($g, $month) => ['month' => $month, 'amount' => round((float) $g->sum('amount'), 2), 'count' => $g->count()])
                ->sortKeys()->values(),
        ];
    }

    private function slipRules(): array
    {
        return [
            'lop_days' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:31'],
            'additions' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'addition_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'other_deductions' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'other_deduction_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'nullable', Rule::in(['pending', 'paid'])],
            'paid_on' => ['sometimes', 'nullable', 'date'],
            'payment_mode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** Only the fields sent are touched; an emptied note is taken away. */
    private function applySlipFields(OfflineSalary $record, array $data): void
    {
        foreach (['lop_days', 'additions', 'other_deductions'] as $key) {
            if (array_key_exists($key, $data) || ! $record->exists) {
                $record->{$key} = (float) ($data[$key] ?? 0);
            }
        }
        foreach (['addition_note', 'other_deduction_note', 'payment_mode', 'note', 'paid_on'] as $key) {
            if (array_key_exists($key, $data)) {
                $record->{$key} = ($data[$key] ?? '') === '' ? null : $data[$key];
            }
        }
        if (! empty($data['status'])) {
            $record->status = $data['status'];
        } elseif (! $record->exists) {
            $record->status = 'pending';
        }
        if ($record->status === 'paid' && ! $record->paid_on) {
            $record->paid_on = now()->toDateString();
        }
    }

    private function snapshot(OfflineEmployee $person): array
    {
        return ['earnings' => $person->earningLines(), 'deductions' => $person->deductionLines()];
    }

    private function plainAmount(float $amount): array
    {
        return ['earnings' => [['key' => 'salary', 'label' => 'Monthly salary', 'amount' => round($amount, 2)]], 'deductions' => []];
    }

    /** A slip's earnings lines, falling back to its net for one made before structures. */
    private function earnings(OfflineSalary $s): array
    {
        return $s->earnings ?: [['key' => 'salary', 'label' => 'Monthly salary', 'amount' => (float) $s->amount]];
    }

    private function validatePerson(Request $request, int $orgId, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'employee_code' => [
                'required', 'string', 'max:64',
                Rule::unique('crm_offline_employees', 'employee_code')->where('organization_id', $orgId)->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:191'],
            'designation' => ['nullable', 'string', 'max:128'],
            'joined_on' => ['nullable', 'date'],
            // Without a structure, one monthly amount is the whole salary.
            'monthly_amount' => ['nullable', 'numeric', 'min:0'],
            'structure' => ['nullable', 'array'],
            'structure.earnings' => ['nullable', 'array', 'max:20'],
            'structure.earnings.*.label' => ['required', 'string', 'max:64'],
            'structure.earnings.*.amount' => ['required', 'numeric', 'min:0'],
            'structure.deductions' => ['nullable', 'array', 'max:20'],
            'structure.deductions.*.label' => ['required', 'string', 'max:64'],
            'structure.deductions.*.amount' => ['required', 'numeric', 'min:0'],
            'bank_name' => ['nullable', 'string', 'max:128'],
            'account_holder' => ['nullable', 'string', 'max:128'],
            'account_no' => ['nullable', 'string', 'max:64'],
            'ifsc' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'employee_code.unique' => 'Another offline employee already has that Employee ID.',
        ]);

        $clean = fn ($lines) => collect($lines ?? [])
            ->map(fn ($l) => [
                'key' => Str::slug((string) $l['label'], '_') ?: 'line',
                'label' => trim((string) $l['label']),
                'amount' => round((float) $l['amount'], 2),
            ])
            ->filter(fn ($l) => $l['label'] !== '' && $l['amount'] > 0)
            ->values()->all();

        $earnings = $clean($data['structure']['earnings'] ?? []);
        $deductions = $clean($data['structure']['deductions'] ?? []);

        if ($earnings !== []) {
            // The structure is the salary; the monthly amount is its gross.
            $data['monthly_amount'] = round(collect($earnings)->sum('amount'), 2);
            $data['structure'] = ['earnings' => $earnings, 'deductions' => $deductions];
        } else {
            abort_if(! isset($data['monthly_amount']), 422, 'Enter a salary structure, or a monthly amount.');
            $data['structure'] = $deductions !== [] ? ['earnings' => [], 'deductions' => $deductions] : null;
        }

        $data['status'] = $request->input('status') ?: 'active';

        return $data;
    }

    private function person(OfflineEmployee $e): array
    {
        $last = $e->last_year_month ?? null;
        $deductions = round(collect($e->deductionLines())->sum('amount'), 2);

        return [
            'uuid' => $e->uuid,
            'employee_code' => $e->employee_code,
            'name' => $e->name,
            'designation' => $e->designation,
            'joined_on' => $e->joined_on?->toDateString(),
            'monthly_amount' => (float) $e->monthly_amount,
            'monthly_deductions' => $deductions,
            'monthly_net' => round((float) $e->monthly_amount - $deductions, 2),
            'structure' => [
                'earnings' => $e->structure['earnings'] ?? [],
                'deductions' => $e->structure['deductions'] ?? [],
            ],
            'bank_name' => $e->bank_name,
            'account_holder' => $e->account_holder,
            'account_no' => $e->account_no,
            'ifsc' => $e->ifsc,
            'status' => $e->status,
            'note' => $e->note,
            'records' => (int) ($e->salaries_count ?? 0),
            'last_month' => $last ? sprintf('%04d-%02d', intdiv((int) $last, 100), (int) $last % 100) : null,
        ];
    }

    private function record(OfflineSalary $s): array
    {
        $days = (int) ($s->month_days ?: Carbon::create($s->year, $s->month, 1)->daysInMonth);

        return [
            'uuid' => $s->uuid,
            'year' => $s->year,
            'month' => $s->month,
            'month_days' => $days,
            'payable_days' => (float) ($s->payable_days ?? $days),
            'lop_days' => (float) $s->lop_days,
            'earnings' => $this->earnings($s),
            'deduction_lines' => $s->deduction_lines ?? [],
            'gross' => (float) ($s->gross ?: $s->amount),
            'additions' => (float) $s->additions,
            'addition_note' => $s->addition_note,
            'deductions' => (float) $s->deductions,
            'other_deductions' => (float) $s->other_deductions,
            'other_deduction_note' => $s->other_deduction_note,
            'net' => (float) $s->amount,
            'amount' => (float) $s->amount,
            'status' => $s->status ?: 'pending',
            'paid_on' => $s->paid_on?->toDateString(),
            'payment_mode' => $s->payment_mode,
            'note' => $s->note,
            'employee' => $s->employee ? [
                'uuid' => $s->employee->uuid,
                'employee_code' => $s->employee->employee_code,
                'name' => $s->employee->name,
                'designation' => $s->employee->designation,
            ] : null,
        ];
    }
}
