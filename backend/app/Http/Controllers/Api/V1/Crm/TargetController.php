<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Target;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Monthly sales targets. The manager sets the numbers; achievement comes
 * straight out of the invoice ledger (tax invoices, not cancelled, dated in
 * the period, attributed to the salesperson), split New vs Existing by the
 * invoice's client category — so the screen can never disagree with billing.
 *
 * The screen reads one month by default but any run of months can be asked
 * for — a quarter, a half, a full year. Targets are still SET one month at a
 * time; a span is a reading of months already set, never a number of its own.
 */
class TargetController extends Controller
{
    /** Client categories that count as "existing" business. */
    private const EXISTING = ['existing', 'global_existing', 'sez_existing'];

    /** How the growth chart may bucket time, in months per bucket. */
    private const PERIODS = ['month' => 1, 'quarter' => 3, 'half' => 6, 'year' => 12];

    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);
        abort_unless($month >= 1 && $month <= 12, 422, 'Month must be 1-12.');

        // The far end of the span. Absent, it is the same month — the old
        // single-month screen, unchanged.
        $endYear = (int) $request->query('end_year', $year);
        $endMonth = (int) $request->query('end_month', $month);
        abort_unless($endMonth >= 1 && $endMonth <= 12, 422, 'Month must be 1-12.');

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = Carbon::create($endYear, $endMonth, 1)->endOfMonth();
        // Months counted as whole numbers, not as a difference between two
        // instants — a month-end is a fraction short of the next month.
        $startCode = $year * 12 + $month;
        $endCode = $endYear * 12 + $endMonth;
        abort_if($endCode < $startCode, 422, 'The last month cannot come before the first.');
        $span = $endCode - $startCode + 1;
        abort_if($span > 24, 422, 'A span of more than 24 months is too wide to read.');

        $isManager = in_array($me->crm_role, ['admin', 'subadmin'], true);

        // Everyone flagged as a salesperson gets a row, target set or not —
        // the old screen auto-created rows so nobody could hide by having
        // no target. Non-managers see only themselves.
        $members = Member::visible()->with('user:id,name')
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->when(! $isManager, fn ($q) => $q->whereIn('id', $me->teamMemberIds()))
            ->where(fn ($q) => $q->where('is_salesperson', true)->orWhereHas('targets', fn ($t) => $t
                ->whereRaw('(year * 12 + month) between ? and ?', [$startCode, $endCode])))
            ->orderBy('id')
            ->get();

        // Over a span the target is the sum of those months' own targets, so
        // the two readings can never drift apart.
        $targets = Target::selectRaw('member_id, sum(target_amount) as target_sum, count(*) as months_set')
            ->where('organization_id', $org->id)
            ->whereRaw('(year * 12 + month) between ? and ?', [$startCode, $endCode])
            ->groupBy('member_id')
            ->get()
            ->keyBy('member_id');

        // A note belongs to a single month; a span has many, so it goes quiet.
        $notes = $span === 1
            ? Target::where('organization_id', $org->id)
                ->where('year', $year)->where('month', $month)
                ->pluck('note', 'member_id')
            : collect();

        // One aggregate query for the whole period, split by category, with
        // the head count of clients actually billed.
        $achieved = Invoice::selectRaw(
            "member_id,
             sum(total) as total_sum,
             count(*) as invoice_count,
             count(distinct client_id) as client_count,
             sum(case when client_category in ('" . implode("','", self::EXISTING) . "') then total else 0 end) as existing_sum"
        )
            ->where('organization_id', $org->id)
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereDate('invoice_date', '>=', $start->toDateString())
            ->whereDate('invoice_date', '<=', $end->toDateString())
            ->whereNotNull('member_id')
            ->groupBy('member_id')
            ->get()
            ->keyBy('member_id');

        $rows = $members->map(function (Member $m) use ($targets, $achieved, $notes) {
            $target = (float) ($targets[$m->id]->target_sum ?? 0);
            $total = (float) ($achieved[$m->id]->total_sum ?? 0);
            $existing = (float) ($achieved[$m->id]->existing_sum ?? 0);
            $clients = (int) ($achieved[$m->id]->client_count ?? 0);

            return [
                'member_uuid' => $m->uuid,
                'name' => $m->user?->name,
                'employee_code' => $m->employee_code,
                'target' => round($target, 2),
                'achieved' => round($total, 2),
                'achieved_new' => round($total - $existing, 2),
                'achieved_existing' => round($existing, 2),
                'due' => round(max(0, $target - $total), 2),
                'percent' => $target > 0 ? round($total / $target * 100, 1) : null,
                'clients' => $clients,
                'invoices' => (int) ($achieved[$m->id]->invoice_count ?? 0),
                // What one client was worth on average to this desk.
                'per_client' => $clients > 0 ? round($total / $clients, 2) : null,
                'note' => $notes[$m->id] ?? null,
            ];
        })->sortByDesc('achieved')->values();

        // A client billed by two salespeople is still one client to the
        // company, so the head count is taken again over the whole floor
        // rather than summed down the column.
        $clientTotal = (int) Invoice::where('organization_id', $org->id)
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereDate('invoice_date', '>=', $start->toDateString())
            ->whereDate('invoice_date', '<=', $end->toDateString())
            ->whereIn('member_id', $members->pluck('id'))
            ->distinct()
            ->count('client_id');

        return response()->json([
            'data' => $rows,
            'totals' => [
                'target' => $rows->sum('target'),
                'achieved' => $rows->sum('achieved'),
                'achieved_new' => $rows->sum('achieved_new'),
                'achieved_existing' => $rows->sum('achieved_existing'),
                'due' => $rows->sum('due'),
                'clients' => $clientTotal,
                'invoices' => $rows->sum('invoices'),
                'per_client' => $clientTotal > 0 ? round($rows->sum('achieved') / $clientTotal, 2) : null,
            ],
            'year' => $year,
            'month' => $month,
            'end_year' => $endYear,
            'end_month' => $endMonth,
            'months' => $span,
            'label' => $span === 1
                ? $start->format('F Y')
                : $start->format('M Y') . ' — ' . $end->format('M Y'),
            // Numbers are typed into one month at a time; a span is read-only.
            'editable' => $span === 1,
        ]);
    }

    /**
     * The growth map: sales bucketed by month, quarter, half-year or year,
     * each bucket carrying what the same bucket did a year earlier — so the
     * trend and the year-on-year comparison are one answer, for the whole
     * floor or for one salesperson.
     */
    public function growth(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $asked = (string) $request->query('period', 'month');
        $period = array_key_exists($asked, self::PERIODS) ? $asked : 'month';
        $size = self::PERIODS[$period];
        $points = min(24, max(2, (int) $request->query('points', match ($period) {
            'month' => 12,
            'quarter' => 8,
            'half' => 6,
            default => 5,
        })));

        // The same two ledgers as every other money screen.
        $scope = $request->query('scope') === 'mine' ? 'mine' : 'team';
        $window = $me->salesWindow($scope);
        $optionsWindow = $window;
        $picked = null;

        if ($uuid = $request->query('salesperson')) {
            $member = Member::where('organization_id', $org->id)->where('uuid', $uuid)->first();
            // Narrowing only: nobody reaches outside their own window here.
            $inside = $member && ($optionsWindow === null || in_array($member->id, $optionsWindow, true));
            $window = $inside ? [$member->id] : [0];
            $picked = $inside ? $uuid : null;
        }

        // Buckets run forward to the one today sits in.
        $last = $this->bucketStart(now(), $size);
        $starts = collect(range($points - 1, 0))
            ->map(fn ($back) => $last->copy()->subMonths($back * $size))
            ->values();

        // Read a year further back so every bucket has last year's twin.
        $from = $starts->first()->copy()->subYear();
        $to = $last->copy()->addMonths($size)->subDay();

        $userWindow = $window === null ? null : Member::whereIn('id', $window)->pluck('user_id')->all();
        $invoices = Invoice::where('organization_id', $org->id)
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereDate('invoice_date', '>=', $from->toDateString())
            ->whereDate('invoice_date', '<=', $to->toDateString())
            // Older paperwork predates automatic attribution, so the person
            // who wrote it still stands in for the salesperson.
            ->when($window !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('member_id', $window)
                ->orWhere(fn ($x) => $x->whereNull('member_id')->whereIn('created_by', $userWindow))))
            ->get(['invoice_date', 'total', 'client_id']);

        $sums = [];
        foreach ($invoices as $invoice) {
            $key = $this->bucketKey($invoice->invoice_date, $size);
            $sums[$key] ??= ['achieved' => 0.0, 'invoices' => 0, 'clients' => []];
            $sums[$key]['achieved'] += (float) $invoice->total;
            $sums[$key]['invoices']++;
            $sums[$key]['clients'][$invoice->client_id] = true;
        }

        $targetByBucket = [];
        $targetRows = Target::selectRaw('year, month, sum(target_amount) as target_sum')
            ->where('organization_id', $org->id)
            ->when($window !== null, fn ($q) => $q->whereIn('member_id', $window))
            ->groupBy('year', 'month')
            ->get();
        foreach ($targetRows as $t) {
            $key = $this->bucketKey(Carbon::create($t->year, $t->month, 1), $size);
            $targetByBucket[$key] = ($targetByBucket[$key] ?? 0) + (float) $t->target_sum;
        }

        $buckets = $starts->map(function (Carbon $bucketStart) use ($size, $sums, $targetByBucket) {
            $key = $this->bucketKey($bucketStart, $size);
            $lastYearKey = $this->bucketKey($bucketStart->copy()->subYear(), $size);
            $achieved = round($sums[$key]['achieved'] ?? 0, 2);
            $lastYear = round($sums[$lastYearKey]['achieved'] ?? 0, 2);

            return [
                'key' => $key,
                'label' => $this->bucketLabel($bucketStart, $size),
                'achieved' => $achieved,
                'target' => round($targetByBucket[$key] ?? 0, 2),
                'clients' => count($sums[$key]['clients'] ?? []),
                'invoices' => $sums[$key]['invoices'] ?? 0,
                'last_year' => $lastYear,
                // A rise from nothing has no percentage worth printing.
                'yoy' => $lastYear > 0 ? round(($achieved - $lastYear) / $lastYear * 100, 1) : null,
            ];
        })->values();

        // Growth against the bucket before it — the trend itself.
        $buckets = $buckets->map(function (array $bucket, int $i) use ($buckets) {
            $prev = $i > 0 ? (float) $buckets[$i - 1]['achieved'] : null;
            $bucket['previous'] = $prev;
            $bucket['growth'] = $prev !== null && $prev > 0
                ? round(($bucket['achieved'] - $prev) / $prev * 100, 1)
                : null;

            return $bucket;
        })->values();

        // One client billed in three buckets is one client over the window.
        $clientsSeen = [];
        foreach ($buckets as $bucket) {
            $clientsSeen += $sums[$bucket['key']]['clients'] ?? [];
        }

        $totalThis = round($buckets->sum('achieved'), 2);
        $totalLast = round($buckets->sum('last_year'), 2);
        $best = $buckets->sortByDesc('achieved')->first();

        return response()->json(['data' => [
            'period' => $period,
            'points' => $points,
            'scope' => $scope,
            'salesperson' => $picked,
            'salespeople' => $scope === 'team'
                ? Member::visible()->with('user:id,name')
                    ->where('organization_id', $org->id)
                    ->where('status', 'active')
                    ->when($optionsWindow !== null, fn ($q) => $q->whereIn('id', $optionsWindow))
                    ->get()
                    ->map(fn (Member $m) => ['uuid' => $m->uuid, 'name' => $m->user?->name, 'is_me' => $m->id === $me->id])
                    ->values()
                : null,
            'buckets' => $buckets,
            'totals' => [
                'achieved' => $totalThis,
                'last_year' => $totalLast,
                'yoy' => $totalLast > 0 ? round(($totalThis - $totalLast) / $totalLast * 100, 1) : null,
                'clients' => count($clientsSeen),
                'best' => ($best && $best['achieved'] > 0) ? $best['label'] : null,
            ],
        ]]);
    }

    /** The first day of the bucket a date falls in. */
    private function bucketStart(Carbon $date, int $size): Carbon
    {
        $index = intdiv($date->month - 1, $size);

        return Carbon::create($date->year, $index * $size + 1, 1)->startOfMonth();
    }

    private function bucketKey(Carbon $date, int $size): string
    {
        $start = $this->bucketStart($date, $size);

        return $size === 12 ? (string) $start->year : $start->format('Y-m');
    }

    private function bucketLabel(Carbon $start, int $size): string
    {
        if ($size === 1) {
            return $start->format('M y');
        }
        if ($size === 12) {
            return (string) $start->year;
        }

        return $start->format('M') . '–' . $start->copy()->addMonths($size - 1)->format('M y');
    }

    /** Set or update many targets for one month in a single save. */
    public function upsert(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.member_uuid' => ['required', 'string'],
            'targets.*.target_amount' => ['required', 'numeric', 'min:0'],
            'targets.*.note' => ['nullable', 'string', 'max:512'],
        ]);

        DB::transaction(function () use ($org, $data, $request) {
            foreach ($data['targets'] as $row) {
                $member = Member::where('organization_id', $org->id)
                    ->where('uuid', $row['member_uuid'])
                    ->firstOrFail();

                Target::updateOrCreate(
                    [
                        'organization_id' => $org->id,
                        'member_id' => $member->id,
                        'year' => $data['year'],
                        'month' => $data['month'],
                    ],
                    [
                        'target_amount' => $row['target_amount'],
                        'note' => $row['note'] ?? null,
                        'created_by' => $request->user()->id,
                    ],
                );
            }
        });

        return response()->json(['message' => 'Targets saved.']);
    }

    /** Start a month by copying the previous month's numbers. */
    public function copyPrevious(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $prev = Carbon::create($data['year'], $data['month'], 1)->subMonth();
        $source = Target::where('organization_id', $org->id)
            ->where('year', $prev->year)->where('month', $prev->month)
            ->get();

        if ($source->isEmpty()) {
            abort(422, 'The previous month has no targets to copy.');
        }

        $copied = 0;
        DB::transaction(function () use ($source, $org, $data, $request, &$copied) {
            foreach ($source as $t) {
                $created = Target::firstOrCreate(
                    [
                        'organization_id' => $org->id,
                        'member_id' => $t->member_id,
                        'year' => $data['year'],
                        'month' => $data['month'],
                    ],
                    ['target_amount' => $t->target_amount, 'created_by' => $request->user()->id],
                );
                if ($created->wasRecentlyCreated) {
                    $copied++;
                }
            }
        });

        return response()->json(['message' => $copied . ' targets copied from ' . $prev->format('F Y') . '.']);
    }
}
