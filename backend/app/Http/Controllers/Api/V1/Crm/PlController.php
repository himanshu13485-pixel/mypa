<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Expense;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\SalarySlip;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The monthly P&L — the Admin's page alone, no Subadmin, no employee.
 *
 * Income on the left: gross sales (invoice totals, taxes included) of all
 * issuing companies or just the ones the Admin selects, in universal INR.
 * Expenses on the right: the expense book by category (each category a
 * switch), payroll if wanted, and hand-entered lines on either side for
 * what the system does not know — a tax provision, a credit-card bill, a
 * cash spend. One read computes the month; a span reads month on month.
 */
class PlController extends Controller
{
    private function admin(Request $request): Member
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless($me->crm_role === 'admin', 403, 'The P&L is the Company Admin’s alone.');

        return $me;
    }

    /** The setup: what counts as income, what counts as expense. */
    public function config(Request $request): JsonResponse
    {
        $this->admin($request);
        $org = $request->attributes->get('crm_org');

        return response()->json(['data' => [
            'config' => $this->settings($org),
            'companies' => IssuingCompany::where('organization_id', $org->id)
                ->orderBy('name')->get(['id', 'name', 'currency']),
            'categories' => $org->optionList('expense_categories'),
        ]]);
    }

    public function saveConfig(Request $request): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            // null = all companies / all categories.
            'income_company_ids' => ['nullable', 'array'],
            'income_company_ids.*' => ['integer'],
            'expense_categories' => ['nullable', 'array'],
            'expense_categories.*' => ['string', 'max:64'],
            'include_salaries' => ['required', 'boolean'],
            'include_proformas' => ['nullable', 'boolean'],
        ]);

        $settings = $org->settings ?? [];
        $settings['pl'] = $data;
        $org->update(['settings' => $settings]);
        ActivityLog::record($me, $org->id, 'pl.config_saved', $org);

        return response()->json(['message' => 'P&L setup saved.']);
    }

    /** @return array<string, mixed> */
    private function settings($org): array
    {
        return (array) data_get($org->settings, 'pl', []) + [
            'income_company_ids' => null,
            'expense_categories' => null,
            'include_salaries' => true,
            'include_proformas' => false,
        ];
    }

    /** The statement: one month, or month on month over a span. */
    public function index(Request $request): JsonResponse
    {
        $this->admin($request);
        $org = $request->attributes->get('crm_org');
        $cfg = $this->settings($org);

        [$from, $to] = $this->span($request);

        return response()->json(['data' => $this->statement($org, $cfg, $from, $to) + ['config' => $cfg]]);
    }

    /**
     * The statement as Excel - the Admin's alone, like the screen.
     *
     * The first sheet reads like the screen, month by month: income lines,
     * expense lines, the totals and the result. The second is one row a
     * month, for anybody who wants to chart it.
     */
    public function export(Request $request)
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');
        $cfg = $this->settings($org);
        [$from, $to] = $this->span($request);

        $statement = $this->statement($org, $cfg, $from, $to);
        $label = fn (string $ym) => Carbon::parse($ym . '-01')->format('F Y');
        $period = $from === $to ? $label($from) : $label($from) . ' to ' . $label($to);

        $rows = [
            [\App\Support\Xlsx::cell('Profit & Loss — ' . $org->name . ' — ' . $period, \App\Support\Xlsx::TITLE)],
            ['Generated ' . now()->format('d M Y H:i') . '. Income is gross sales in INR; salaries are the month’s CTC.'],
            [],
            array_map(fn ($h) => \App\Support\Xlsx::cell($h, \App\Support\Xlsx::HEADER), ['Month', 'Side', 'Line', 'Amount (INR)']),
        ];
        foreach ($statement['months'] as $m) {
            $month = $label($m['month']);
            foreach ($m['income'] as $line) {
                $rows[] = [$month, 'Income', $line['label'], \App\Support\Xlsx::money($line['amount'])];
            }
            $rows[] = [$month, 'Income', \App\Support\Xlsx::cell('Total income', \App\Support\Xlsx::BOLD), \App\Support\Xlsx::money($m['income_total'], true)];
            foreach ($m['expenses'] as $line) {
                $rows[] = [$month, 'Expense', $line['label'], \App\Support\Xlsx::money($line['amount'])];
            }
            $rows[] = [$month, 'Expense', \App\Support\Xlsx::cell('Total expenses', \App\Support\Xlsx::BOLD), \App\Support\Xlsx::money($m['expense_total'], true)];
            $rows[] = [$month, '', \App\Support\Xlsx::cell($m['profit'] >= 0 ? 'Profit' : 'Loss', \App\Support\Xlsx::BOLD), \App\Support\Xlsx::money($m['profit'], true)];
            $rows[] = [];
        }

        $summary = [
            [\App\Support\Xlsx::cell('Summary — ' . $period, \App\Support\Xlsx::TITLE)],
            [],
            array_map(fn ($h) => \App\Support\Xlsx::cell($h, \App\Support\Xlsx::HEADER), ['Month', 'Income', 'Expenses', 'Profit / loss']),
        ];
        foreach ($statement['months'] as $m) {
            $summary[] = [$label($m['month']), \App\Support\Xlsx::money($m['income_total']), \App\Support\Xlsx::money($m['expense_total']), \App\Support\Xlsx::money($m['profit'])];
        }
        $summary[] = [
            \App\Support\Xlsx::cell('Total', \App\Support\Xlsx::BOLD),
            \App\Support\Xlsx::money($statement['totals']['income'], true),
            \App\Support\Xlsx::money($statement['totals']['expense'], true),
            \App\Support\Xlsx::money($statement['totals']['profit'], true),
        ];

        ActivityLog::record($me, $org->id, 'export.pl', $org, ['period' => $period]);

        $path = (new \App\Support\Xlsx())
            ->sheet('P&L', $rows, [16, 10, 44, 16], 4)
            ->sheet('Summary', $summary, [16, 16, 16, 16], 3)
            ->toTempFile();

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, 'profit-and-loss-' . ($from === $to ? $from : $from . '-to-' . $to) . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function span(Request $request): array
    {
        $from = $request->query('month_from', now()->format('Y-m'));
        $to = $request->query('month_to', $from);
        abort_if($to < $from, 422, 'The last month cannot come before the first.');

        return [$from, $to];
    }

    /** @param array<string, mixed> $cfg */
    private function statement($org, array $cfg, string $from, string $to): array
    {
        $months = [];
        $cursor = Carbon::parse($from . '-01');
        $stop = Carbon::parse($to . '-01');
        $guard = 0;
        while ($cursor->lte($stop) && $guard++ < 24) {
            $months[] = $this->month($org, $cfg, $cursor->copy());
            $cursor->addMonthNoOverflow();
        }

        return [
            'months' => $months,
            'totals' => [
                'income' => round(collect($months)->sum('income_total'), 2),
                'expense' => round(collect($months)->sum('expense_total'), 2),
                'profit' => round(collect($months)->sum('profit'), 2),
            ],
        ];
    }

    /** @param array<string, mixed> $cfg */
    private function month($org, array $cfg, Carbon $month): array
    {
        $key = $month->format('Y-m');
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();

        // ---- Income: gross sales, taxes included, in universal INR -------
        $kinds = ($cfg['include_proformas'] ?? false) ? ['invoice', 'proforma'] : ['invoice'];
        $invoices = Invoice::with('issuingCompany:id,name,currency')
            ->where('organization_id', $org->id)
            ->whereIn('kind', $kinds)
            ->where('status', '!=', 'cancelled')
            ->whereDate('invoice_date', '>=', $start)
            ->whereDate('invoice_date', '<=', $end)
            ->when($cfg['income_company_ids'] ?? null,
                fn ($q, $ids) => $q->whereIn('issuing_company_id', $ids))
            ->get(['id', 'issuing_company_id', 'currency', 'total', 'total_fx']);

        // A foreign-currency invoice counts at its frozen INR equivalent.
        $inr = fn ($i) => strtoupper((string) ($i->currency ?: 'INR')) === 'INR'
            ? (float) $i->total
            : (float) ($i->total_fx ?: $i->total);

        $incomeLines = $invoices->groupBy(fn ($i) => $i->issuingCompany?->name ?? 'No company')
            ->map(fn ($group, $name) => ['label' => $name . ' — gross sales', 'amount' => round($group->sum($inr), 2), 'source' => 'sales'])
            ->values()->all();

        // ---- Expenses: the book by category, payroll, manual lines -------
        $expenseQuery = Expense::where('organization_id', $org->id)
            ->whereDate('expense_date', '>=', $start)
            ->whereDate('expense_date', '<=', $end)
            ->when($cfg['expense_categories'] ?? null,
                fn ($q, $cats) => $q->whereIn('category', $cats));
        $expenseLines = $expenseQuery->get(['category', 'total_amount'])
            ->groupBy(fn ($e) => $e->category ?: 'Uncategorised')
            ->map(fn ($group, $cat) => ['label' => $cat, 'amount' => round((float) $group->sum('total_amount'), 2), 'source' => 'expenses'])
            ->values()->all();

        if ($cfg['include_salaries'] ?? true) {
            // The cost of the payroll, not what reached the bank: the net
            // plus every deduction - both halves of PF, ESI and the welfare
            // fund, EDLI, and the other deductions.
            $payroll = (float) SalarySlip::where('organization_id', $org->id)
                ->where('year', $month->year)->where('month', $month->month)
                ->get(['net_salary', 'deductions', 'other_deductions'])
                ->sum(fn ($s) => (float) $s->net_salary + (float) $s->deductions + (float) $s->other_deductions);
            if ($payroll > 0) {
                $expenseLines[] = ['label' => 'Salaries (CTC)', 'amount' => round($payroll, 2), 'source' => 'payroll'];
            }
        }

        // The hand-entered lines, either side.
        $manual = DB::table('crm_pl_lines')
            ->where('organization_id', $org->id)->where('month', $key)
            ->orderBy('id')->get();
        foreach ($manual as $line) {
            $row = ['id' => $line->id, 'label' => $line->label, 'amount' => (float) $line->amount, 'source' => 'manual'];
            if ($line->side === 'income') {
                $incomeLines[] = $row;
            } else {
                $expenseLines[] = $row;
            }
        }

        $incomeTotal = round(collect($incomeLines)->sum('amount'), 2);
        $expenseTotal = round(collect($expenseLines)->sum('amount'), 2);

        return [
            'month' => $key,
            'income' => $incomeLines,
            'expenses' => $expenseLines,
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'profit' => round($incomeTotal - $expenseTotal, 2),
        ];
    }

    /** A manual line: what the books know that the system does not. */
    public function storeLine(Request $request): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'side' => ['required', Rule::in(['income', 'expense'])],
            'label' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $id = DB::table('crm_pl_lines')->insertGetId($data + [
            'organization_id' => $org->id,
            'created_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        ActivityLog::record($me, $org->id, 'pl.line_added', $org, $data);

        return response()->json(['message' => 'Line added.', 'data' => ['id' => $id]], 201);
    }

    public function deleteLine(Request $request, int $id): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $deleted = DB::table('crm_pl_lines')
            ->where('organization_id', $org->id)->where('id', $id)->delete();
        abort_unless($deleted, 404, 'No such line.');

        return response()->json(['message' => 'Line removed.']);
    }
}
