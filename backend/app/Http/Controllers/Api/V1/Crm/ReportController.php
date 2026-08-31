<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Client;
use App\Models\Crm\Expense;
use App\Models\Crm\Invoice;
use App\Models\Crm\InvoicePayment;
use App\Models\Crm\Lead;
use App\Models\Crm\Member;
use App\Models\Crm\SalarySlip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports (the chart wall) and the User Log (the org-wide activity trail).
 * Everything is computed from the live tables — reports here are views,
 * never stored numbers that can go stale.
 */
class ReportController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        // The Reports screen belongs to the Admin, plus exactly the Subadmin
        // the Admin has named with reports.view — a raw grant, never a role.
        abort_unless($me->crm_role === 'admin'
            || ($me->crm_role === 'subadmin'
                && in_array('reports.view', (array) ($me->capabilities ?? []), true)),
            403, 'Reports are held by the Admin, plus a Subadmin the Admin names.');

        $months = min(24, max(3, (int) $request->query('months', 12)));

        // The same two ledgers as the document lists: 'mine' is anyone's own
        // sales, the combined view is a manager's company or a Team Head's
        // subtree. Money OUT (expenses, payroll) has no salesperson, so it
        // stays a company figure whatever the sales window shows.
        $scope = $request->query('scope') === 'mine' ? 'mine' : 'team';
        $window = $me->salesWindow($scope);
        $optionsWindow = $window;

        // One person's figures out of the combined view — narrowing only.
        if ($picked = $request->query('salesperson')) {
            $member = Member::where('organization_id', $org->id)->where('uuid', $picked)->first();
            $window = ($member && ($window === null || in_array($member->id, $window, true)))
                ? [$member->id] : [0];
        }

        $userWindow = $window === null ? null
            : Member::whereIn('id', $window)->pluck('user_id')->all();
        $sales = fn ($query) => $window === null ? $query : $query->where(fn ($q) => $q
            ->whereIn('member_id', $window)
            ->orWhere(fn ($w) => $w->whereNull('member_id')->whereIn('created_by', $userWindow)));
        $start = now()->startOfMonth()->subMonths($months - 1);
        $end = now()->endOfDay();

        // A picked window — This month, Last month, or the calendar range —
        // overrides the rolling presets. Buckets stay capped at 24 months.
        $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date']]);
        if ($from = $request->query('date_from')) {
            $start = \Illuminate\Support\Carbon::parse($from)->startOfDay();
            if ($to = $request->query('date_to')) {
                $end = \Illuminate\Support\Carbon::parse($to)->endOfDay();
            }
            abort_if($end->lessThan($start), 422, 'The To date sits before the From date.');
            if ($start->diffInMonths($end) >= 24) {
                $start = $end->copy()->startOfMonth()->subMonths(23);
            }
        }

        $monthKeys = collect();
        for ($cursor = $start->copy()->startOfMonth(); $cursor->lessThanOrEqualTo($end); $cursor->addMonth()) {
            $monthKeys->push($cursor->format('Y-m'));
        }

        // Money in: invoiced and actually received, per month.
        $invoices = $sales(Invoice::where('organization_id', $org->id)
            ->where('kind', 'invoice')->where('status', '!=', 'cancelled')
            ->whereDate('invoice_date', '>=', $start)
            ->whereDate('invoice_date', '<=', $end))
            ->get(['invoice_date', 'total', 'payment_status', 'client_id', 'member_id']);
        $received = InvoicePayment::whereHas('invoice', fn ($q) => $sales($q
            ->where('organization_id', $org->id)->where('status', '!=', 'cancelled')))
            ->whereDate('received_at', '>=', $start)
            ->whereDate('received_at', '<=', $end)
            ->get(['received_at', 'amount']);
        // Money out: expenses and payroll.
        $expenses = Expense::where('organization_id', $org->id)
            ->whereDate('expense_date', '>=', $start)
            ->whereDate('expense_date', '<=', $end)
            ->get(['expense_date', 'total_amount']);
        $payroll = SalarySlip::where('organization_id', $org->id)
            ->get(['year', 'month', 'net_salary'])
            ->filter(fn ($s) => sprintf('%04d-%02d', $s->year, $s->month) >= $start->format('Y-m')
                && sprintf('%04d-%02d', $s->year, $s->month) <= $end->format('Y-m'));

        $monthly = $monthKeys->map(fn ($key) => [
            'month' => $key,
            'invoiced' => round($invoices->filter(fn ($i) => $i->invoice_date->format('Y-m') === $key)->sum('total'), 2),
            'received' => round($received->filter(fn ($p) => $p->received_at->format('Y-m') === $key)->sum('amount'), 2),
            'expenses' => round($expenses->filter(fn ($e) => $e->expense_date->format('Y-m') === $key)->sum('total_amount'), 2),
            'payroll' => round($payroll->filter(fn ($s) => sprintf('%04d-%02d', $s->year, $s->month) === $key)->sum('net_salary'), 2),
        ])->values();

        // Top clients and salespeople over the window.
        $clientNames = Client::where('organization_id', $org->id)
            ->whereIn('id', $invoices->pluck('client_id')->unique())
            ->pluck('company_name', 'id');
        $topClients = $invoices->groupBy('client_id')
            ->map(fn ($g, $id) => ['name' => $clientNames[$id] ?? '—', 'amount' => round($g->sum('total'), 2), 'invoices' => $g->count()])
            ->sortByDesc('amount')->take(10)->values();

        // Commissions paid to clients out of these sales — money out that
        // belongs to a salesperson's figure, so "net of commission" is honest.
        $commissions = Expense::with('invoice:id,member_id')
            ->where('organization_id', $org->id)
            ->where('category', \App\Http\Controllers\Api\V1\Crm\CommissionController::CATEGORY)
            ->whereNotNull('invoice_id')
            ->whereDate('expense_date', '>=', $start)
            ->whereDate('expense_date', '<=', $end)
            ->when($window !== null, fn ($q) => $q->whereHas('invoice', fn ($i) => $i->whereIn('member_id', $window)))
            ->get(['id', 'invoice_id', 'total_amount', 'expense_date']);
        $commissionByMember = $commissions->groupBy(fn ($e) => $e->invoice?->member_id)
            ->map(fn ($g) => round($g->sum('total_amount'), 2));

        $memberNames = Member::with('user:id,name')
            ->whereIn('id', $invoices->pluck('member_id')->filter()->unique())
            ->get()
            ->mapWithKeys(fn ($m) => [$m->id => $m->user?->name]);
        $topSalespeople = $invoices->filter(fn ($i) => $i->member_id)
            ->groupBy('member_id')
            ->map(function ($g, $id) use ($memberNames, $commissionByMember) {
                $amount = round($g->sum('total'), 2);
                $commission = (float) ($commissionByMember[$id] ?? 0);

                return [
                    'name' => $memberNames[$id] ?? '—',
                    'amount' => $amount,
                    'commission' => $commission,
                    'net' => round($amount - $commission, 2),
                ];
            })
            ->sortByDesc('amount')->take(10)->values();

        // The lead funnel, over the same window as the money.
        $leads = Lead::where('organization_id', $org->id)
            ->when($window !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('assigned_member_id', $window)
                ->orWhereIn('created_by', $userWindow)))
            ->get(['lead_status']);

        return response()->json(['data' => [
            'months' => $monthKeys->count(),
            'scope' => $scope,
            'salespeople' => $scope === 'team'
                ? Member::visible()->with('user:id,name')
                    ->where('organization_id', $org->id)
                    ->where('status', 'active')
                    ->when($optionsWindow !== null, fn ($q) => $q->whereIn('id', $optionsWindow))
                    ->get()
                    ->map(fn (Member $m) => ['uuid' => $m->uuid, 'name' => $m->user?->name, 'is_me' => $m->id === $me->id])
                    ->values()
                : null,
            'monthly' => $monthly,
            'totals' => [
                'invoiced' => round($invoices->sum('total'), 2),
                'received' => round($received->sum('amount'), 2),
                'expenses' => round($expenses->sum('total_amount'), 2),
                'payroll' => round($payroll->sum('net_salary'), 2),
                'commission' => round($commissions->sum('total_amount'), 2),
            ],
            'invoice_status' => $invoices->groupBy('payment_status')
                ->map(fn ($g, $s) => ['status' => $s, 'count' => $g->count(), 'amount' => round($g->sum('total'), 2)])
                ->values(),
            'lead_funnel' => $leads->groupBy('lead_status')
                ->map(fn ($g, $s) => ['status' => $s, 'count' => $g->count()])
                ->values(),
            'top_clients' => $topClients,
            'top_salespeople' => $topSalespeople,
        ]]);
    }

    /** The User Log: everything anyone did, filterable like the old screen. */
    public function userLog(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $query = ActivityLog::with('member.user:id,name')
            ->where('organization_id', $org->id);

        if ($member = $request->query('member')) {
            $query->whereHas('member', fn ($m) => $m->where('uuid', $member));
        }
        if ($action = $request->query('action')) {
            $query->where('action', 'like', $action . '%');
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where('changes', 'like', "%{$search}%");
        }

        // Actions per day, for the little activity chart.
        $daily = (clone $query)
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->get(['created_at'])
            ->groupBy(fn ($l) => $l->created_at->toDateString())
            ->map(fn ($g, $d) => ['date' => $d, 'count' => $g->count()])
            ->sortKeys()->values();

        $logs = $query->latest()->latest('id')->paginate(50);
        $logs->getCollection()->transform(fn (ActivityLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'by' => $log->member?->user?->name,
            'at' => $log->created_at->toDateTimeString(),
            'changes' => $log->changes,
        ]);

        return response()->json(['daily' => $daily] + $logs->toArray());
    }
}
