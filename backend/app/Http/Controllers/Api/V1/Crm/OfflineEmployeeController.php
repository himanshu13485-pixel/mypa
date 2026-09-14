<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Models\Crm\OfflineEmployee;
use App\Models\Crm\OfflineSalary;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Offline Employees: people paid outside the payroll.
 *
 * An Employee ID, a name and a monthly amount; a record for each month that
 * is paid. None of it touches Salary - no slips, no statutory lines, no CTC.
 * The months add up in the P&L as Offline Salary, beside the real payroll.
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
            ->withMax('salaries as last_year_month', \Illuminate\Support\Facades\DB::raw('year * 100 + month'))
            ->where('organization_id', $org->id)
            ->when(trim((string) $request->query('search')), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")->orWhere('employee_code', 'like', "%{$s}%")))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
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
            ->filter(fn ($value, $key) => (string) ($before[$key] ?? '') !== (string) $value)
            ->map(fn ($value, $key) => ['from' => $before[$key] ?? null, 'to' => $value]);
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

    // ---- Months -------------------------------------------------------------

    public function salaries(Request $request): JsonResponse
    {
        $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $from = (string) $request->query('month_from', now()->format('Y-m'));
        $to = (string) $request->query('month_to', $from);
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}$/', $to), 422, 'Months are written YYYY-MM.');
        abort_if($to < $from, 422, 'The last month cannot come before the first.');
        $code = fn (string $ym) => (int) str_replace('-', '', $ym);

        $rows = OfflineSalary::with('employee:id,uuid,employee_code,name')
            ->where('organization_id', $org->id)
            ->whereRaw('(year * 100 + month) between ? and ?', [$code($from), $code($to)])
            ->when(trim((string) $request->query('search')), fn ($q, $s) => $q->whereHas('employee', fn ($e) => $e
                ->where('name', 'like', "%{$s}%")->orWhere('employee_code', 'like', "%{$s}%")))
            ->when($request->query('employee'), fn ($q, $uuid) => $q->whereHas('employee', fn ($e) => $e->where('uuid', $uuid)))
            ->get()
            ->sortBy([
                fn ($a, $b) => ($b->year * 100 + $b->month) <=> ($a->year * 100 + $a->month),
                fn ($a, $b) => strcasecmp((string) $a->employee?->name, (string) $b->employee?->name),
            ])
            ->values();

        return response()->json([
            'data' => $rows->map(fn (OfflineSalary $s) => $this->record($s))->values(),
            'totals' => [
                'count' => $rows->count(),
                'amount' => round((float) $rows->sum('amount'), 2),
                'by_month' => $rows->groupBy(fn ($s) => sprintf('%04d-%02d', $s->year, $s->month))
                    ->map(fn ($g, $month) => ['month' => $month, 'amount' => round((float) $g->sum('amount'), 2), 'count' => $g->count()])
                    ->sortKeys()->values(),
            ],
        ]);
    }

    /**
     * A month's records for everybody active, at their monthly amount.
     *
     * Somebody who already has that month is left as they are, so running it
     * twice - or after adding one person by hand - creates only what is
     * missing.
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
            $record = OfflineSalary::firstOrCreate(
                ['offline_employee_id' => $person->id, 'year' => $data['year'], 'month' => $data['month']],
                ['organization_id' => $org->id, 'amount' => $person->monthly_amount, 'created_by' => $request->user()->id],
            );
            if ($record->wasRecentlyCreated) {
                $created++;
            }
        }

        $label = Carbon::create($data['year'], $data['month'], 1)->format('F Y');
        ActivityLog::record($me, $org->id, 'offline_salary.generated', $org, ['month' => $label, 'created' => $created]);

        return response()->json([
            'message' => $created
                ? $created . ' record' . ($created === 1 ? '' : 's') . ' created for ' . $label . '.'
                : 'Everybody active already has a record for ' . $label . '.',
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
            'amount' => ['required', 'numeric', 'min:0'],
            'paid_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $person = $this->findPerson($org->id, $data['offline_employee_uuid']);
        $label = Carbon::create($data['year'], $data['month'], 1)->format('F Y');

        abort_if(
            OfflineSalary::where('offline_employee_id', $person->id)->where('year', $data['year'])->where('month', $data['month'])->exists(),
            422,
            $person->name . ' already has a record for ' . $label . '. Edit that one instead.',
        );

        $record = OfflineSalary::create([
            'organization_id' => $org->id,
            'offline_employee_id' => $person->id,
            'year' => $data['year'],
            'month' => $data['month'],
            'amount' => $data['amount'],
            'paid_on' => $data['paid_on'] ?? null,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record($me, $org->id, 'offline_salary.created', $record, [
            'name' => $person->name, 'month' => $label, 'amount' => (float) $record->amount,
        ]);

        return response()->json(['message' => 'Record added for ' . $person->name . ', ' . $label . '.', 'data' => $this->record($record->load('employee'))], 201);
    }

    public function updateSalary(Request $request, string $uuid): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');
        $record = OfflineSalary::with('employee')->where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'paid_on' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $before = (float) $record->amount;
        $record->update($data);

        ActivityLog::record($me, $org->id, 'offline_salary.updated', $record, array_filter([
            'name' => $record->employee?->name,
            'month' => Carbon::create($record->year, $record->month, 1)->format('F Y'),
            'amount' => $before !== (float) $record->amount ? ['from' => $before, 'to' => (float) $record->amount] : null,
        ]));

        return response()->json(['message' => 'Saved.', 'data' => $this->record($record->fresh('employee'))]);
    }

    public function destroySalary(Request $request, string $uuid): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');
        $record = OfflineSalary::with('employee')->where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail();
        $label = Carbon::create($record->year, $record->month, 1)->format('F Y');

        ActivityLog::record($me, $org->id, 'offline_salary.deleted', $org, [
            'name' => $record->employee?->name, 'month' => $label, 'amount' => (float) $record->amount,
        ]);

        $record->delete();

        return response()->json(['message' => 'Record deleted.']);
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

    private function validatePerson(Request $request, int $orgId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'employee_code' => [
                'required', 'string', 'max:64',
                Rule::unique('crm_offline_employees', 'employee_code')->where('organization_id', $orgId)->ignore($ignoreId),
            ],
            'name' => ['required', 'string', 'max:191'],
            'monthly_amount' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'employee_code.unique' => 'Another offline employee already has that Employee ID.',
        ]) + ['status' => $request->input('status') ?: 'active'];
    }

    private function person(OfflineEmployee $e): array
    {
        $last = $e->last_year_month ?? null;

        return [
            'uuid' => $e->uuid,
            'employee_code' => $e->employee_code,
            'name' => $e->name,
            'monthly_amount' => (float) $e->monthly_amount,
            'status' => $e->status,
            'note' => $e->note,
            'records' => (int) ($e->salaries_count ?? 0),
            'last_month' => $last ? sprintf('%04d-%02d', intdiv((int) $last, 100), (int) $last % 100) : null,
        ];
    }

    private function record(OfflineSalary $s): array
    {
        return [
            'uuid' => $s->uuid,
            'year' => $s->year,
            'month' => $s->month,
            'amount' => (float) $s->amount,
            'paid_on' => $s->paid_on?->toDateString(),
            'note' => $s->note,
            'employee' => $s->employee ? [
                'uuid' => $s->employee->uuid,
                'employee_code' => $s->employee->employee_code,
                'name' => $s->employee->name,
            ] : null,
        ];
    }
}
