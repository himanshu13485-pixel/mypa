<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Invoice;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Churn, the industry way: a client is ACTIVE in a month if they were
 * invoiced inside the trailing twelve months (or their Work Order validity
 * still covers it). Monthly churn = clients who were active last month but
 * fell out this month, over the clients active at the month's start —
 * alongside the new-vs-repeat split and the not-renewed list.
 */
class ChurnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var \App\Models\Crm\Member $me */
        $me = $request->attributes->get('crm_member');
        $span = max(3, min(24, (int) $request->query('months', 12)));

        // Whose churn: an employee reads their own book; a Team Workspace
        // leader reads their team (each person filterable); the Admin and
        // Subadmin read the company, any person filterable.
        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);
        $picked = null;
        if ($uuid = $request->query('member')) {
            $picked = \App\Models\Crm\Member::where('organization_id', $org->id)
                ->where('uuid', $uuid)->firstOrFail();
        }
        if ($manages) {
            $memberIds = $picked ? [$picked->id] : null;
        } else {
            $window = $me->teamMemberIds();
            abort_if($picked && ! in_array($picked->id, $window, true), 403,
                'That person is outside your team.');
            $memberIds = $picked ? [$picked->id] : (count($window) > 1 ? $window : [$me->id]);
        }

        // Everything needed in two reads: every invoice's client + month,
        // and the furthest validity promise per client.
        $invoices = Invoice::where('crm_invoices.organization_id', $org->id)
            ->where('crm_invoices.kind', 'invoice')
            ->where('crm_invoices.status', '!=', 'cancelled')
            ->when($memberIds, fn ($q, $ids) => $q->whereIn('crm_invoices.member_id', $ids))
            ->join('crm_clients', 'crm_clients.id', '=', 'crm_invoices.client_id')
            ->get([
                'crm_invoices.client_id', 'crm_invoices.invoice_date', 'crm_invoices.total',
                'crm_clients.company_name',
            ]);

        $validity = \App\Models\Crm\InvoiceItem::whereIn('invoice_id', function ($q) use ($org, $memberIds) {
            $q->select('id')->from('crm_invoices')
                ->where('organization_id', $org->id)
                ->where('kind', 'invoice')->where('status', '!=', 'cancelled')
                ->when($memberIds, fn ($qq, $ids) => $qq->whereIn('member_id', $ids));
        })->whereNotNull('validity_to')
            ->join('crm_invoices', 'crm_invoices.id', '=', 'crm_invoice_items.invoice_id')
            ->groupBy('crm_invoices.client_id')
            ->selectRaw('crm_invoices.client_id, max(crm_invoice_items.validity_to) as covered_to')
            ->pluck('covered_to', 'client_id');

        $byClient = $invoices->groupBy('client_id')->map(fn ($rows) => [
            'name' => $rows->first()->company_name,
            'months' => $rows->map(fn ($r) => Carbon::parse($r->invoice_date)->format('Y-m'))->unique()->sort()->values(),
            'first' => $rows->min(fn ($r) => Carbon::parse($r->invoice_date)->format('Y-m')),
            'last' => $rows->max(fn ($r) => Carbon::parse($r->invoice_date)->format('Y-m')),
            'revenue' => (float) $rows->sum('total'),
        ]);

        // Active in month M: invoiced within [M-11 .. M], or validity covers M.
        $activeIn = function (array $client, string $ym, $coveredTo) {
            $floor = Carbon::parse($ym . '-01')->subMonthsNoOverflow(11)->format('Y-m');
            $recent = $client['months']->contains(fn ($m) => $m >= $floor && $m <= $ym);
            $covered = $coveredTo !== null && Carbon::parse($coveredTo)->format('Y-m') >= $ym
                && $client['first'] <= $ym;

            return $recent || $covered;
        };

        $rows = [];
        $cursor = now()->startOfMonth()->subMonthsNoOverflow($span - 1);
        for ($i = 0; $i < $span; $i++) {
            $ym = $cursor->format('Y-m');
            $prevYm = $cursor->copy()->subMonthNoOverflow()->format('Y-m');

            $activeNow = [];
            $activePrev = [];
            $new = 0;
            $repeat = 0;
            $churnedNames = [];

            foreach ($byClient as $clientId => $client) {
                $covered = $validity[$clientId] ?? null;
                $now = $activeIn($client, $ym, $covered);
                $before = $activeIn($client, $prevYm, $covered);
                if ($now) {
                    $activeNow[] = $clientId;
                }
                if ($before) {
                    $activePrev[] = $clientId;
                }
                if ($before && ! $now) {
                    $churnedNames[] = $client['name'];
                }
                if ($client['first'] === $ym) {
                    $new++;
                } elseif ($client['months']->contains($ym) && $client['first'] < $ym) {
                    $repeat++;
                }
            }

            $base = count($activePrev);
            $churned = count($churnedNames);
            $rows[] = [
                'month' => $ym,
                'active' => count($activeNow),
                'new_customers' => $new,
                'repeat_customers' => $repeat,
                'churned' => $churned,
                'churned_names' => array_slice($churnedNames, 0, 10),
                'churn_rate' => $base > 0 ? round($churned / $base * 100, 2) : 0.0,
                'retention_rate' => $base > 0 ? round((1 - $churned / $base) * 100, 2) : 100.0,
            ];
            $cursor->addMonthNoOverflow();
        }

        // Validity runs that ended and never renewed — the follow-up list.
        $notRenewed = [];
        foreach ($byClient as $clientId => $client) {
            $covered = $validity[$clientId] ?? null;
            if ($covered === null) {
                continue;
            }
            $end = Carbon::parse($covered);
            if ($end->isPast() && $client['last'] <= $end->format('Y-m')) {
                $notRenewed[] = [
                    'client' => $client['name'],
                    'covered_to' => $end->toDateString(),
                    'last_invoice' => $client['last'],
                    'lifetime_revenue' => round($client['revenue'], 2),
                ];
            }
        }
        usort($notRenewed, fn ($a, $b) => strcmp($b['covered_to'], $a['covered_to']));

        return response()->json(['data' => [
            'months' => $rows,
            'not_renewed' => array_slice($notRenewed, 0, 50),
            'summary' => [
                'active' => end($rows)['active'] ?? 0,
                'avg_churn_rate' => round(collect($rows)->avg('churn_rate'), 2),
                'avg_retention_rate' => round(collect($rows)->avg('retention_rate'), 2),
            ],
        ]]);
    }
}
