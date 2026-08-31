<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Dwr;
use App\Models\Crm\Invoice;
use App\Models\Crm\Lead;
use App\Models\Crm\DwrEntry;
use App\Models\Crm\KpiParameter;
use App\Models\Crm\Member;
use App\Models\Crm\MemberKpi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Daily Work Reports. An employee submits values against their assigned KPI
 * parameters; the weighted score and performance band are computed here, and
 * the entries snapshot the weightage/target of the day so history stays
 * truthful when assignments change later.
 *
 * Everyone submits and sees their own reports. The org-wide view (and its
 * charts) needs the dwr module right; assignments and the parameter catalog
 * belong to whoever can edit employees.
 */
class DwrController extends Controller
{
    // ---- KPI catalog & per-employee assignment ----------------------------

    public function parameters(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        // First visit seeds the starter catalog so the screen is never empty.
        if (! KpiParameter::where('organization_id', $org->id)->exists()) {
            KpiParameter::seedDefaults($org->id);
        }

        return response()->json(['data' => KpiParameter::where('organization_id', $org->id)
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'unit', 'is_active'])]);
    }

    public function storeParameter(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128',
                Rule::unique('crm_kpi_parameters', 'name')->where('organization_id', $org->id)],
            'unit' => ['required', Rule::in(KpiParameter::UNITS)],
        ]);

        $parameter = KpiParameter::create($data + [
            'organization_id' => $org->id,
            'sort' => (int) KpiParameter::where('organization_id', $org->id)->max('sort') + 1,
        ]);

        return response()->json(['message' => 'Parameter added.', 'data' => $parameter->only(['id', 'name', 'unit', 'is_active'])], 201);
    }

    public function updateParameter(Request $request, int $id): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $parameter = KpiParameter::where('organization_id', $org->id)->findOrFail($id);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:128',
                Rule::unique('crm_kpi_parameters', 'name')->where('organization_id', $org->id)->ignore($id)],
            'unit' => ['nullable', Rule::in(KpiParameter::UNITS)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $parameter->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json(['message' => 'Parameter updated.']);
    }

    public function assignments(Request $request, string $memberUuid): JsonResponse
    {
        $member = $this->memberByUuid($request, $memberUuid);

        return response()->json(['data' => $member->kpis()->with('parameter:id,name,unit,is_active')->get()
            ->map(fn (MemberKpi $k) => [
                'parameter_id' => $k->parameter_id,
                'name' => $k->parameter?->name,
                'unit' => $k->parameter?->unit,
                'weightage' => $k->weightage,
                'daily_target' => $k->daily_target,
            ])]);
    }

    public function saveAssignments(Request $request, string $memberUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $member = $this->memberByUuid($request, $memberUuid);

        $data = $request->validate([
            'kpis' => ['present', 'array'],
            'kpis.*.parameter_id' => ['required', Rule::exists('crm_kpi_parameters', 'id')->where('organization_id', $org->id)],
            'kpis.*.weightage' => ['required', 'integer', 'min:1', 'max:100'],
            'kpis.*.daily_target' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($member, $data) {
            $member->kpis()->delete();
            foreach (array_values($data['kpis']) as $i => $row) {
                $member->kpis()->create($row + ['sort' => $i]);
            }
        });

        return response()->json(['message' => 'KPI assignment saved.']);
    }

    /** The signed-in member's own assignment — what the submit form renders. */
    public function myKpis(Request $request): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        return response()->json(['data' => $me->kpis()->with('parameter:id,name,unit,is_active')->get()
            ->filter(fn (MemberKpi $k) => $k->parameter?->is_active)
            ->values()
            ->map(fn (MemberKpi $k) => [
                'parameter_id' => $k->parameter_id,
                'name' => $k->parameter->name,
                'unit' => $k->parameter->unit,
                'weightage' => $k->weightage,
                'daily_target' => $k->daily_target,
            ])]);
    }

    /**
     * Todays figures, read straight from the ledgers, offered as a starting
     * point for the submit form. Every value stays editable - the report is
     * still the person telling their day, the system just fills what it
     * already knows: closings and invoice counts from todays invoices,
     * leads generated, and unique follow-ups attended from the lead log.
     */
    public function prefill(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $today = now()->toDateString();

        $invoices = Invoice::where('organization_id', $org->id)
            ->where('kind', 'invoice')->where('status', '!=', 'cancelled')
            ->where('member_id', $me->id)
            ->whereDate('invoice_date', $today)
            ->get(['total']);
        $leadsCreated = Lead::where('organization_id', $org->id)
            ->where('created_by', $me->user_id)
            ->whereDate('created_at', $today)
            ->count();
        $followUps = ActivityLog::where('organization_id', $org->id)
            ->where('member_id', $me->id)
            ->where('action', 'lead.followup')
            ->whereDate('created_at', $today)
            ->distinct()
            ->count('subject_id');

        $suggestions = $me->kpis()->with('parameter:id,name,unit,is_active')->get()
            ->filter(fn (MemberKpi $k) => $k->parameter?->is_active)
            ->map(function (MemberKpi $k) use ($invoices, $leadsCreated, $followUps) {
                $name = mb_strtolower($k->parameter->name);
                [$value, $basis] = match (true) {
                    str_contains($name, 'closing') && $k->parameter->unit === 'currency'
                        => [round((float) $invoices->sum('total'), 2), 'sum of todays invoices'],
                    str_contains($name, 'invoice') || str_contains($name, 'closure') || str_contains($name, 'closing')
                        => [(float) $invoices->count(), 'todays invoice count'],
                    str_contains($name, 'follow')
                        => [(float) $followUps, 'unique leads followed up today'],
                    str_contains($name, 'lead')
                        => [(float) $leadsCreated, 'leads created today'],
                    default => [null, null],
                };

                return $value === null ? null : [
                    'parameter_id' => $k->parameter_id,
                    'value' => $value,
                    'basis' => $basis,
                ];
            })
            ->filter()
            ->values();

        return response()->json(['data' => $suggestions]);
    }

    // ---- Reports -----------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request)->with('member.user:id,name');

        if ($member = $request->query('member')) {
            $query->whereHas('member', fn ($m) => $m->where('uuid', $member));
        }
        if ($band = $request->query('band')) {
            $query->where('band', $band);
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('work_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('work_date', '<=', $to);
        }

        $dwrs = $query->orderByDesc('work_date')->orderBy('member_id')->paginate(31);
        $dwrs->getCollection()->transform(fn (Dwr $d) => $this->serialize($d));

        return response()->json($dwrs);
    }

    /** Chart feed: daily average scores, band split, per-member averages. */
    public function stats(Request $request): JsonResponse
    {
        $from = $request->query('date_from', now()->subDays(13)->toDateString());
        $to = $request->query('date_to', now()->toDateString());

        $query = $this->scoped($request)
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to)
            ->whereNotNull('score');
        if ($member = $request->query('member')) {
            $query->whereHas('member', fn ($m) => $m->where('uuid', $member));
        }

        $rows = $query->with('member.user:id,name')->get();

        return response()->json(['data' => [
            'daily' => $rows->groupBy(fn ($d) => $d->work_date->toDateString())
                ->map(fn ($group, $date) => ['date' => $date, 'avg_score' => round($group->avg('score'), 1), 'count' => $group->count()])
                ->sortKeys()->values(),
            'bands' => collect(Dwr::BANDS)
                ->map(fn ($band) => ['band' => $band, 'count' => $rows->where('band', $band)->count()])
                ->filter(fn ($b) => $b['count'] > 0)->values(),
            'members' => $rows->groupBy('member_id')
                ->map(fn ($group) => [
                    'name' => $group->first()->member?->user?->name,
                    'avg_score' => round($group->avg('score'), 1),
                    'reports' => $group->count(),
                ])
                ->sortByDesc('avg_score')->values(),
        ]]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $dwr = $this->scoped($request)->with(['member.user:id,name', 'entries'])->where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => $this->serialize($dwr, full: true)]);
    }

    /** Submit (or resubmit) my report for a date. */
    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $request->validate([
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.parameter_id' => ['required', 'integer'],
            'entries.*.value' => ['required', 'numeric', 'min:0'],
            // An Admin/Subadmin correcting a day on someone's behalf.
            'member_uuid' => ['nullable', 'string'],
        ]);

        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);

        // Whose report: one's own — or, for a manager, the named person's.
        $target = $me;
        if (! empty($data['member_uuid']) && $data['member_uuid'] !== $me->uuid) {
            abort_unless($manages, 403, 'Only an Admin or Subadmin corrects another person’s report.');
            $target = Member::where('organization_id', $org->id)
                ->where('uuid', $data['member_uuid'])->firstOrFail();
        }

        // Submitted is FINAL: the owner gets one shot a day; corrections
        // afterwards are the Admin's or a Subadmin's alone.
        $already = Dwr::where('organization_id', $org->id)
            ->where('member_id', $target->id)
            ->whereDate('work_date', $data['work_date'])
            ->exists();
        if ($already && ! $manages) {
            abort(422, 'This day’s report is already submitted — it is final. Ask an Admin/Subadmin for a correction.');
        }

        // Only a recent report can be submitted by its owner — history
        // beyond a week needs an admin, which keeps backfilling honest.
        if (now()->parse($data['work_date'])->lt(now()->subDays(7)) && ! $manages) {
            abort(422, 'Reports older than a week can only be corrected by an admin.');
        }

        $assigned = $target->kpis()->with('parameter')->get()->keyBy('parameter_id');
        if ($assigned->isEmpty()) {
            abort(422, 'No KPI parameters are assigned to you yet — ask your admin.');
        }

        $dwr = DB::transaction(function () use ($org, $target, $data, $assigned, $request) {
            $me = $target;
            // whereDate, not updateOrCreate: the date cast stores midnight
            // timestamps, so a bare-date where would miss the existing row
            // and trip the unique index on resubmission.
            $dwr = Dwr::where('organization_id', $org->id)
                ->where('member_id', $me->id)
                ->whereDate('work_date', $data['work_date'])
                ->first();
            $values = ['note' => $data['note'] ?? null, 'created_by' => $request->user()->id];
            if ($dwr) {
                $dwr->update($values);
            } else {
                $dwr = Dwr::create($values + [
                    'organization_id' => $org->id,
                    'member_id' => $me->id,
                    'work_date' => $data['work_date'],
                ]);
            }
            $dwr->entries()->delete();

            $i = 0;
            foreach ($data['entries'] as $row) {
                $kpi = $assigned[$row['parameter_id']] ?? null;
                if (! $kpi || ! $kpi->parameter) {
                    continue; // values for unassigned parameters are ignored
                }
                $dwr->entries()->create([
                    'parameter_id' => $kpi->parameter_id,
                    'name' => $kpi->parameter->name,
                    'unit' => $kpi->parameter->unit,
                    'weightage' => $kpi->weightage,
                    'target' => $kpi->daily_target,
                    'value' => $row['value'],
                    'sort' => $i++,
                ]);
            }

            $this->recomputeScore($dwr);

            return $dwr;
        });

        return response()->json([
            'message' => 'DWR for ' . $dwr->work_date->toDateString() . ' submitted — score ' . $dwr->score . '%.',
            'data' => $this->serialize($dwr->fresh()->load(['member.user:id,name', 'entries']), full: true),
        ], 201);
    }

    // ---- Helpers -----------------------------------------------------------

    private function recomputeScore(Dwr $dwr): void
    {
        $entries = $dwr->entries()->get();
        $totalWeight = $entries->sum('weightage');

        $score = $totalWeight > 0
            ? round($entries->sum(fn (DwrEntry $e) => $e->weightage * $e->achievement()) / $totalWeight * 100, 1)
            : null;

        $dwr->update(['score' => $score, 'band' => Dwr::bandFor($score)]);
    }

    private function scoped(Request $request): Builder
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = Dwr::where('organization_id', $org->id);

        $seesAll = in_array($me->crm_role, ['admin', 'subadmin'], true) || $me->can('dwr', 'view');
        if (! $seesAll) {
            // Team Heads read their subtree's reports, not just their own.
            $query->whereIn('member_id', $me->teamMemberIds());
        }

        return $query;
    }

    private function memberByUuid(Request $request, string $uuid): Member
    {
        return Member::where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function serialize(Dwr $d, bool $full = false): array
    {
        $base = [
            'uuid' => $d->uuid,
            'work_date' => $d->work_date->toDateString(),
            'submitted_at' => $d->updated_at?->toDateTimeString(),
            'member' => $d->member ? ['uuid' => $d->member->uuid, 'name' => $d->member->user?->name] : null,
            'score' => $d->score !== null ? (float) $d->score : null,
            'band' => $d->band,
            'note' => $d->note,
        ];

        if (! $full) {
            return $base;
        }

        return $base + [
            'entries' => $d->entries->map(fn (DwrEntry $e) => [
                'parameter_id' => $e->parameter_id,
                'name' => $e->name,
                'unit' => $e->unit,
                'weightage' => $e->weightage,
                'target' => $e->target,
                'value' => $e->value,
                'achievement' => round($e->achievement() * 100, 1),
            ]),
        ];
    }
}
