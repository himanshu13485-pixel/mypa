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
            // The note travels with the figure: an explanation typed for
            // the accountant is no use if it stays on the screen.
            array_map(fn ($h) => \App\Support\Xlsx::cell($h, \App\Support\Xlsx::HEADER), ['Month', 'Side', 'Line', 'Amount (INR)', 'Notes']),
        ];
        $said = fn (array $row) => collect($row['notes'] ?? [])
            ->map(fn ($n) => $n['body'] . ' — ' . $n['author'])
            ->implode(' · ');
        foreach ($statement['months'] as $m) {
            $month = $label($m['month']);
            foreach ($m['income'] as $line) {
                $rows[] = [$month, 'Income', $line['label'], \App\Support\Xlsx::money($line['amount']), $said($line)];
            }
            $rows[] = [$month, 'Income', \App\Support\Xlsx::cell('Total income', \App\Support\Xlsx::BOLD), \App\Support\Xlsx::money($m['income_total'], true)];
            foreach ($m['expenses'] as $line) {
                $rows[] = [$month, 'Expense', $line['label'], \App\Support\Xlsx::money($line['amount']), $said($line)];
            }
            $rows[] = [$month, 'Expense', \App\Support\Xlsx::cell('Total expenses', \App\Support\Xlsx::BOLD), \App\Support\Xlsx::money($m['expense_total'], true)];
            // The month's own remarks sit beside its result, which is the
            // line somebody reading the sheet stops at.
            $rows[] = [$month, '', \App\Support\Xlsx::cell($m['profit'] >= 0 ? 'Profit' : 'Loss', \App\Support\Xlsx::BOLD), \App\Support\Xlsx::money($m['profit'], true), $said($m)];
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
            ->sheet('P&L', $rows, [16, 10, 44, 16, 60], 4)
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

            // People paid outside the payroll, under their own head.
            $offline = (float) \App\Models\Crm\OfflineSalary::where('organization_id', $org->id)
                ->where('year', $month->year)->where('month', $month->month)
                ->sum('amount');
            if ($offline > 0) {
                $expenseLines[] = ['label' => 'Offline Salary', 'amount' => round($offline, 2), 'source' => 'offline_payroll'];
            }
        }

        // The added lines, either side. One linked to a month's figure reads
        // that figure afresh; a typed one keeps its amount.
        $manual = DB::table('crm_pl_lines')
            ->where('organization_id', $org->id)->where('month', $key)
            ->orderBy('id')->get();
        $figures = $manual->contains(fn ($l) => ! empty($l->auto_key))
            ? collect($this->figures($org, $cfg, $month))->keyBy('key')
            : collect();
        foreach ($manual as $line) {
            $linked = ! empty($line->auto_key) && $figures->has($line->auto_key);
            $row = [
                'id' => $line->id,
                'label' => $line->label,
                'amount' => $linked ? (float) $figures[$line->auto_key]['amount'] : (float) $line->amount,
                'source' => $linked ? 'linked' : 'manual',
                'auto_key' => $line->auto_key,
            ];
            if ($line->side === 'income') {
                $incomeLines[] = $row;
            } else {
                $expenseLines[] = $row;
            }
        }

        // ---- What people wrote about all this ----------------------------
        $notes = DB::table('crm_pl_notes')
            ->leftJoin('users', 'users.id', '=', 'crm_pl_notes.created_by')
            ->where('crm_pl_notes.organization_id', $org->id)
            ->where('crm_pl_notes.month', $key)
            ->orderBy('crm_pl_notes.id')
            ->get(['crm_pl_notes.id', 'crm_pl_notes.line_key', 'crm_pl_notes.body', 'crm_pl_notes.created_at', 'users.name as author']);

        $shape = fn ($n) => [
            'id' => (int) $n->id,
            'body' => $n->body,
            'author' => $n->author ?: 'Someone',
            'at' => Carbon::parse($n->created_at)->toDateTimeString(),
        ];
        $byLine = $notes->whereNotNull('line_key')->groupBy('line_key');
        $attach = fn (array $lines) => array_map(function (array $row) use ($byLine, $shape) {
            $row['key'] = self::lineKey($row);
            $row['notes'] = collect($byLine->get($row['key'], []))->map($shape)->values()->all();

            return $row;
        }, $lines);

        $incomeLines = $attach($incomeLines);
        $expenseLines = $attach($expenseLines);

        $incomeTotal = round(collect($incomeLines)->sum('amount'), 2);
        $expenseTotal = round(collect($expenseLines)->sum('amount'), 2);

        return [
            'month' => $key,
            'income' => $incomeLines,
            'expenses' => $expenseLines,
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'profit' => round($incomeTotal - $expenseTotal, 2),
            // The month's own remarks: what was unusual about it, said once
            // where it will be read again next year.
            'notes' => $notes->whereNull('line_key')->map($shape)->values()->all(),
        ];
    }

    /**
     * The name a note hangs on.
     *
     * Most entries are not rows in a table. Gross sales, a category of the
     * expense book, the payroll - each is worked out afresh every time the
     * page is opened, so a note has to be pinned to what the entry IS rather
     * than to an id it does not have. A hand-added line does have one, and
     * uses it, so renaming that line keeps its notes.
     *
     * The price is that renaming a category, or an issuing company, starts
     * the notes again - which is the honest answer, because the explanation
     * was written about a line that no longer goes by that name.
     *
     * @param  array<string, mixed>  $row
     */
    private static function lineKey(array $row): string
    {
        return ! empty($row['id'])
            ? 'line:' . $row['id']
            : $row['source'] . ':' . $row['label'];
    }

    /**
     * The month's own figures a line can be added from: the taxes and TDS
     * on its invoices (the same companies and documents the income counts),
     * commission and gateway charges, and the whole expense book.
     *
     * Each says whether the statement already counts it, so nothing is
     * added twice by accident.
     *
     * @param  array<string, mixed>  $cfg
     * @return list<array{key: string, label: string, amount: float, side: string, already_counted: bool, note: string}>
     */
    private function figures($org, array $cfg, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();
        $kinds = ($cfg['include_proformas'] ?? false) ? ['invoice', 'proforma'] : ['invoice'];

        $invoices = Invoice::where('organization_id', $org->id)
            ->whereIn('kind', $kinds)
            ->where('status', '!=', 'cancelled')
            ->whereDate('invoice_date', '>=', $start)
            ->whereDate('invoice_date', '<=', $end)
            ->when($cfg['income_company_ids'] ?? null, fn ($q, $ids) => $q->whereIn('issuing_company_id', $ids))
            ->get(['currency', 'subtotal', 'discount', 'cgst', 'sgst', 'igst', 'other_tax', 'tds', 'total', 'total_fx']);

        // In INR, the way the income counts: a foreign document at its frozen rate.
        $sum = fn (callable $pick) => round($invoices->sum(function ($i) use ($pick) {
            $rate = strtoupper((string) ($i->currency ?: 'INR')) === 'INR' || (float) $i->total == 0.0
                ? 1.0
                : (float) ($i->total_fx ?: $i->total) / (float) $i->total;

            return (float) $pick($i) * $rate;
        }), 2);

        $cgst = $sum(fn ($i) => $i->cgst);
        $sgst = $sum(fn ($i) => $i->sgst);
        $igst = $sum(fn ($i) => $i->igst);

        $book = Expense::where('organization_id', $org->id)
            ->whereDate('expense_date', '>=', $start)
            ->whereDate('expense_date', '<=', $end)
            ->get(['category', 'total_amount']);
        $category = fn (string $name) => round((float) $book->where('category', $name)->sum('total_amount'), 2);
        $counted = fn (string $name) => ($cfg['expense_categories'] ?? null) === null
            || in_array($name, (array) $cfg['expense_categories'], true);
        $commissionName = CommissionController::CATEGORY;
        $gatewayName = \App\Services\Crm\GatewayCharge::CATEGORY;

        $taxNote = 'From this month’s invoices. Gross sales already include it, so as an expense it takes it back out.';

        return [
            ['key' => 'cgst', 'label' => 'CGST', 'amount' => $cgst, 'side' => 'expense', 'already_counted' => false, 'note' => $taxNote],
            ['key' => 'sgst', 'label' => 'SGST', 'amount' => $sgst, 'side' => 'expense', 'already_counted' => false, 'note' => $taxNote],
            ['key' => 'igst', 'label' => 'IGST', 'amount' => $igst, 'side' => 'expense', 'already_counted' => false, 'note' => $taxNote],
            ['key' => 'gst_total', 'label' => 'Total GST', 'amount' => round($cgst + $sgst + $igst, 2), 'side' => 'expense', 'already_counted' => false, 'note' => 'CGST + SGST + IGST on this month’s invoices, as one line.'],
            ['key' => 'other_tax', 'label' => 'Other tax', 'amount' => $sum(fn ($i) => $i->other_tax), 'side' => 'expense', 'already_counted' => false, 'note' => $taxNote],
            ['key' => 'tds', 'label' => 'TDS', 'amount' => $sum(fn ($i) => $i->tds), 'side' => 'expense', 'already_counted' => false, 'note' => 'TDS clients held back on this month’s invoices.'],
            ['key' => 'taxable_value', 'label' => 'Basic (taxable) value', 'amount' => $sum(fn ($i) => (float) $i->subtotal - (float) $i->discount), 'side' => 'income', 'already_counted' => false, 'note' => 'This month’s invoices before tax.'],
            ['key' => 'commission', 'label' => 'Commission', 'amount' => $category($commissionName), 'side' => 'expense', 'already_counted' => $counted($commissionName), 'note' => 'Client commission recorded against this month’s sales.'],
            ['key' => 'gateway', 'label' => 'Bank / gateway charges', 'amount' => $category($gatewayName), 'side' => 'expense', 'already_counted' => $counted($gatewayName), 'note' => 'Payment gateway charges in the expense book.'],
            ['key' => 'expenses', 'label' => 'Expenses', 'amount' => round((float) $book->sum('total_amount'), 2), 'side' => 'expense', 'already_counted' => $book->isNotEmpty() && ($cfg['expense_categories'] ?? null) === null, 'note' => 'Every expense this month, all categories, as one line.'],
        ];
    }

    /** The month's figures the Add window offers. */
    public function figuresFor(Request $request): JsonResponse
    {
        $this->admin($request);
        $org = $request->attributes->get('crm_org');
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        $taken = DB::table('crm_pl_lines')
            ->where('organization_id', $org->id)->where('month', $data['month'])
            ->whereNotNull('auto_key')->pluck('auto_key')->all();

        return response()->json(['data' => collect($this->figures($org, $this->settings($org), Carbon::parse($data['month'] . '-01')))
            ->map(fn ($f) => $f + ['added' => in_array($f['key'], $taken, true)])
            ->values()]);
    }

    /** An added line: typed by hand, or following one of the month's figures. */
    public function storeLine(Request $request): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $keys = ['cgst', 'sgst', 'igst', 'gst_total', 'other_tax', 'tds', 'taxable_value', 'commission', 'gateway', 'expenses'];
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'side' => ['required', Rule::in(['income', 'expense'])],
            'label' => ['required', 'string', 'max:255'],
            'auto_key' => ['nullable', Rule::in($keys)],
            'amount' => ['required_without:auto_key', 'nullable', 'numeric', 'min:0.01'],
        ]);

        if (! empty($data['auto_key'])) {
            abort_if(
                DB::table('crm_pl_lines')->where('organization_id', $org->id)->where('month', $data['month'])
                    ->where('auto_key', $data['auto_key'])->exists(),
                422,
                'That figure is already on this month’s P&L.',
            );
            // Kept as it stood when added; the statement reads the live figure.
            $data['amount'] = collect($this->figures($org, $this->settings($org), Carbon::parse($data['month'] . '-01')))
                ->firstWhere('key', $data['auto_key'])['amount'] ?? 0;
        } else {
            $data['auto_key'] = null;
        }

        $id = DB::table('crm_pl_lines')->insertGetId($data + [
            'organization_id' => $org->id,
            'created_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        ActivityLog::record($me, $org->id, 'pl.line_added', $org, $data);

        return response()->json(['message' => $data['auto_key'] ? $data['label'] . ' added — it follows the month’s figures.' : 'Line added.', 'data' => ['id' => $id]], 201);
    }

    /**
     * Write something beside a figure, or beside the month.
     *
     * No line_key means the month itself. There is no limit on how many a
     * month or an entry may carry: an explanation is not a field to be
     * overwritten by the next person who has something to say.
     */
    public function storeNote(Request $request): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'line_key' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $id = DB::table('crm_pl_notes')->insertGetId([
            'organization_id' => $org->id,
            'month' => $data['month'],
            'line_key' => $data['line_key'] ?: null,
            'body' => trim($data['body']),
            'created_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        ActivityLog::record($me, $org->id, 'pl.note_added', $org, array_filter([
            'month' => $data['month'],
            'line' => $data['line_key'] ?? null,
        ]));

        return response()->json([
            'message' => empty($data['line_key']) ? 'Noted against ' . $data['month'] . '.' : 'Noted.',
            'data' => ['id' => $id],
        ], 201);
    }

    public function deleteNote(Request $request, int $id): JsonResponse
    {
        $me = $this->admin($request);
        $org = $request->attributes->get('crm_org');

        $note = DB::table('crm_pl_notes')->where('organization_id', $org->id)->where('id', $id)->first();
        abort_unless($note, 404, 'No such note.');

        DB::table('crm_pl_notes')->where('id', $id)->delete();
        ActivityLog::record($me, $org->id, 'pl.note_removed', $org, ['month' => $note->month]);

        return response()->json(['message' => 'Note removed.']);
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
