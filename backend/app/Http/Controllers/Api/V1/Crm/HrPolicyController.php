<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Holiday;
use App\Models\Crm\LeaveLedger;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Services\Crm\LeaveAccount;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HR Policy — the house rules, in one place, kept by the Admin.
 *
 * Everything that used to be a number buried in a controller lives here:
 * when the day starts, when arriving becomes Late and when Late becomes a
 * Half day, which days are weekly offs, how long probation runs, and how
 * paid leave is earned and paid back. One knob moves everybody, Subadmins
 * included — that is what makes it a policy rather than a preference.
 *
 * Everyone may read it. Only the Admin may change it: rules people are
 * measured against should be visible to the people being measured.
 */
class HrPolicyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $policy = $org->hrPolicy();
        $account = new LeaveAccount($org);
        $year = $account->financialYear();

        return response()->json(['data' => [
            'policy' => $policy,
            'defaults' => Organization::HR_DEFAULTS,
            'financial_year' => $year,
            'financial_year_label' => $year . '–' . substr((string) ($year + 1), 2),
            'can_edit' => $me->crm_role === 'admin'
                || ($me->crm_role === 'subadmin'
                    && in_array('hr.policy_edit', (array) ($me->capabilities ?? []), true)),
            'can_manage_holidays' => in_array($me->crm_role, ['admin', 'subadmin'], true),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        // The Admin sets policy; a Subadmin only when the Admin has granted
        // it by name — the grant is the capability, not the job.
        abort_unless(
            $me->crm_role === 'admin'
            || ($me->crm_role === 'subadmin'
                && in_array('hr.policy_edit', (array) ($me->capabilities ?? []), true)),
            403, 'The HR Policy is the Company Admin’s to set.'
        );

        $data = $request->validate([
            'work_start' => ['required', 'date_format:H:i'],
            'work_end' => ['required', 'date_format:H:i'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'half_day_after_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'half_day_hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'full_day_hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'week_off_days' => ['present', 'array'],
            // Per-day office hours: '1'..'6'/'0' => {start, end}. A missing
            // day falls back to work_start/work_end.
            'day_schedule' => ['nullable', 'array'],
            'day_schedule.*.start' => ['required_with:day_schedule.*', 'date_format:H:i'],
            'day_schedule.*.end' => ['required_with:day_schedule.*', 'date_format:H:i'],
            // Every N lates in a month cost half a day's pay; 0 = off.
            'lates_per_half_day' => ['nullable', 'integer', 'min:0', 'max:31'],
            'week_off_days.*' => ['integer', 'min:0', 'max:6'],
            'probation_days' => ['required', 'integer', 'min:0', 'max:1095'],
            'monthly_leave_credit' => ['required', 'numeric', 'min:0', 'max:5'],
            'encash_unused_leave' => ['required', 'boolean'],
            'financial_year_start_month' => ['required', 'integer', 'min:1', 'max:12'],
            // The standard salary structure: statutory rates and caps, both
            // sides of the table, edited here when the law moves — and which
            // facilities a new employee starts inside.
            // Legacy single figure still lands on both sides; the split
            // fields are the real policy now.
            'pf_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pf_employer_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pf_employee_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pf_wage_cap' => ['nullable', 'numeric', 'min:0'],
            'esi_employer_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'esi_employee_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'edli_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'welfare_employee_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'welfare_employee_cap' => ['nullable', 'numeric', 'min:0'],
            'welfare_employer_multiple' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'incentive_spread_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'incentive_needs_full_payment' => ['nullable', 'boolean'],
            'pf_default' => ['nullable', 'boolean'],
            'edli_default' => ['nullable', 'boolean'],
            'esi_default' => ['nullable', 'boolean'],
            'welfare_default' => ['nullable', 'boolean'],
        ]);

        // A single pf_rate still means "both sides", as it always did.
        if (($data['pf_rate'] ?? null) !== null) {
            $data['pf_employer_rate'] = $data['pf_employer_rate'] ?? $data['pf_rate'];
            $data['pf_employee_rate'] = $data['pf_employee_rate'] ?? $data['pf_rate'];
        }
        unset($data['pf_rate']);

        // A partial save must not silently reset the rest to the defaults.
        $data = array_replace($org->hrPolicy(), array_filter($data, fn ($v) => $v !== null));

        // Lateness cannot outlast the grace it is measured from.
        if ($data['half_day_after_minutes'] < $data['grace_minutes']) {
            abort(422, 'A day cannot become a half day before it has even become late.');
        }

        $settings = $org->settings ?? [];
        $before = $org->hrPolicy();
        $settings['hr'] = $data;
        // The punch screen's own knobs read from here too, so they stay in step.
        $settings['punch'] = [
            'start' => $data['work_start'],
            'grace_minutes' => $data['grace_minutes'],
            'half_day_hours' => $data['half_day_hours'],
        ];
        $org->update(['settings' => $settings]);

        $changed = collect($data)
            ->filter(fn ($v, $k) => ($before[$k] ?? null) != $v)
            ->keys()->implode(', ');

        ActivityLog::record($me, $org->id, 'hr.policy_updated', $org, array_filter([
            'changed' => $changed ?: null,
        ]));

        return response()->json(['message' => 'HR Policy saved.', 'data' => ['policy' => $data]]);
    }

    // ---- The holiday calendar ------------------------------------------------

    public function holidays(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('crm_org');
        $policy = $org->hrPolicy();
        $year = (int) ($request->query('financial_year')
            ?: Holiday::financialYearOf(now(), (int) $policy['financial_year_start_month']));

        $holidays = Holiday::where('organization_id', $org->id)
            ->where('financial_year', $year)
            ->orderBy('holiday_date')
            ->get()
            ->map(fn (Holiday $h) => [
                'uuid' => $h->uuid,
                'holiday_date' => $h->holiday_date->toDateString(),
                'day' => $h->holiday_date->format('D'),
                'name' => $h->name,
                'is_optional' => $h->is_optional,
                'past' => $h->holiday_date->isPast(),
            ]);

        // Which years already have a calendar, so the picker is honest.
        $years = Holiday::where('organization_id', $org->id)
            ->selectRaw('financial_year, count(*) as total')
            ->groupBy('financial_year')->orderBy('financial_year')
            ->get()
            ->map(fn ($r) => ['year' => (int) $r->financial_year, 'count' => (int) $r->total]);

        return response()->json(['data' => [
            'financial_year' => $year,
            'label' => $year . '–' . substr((string) ($year + 1), 2),
            'holidays' => $holidays,
            'years' => $years,
        ]]);
    }

    /**
     * Upload a year's calendar in one go. Dates already declared are left
     * alone unless `replace` is asked for — a re-upload should correct the
     * list, not double it.
     */
    public function saveHolidays(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(in_array($me->crm_role, ['admin', 'subadmin'], true), 403,
            'The holiday calendar is the Admin’s to keep.');

        $data = $request->validate([
            'financial_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'replace' => ['nullable', 'boolean'],
            'holidays' => ['present', 'array', 'max:400'],
            'holidays.*.holiday_date' => ['required', 'date'],
            'holidays.*.name' => ['required', 'string', 'max:191'],
            'holidays.*.is_optional' => ['nullable', 'boolean'],
        ]);

        $startMonth = (int) $org->hrPolicy()['financial_year_start_month'];
        [$from, $to] = Holiday::financialYearRange($data['financial_year'], $startMonth);

        if ($data['replace'] ?? false) {
            Holiday::where('organization_id', $org->id)
                ->where('financial_year', $data['financial_year'])->delete();
        }

        $saved = 0;
        $outside = [];
        foreach ($data['holidays'] as $row) {
            $date = Carbon::parse($row['holiday_date'])->startOfDay();

            // A date that does not belong to the year being uploaded is a
            // typo, and a silent typo in a holiday list is a wrong salary.
            if ($date->lt($from) || $date->gt($to)) {
                $outside[] = $date->toDateString();
                continue;
            }

            Holiday::updateOrCreate(
                ['organization_id' => $org->id, 'holiday_date' => $date->toDateString()],
                [
                    'name' => $row['name'],
                    'financial_year' => $data['financial_year'],
                    'is_optional' => (bool) ($row['is_optional'] ?? false),
                    'created_by' => $request->user()->id,
                ],
            );
            $saved++;
        }

        ActivityLog::record($me, $org->id, 'hr.holidays_uploaded', $org, [
            'financial_year' => $data['financial_year'],
            'count' => $saved,
        ]);

        $message = $saved . ' holiday' . ($saved === 1 ? '' : 's') . ' saved for '
            . $data['financial_year'] . '–' . substr((string) ($data['financial_year'] + 1), 2) . '.';
        if ($outside !== []) {
            $message .= ' Skipped ' . count($outside) . ' outside that year: ' . implode(', ', array_slice($outside, 0, 5)) . '.';
        }

        return response()->json(['message' => $message, 'saved' => $saved, 'skipped' => $outside]);
    }

    public function deleteHoliday(Request $request, string $uuid): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(in_array($me->crm_role, ['admin', 'subadmin'], true), 403,
            'The holiday calendar is the Admin’s to keep.');

        $org = $request->attributes->get('crm_org');
        Holiday::where('organization_id', $org->id)->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Holiday removed.']);
    }

    // ---- The leave accounts --------------------------------------------------

    /** Everyone's paid-leave account, as the Admin needs to see it. */
    public function leaveAccounts(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $account = new LeaveAccount($org);
        $year = (int) ($request->query('financial_year') ?: $account->financialYear());

        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);
        $members = Member::visible()->with('user:id,name')
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->when(! $manages, fn ($q) => $q->whereIn('id', $me->teamMemberIds()))
            ->get()
            ->map(fn (Member $m) => $account->statement($m, $year) + [
                'member_uuid' => $m->uuid,
                'name' => $m->user?->name,
                'employee_code' => $m->employee_code,
                'joined_at' => $m->joined_at?->toDateString(),
            ])
            ->sortBy('name')->values();

        return response()->json(['data' => [
            'financial_year' => $year,
            'label' => $year . '–' . substr((string) ($year + 1), 2),
            'members' => $members,
            'total_balance' => round($members->sum('balance'), 2),
            'can_run_year_end' => $me->crm_role === 'admin',
        ]]);
    }

    /**
     * Catch the accounts up. Normally the scheduler does this on the 1st;
     * this is the same work, on demand, for a company that has just started
     * using the system.
     */
    public function runAccrual(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(in_array($me->crm_role, ['admin', 'subadmin'], true), 403, 'Admins only.');

        $data = $request->validate([
            'months_back' => ['nullable', 'integer', 'min:0', 'max:24'],
        ]);

        $account = new LeaveAccount($org);
        $members = Member::where('organization_id', $org->id)->where('status', 'active')->get();
        $credited = 0.0;

        for ($back = (int) ($data['months_back'] ?? 0); $back >= 0; $back--) {
            $month = now()->startOfMonth()->subMonths($back);
            foreach ($members as $member) {
                $credited += $account->creditMonth($member, $month, $request->user()->id);
            }
        }

        ActivityLog::record($me, $org->id, 'hr.leave_credited', $org, ['days' => $credited]);

        return response()->json([
            'message' => $credited > 0
                ? $credited . ' leave day(s) credited.'
                : 'Nothing to credit — every account is already up to date.',
        ]);
    }

    /** Year end: buy back what nobody used. */
    public function runYearEnd(Request $request): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless($me->crm_role === 'admin', 403, 'Only the Company Admin closes the year.');

        $data = $request->validate(['financial_year' => ['required', 'integer', 'min:2000', 'max:2100']]);
        $paid = (new LeaveAccount($org))->encashYear($data['financial_year'], $request->user()->id);

        ActivityLog::record($me, $org->id, 'hr.leave_encashed', $org, [
            'financial_year' => $data['financial_year'],
            'people' => count($paid),
            'amount' => round(collect($paid)->sum('amount'), 2),
        ]);

        return response()->json([
            'message' => $paid === []
                ? 'Nothing left to pay out for that year.'
                : count($paid) . ' account(s) closed and paid — '
                    . number_format(collect($paid)->sum('amount'), 2) . ' in total.',
            'data' => $paid,
        ]);
    }

    /** One person's account, movement by movement. */
    public function leaveLedger(Request $request, string $memberUuid): JsonResponse
    {
        /** @var Organization $org */
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $member = Member::where('organization_id', $org->id)->where('uuid', $memberUuid)->firstOrFail();
        abort_unless(
            in_array($me->crm_role, ['admin', 'subadmin'], true)
            || $member->id === $me->id
            || in_array($member->id, $me->teamMemberIds(), true),
            403,
            'That is not your leave account.',
        );

        $account = new LeaveAccount($org);
        $year = (int) ($request->query('financial_year') ?: $account->financialYear());

        $rows = LeaveLedger::where('member_id', $member->id)
            ->where('financial_year', $year)
            ->orderBy('effective_on')->orderBy('id')
            ->get()
            ->map(fn (LeaveLedger $l) => [
                'kind' => $l->kind,
                'days' => (float) $l->days,
                'effective_on' => $l->effective_on->toDateString(),
                'amount' => $l->amount === null ? null : (float) $l->amount,
                'note' => $l->note,
            ]);

        return response()->json(['data' => $account->statement($member, $year) + [
            'name' => $member->user?->name,
            'entries' => $rows,
        ]]);
    }
}
