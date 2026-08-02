<?php

namespace App\Console\Commands;

use App\Mail\ProjectDailyReport;
use App\Models\Project;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Daily project ledger reports: mailed once a day, ONLY when the ledger
 * changed since the last report, as an Excel-compatible CSV or a PDF.
 */
class SendProjectDailyReports extends Command
{
    protected $signature = 'mypa:project-daily-reports';

    protected $description = 'Email daily project ledger reports (only on days with changes)';

    public function handle(): int
    {
        $sent = 0;

        Project::with('user')
            ->where('daily_report', true)
            ->where('is_archived', false)
            ->chunkById(100, function ($projects) use (&$sent) {
                foreach ($projects as $project) {
                    $owner = $project->user;
                    if (! $owner?->email || ! $owner->email_verified_at) {
                        continue;
                    }

                    // First report ever: everything counts as new.
                    $since = $project->last_reported_at;
                    $changes = $since
                        ? $project->entries()
                            ->where(fn ($q) => $q->where('created_at', '>', $since)->orWhere('updated_at', '>', $since))
                            ->count()
                        : $project->entries()->count();
                    if ($changes === 0) {
                        continue; // nothing new — stay quiet today
                    }

                    [$contents, $name, $mime] = $project->report_format === 'pdf'
                        ? $this->pdfReport($project)
                        : $this->excelReport($project);

                    Mail::to($owner->email)->queue(new ProjectDailyReport(
                        $project,
                        $changes,
                        $this->summarize($project),
                        $contents,
                        $name,
                        $mime,
                    ));
                    $project->updateQuietly(['last_reported_at' => now()]);
                    $sent++;
                }
            });

        $this->info("Sent {$sent} project report(s).");

        return self::SUCCESS;
    }

    protected function summarize(Project $project): array
    {
        $rows = $project->entries()
            ->selectRaw('currency, direction, SUM(amount) as total')
            ->groupBy('currency', 'direction')
            ->get();

        $byCurrency = [];
        foreach ($rows as $row) {
            $c = &$byCurrency[$row->currency];
            $c['currency'] = $row->currency;
            $c['credit'] = ($c['credit'] ?? 0) + ($row->direction === 'credit' ? (float) $row->total : 0);
            $c['debit'] = ($c['debit'] ?? 0) + ($row->direction === 'debit' ? (float) $row->total : 0);
            unset($c);
        }

        return collect($byCurrency)
            ->map(fn ($c) => $c + ['net' => round(($c['credit'] ?? 0) - ($c['debit'] ?? 0), 2)])
            ->values()
            ->all();
    }

    /** Excel-compatible CSV, same layout as the in-app export. */
    protected function excelReport(Project $project): array
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Date', 'Description', 'Party', 'Type', 'Mode', 'Bank account', 'Currency', 'Credit (in)', 'Debit (out)'], ',', '"', '\\');

        $running = [];
        $project->entries()->orderBy('entry_date')->orderBy('id')->chunk(500, function ($entries) use ($out, &$running) {
            foreach ($entries as $e) {
                $running[$e->currency] = ($running[$e->currency] ?? 0)
                    + ((float) $e->amount) * ($e->direction === 'credit' ? 1 : -1);
                fputcsv($out, [
                    $e->entry_date->toDateString(),
                    $e->description,
                    $e->counterparty,
                    $e->direction === 'credit' ? 'Credit (taken/received)' : 'Debit (given/spent)',
                    $e->mode,
                    $e->bank_account,
                    $e->currency,
                    $e->direction === 'credit' ? number_format((float) $e->amount, 2, '.', '') : '',
                    $e->direction === 'debit' ? number_format((float) $e->amount, 2, '.', '') : '',
                ], ',', '"', '\\');
            }
        });
        fputcsv($out, [], ',', '"', '\\');
        foreach ($running as $currency => $net) {
            fputcsv($out, ['', '', '', '', '', 'NET TOTAL', $currency, $net >= 0 ? number_format($net, 2, '.', '') : '', $net < 0 ? number_format(-$net, 2, '.', '') : ''], ',', '"', '\\');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return [$csv, str($project->name)->slug() . '-ledger-' . now()->format('Ymd') . '.csv', 'text/csv'];
    }

    protected function pdfReport(Project $project): array
    {
        $entries = $project->entries()->orderBy('entry_date')->orderBy('id')->get();
        $summary = $this->summarize($project);

        $rows = $entries->map(fn ($e) => sprintf(
            '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td style="text-align:right;color:%s">%s%s %s</td></tr>',
            $e->entry_date->format('d M Y'),
            htmlspecialchars($e->description),
            htmlspecialchars($e->counterparty ?? '-'),
            $e->mode . ($e->bank_account ? ' (' . htmlspecialchars($e->bank_account) . ')' : ''),
            $e->direction === 'credit' ? '#059669' : '#dc2626',
            $e->direction === 'credit' ? '+' : '-',
            number_format((float) $e->amount, 2),
            $e->currency,
        ))->implode('');

        $totals = collect($summary)->map(fn ($s) => sprintf(
            '<tr><td colspan="4" style="text-align:right;font-weight:bold">NET TOTAL (%s)</td><td style="text-align:right;font-weight:bold">%s</td></tr>',
            $s['currency'],
            number_format($s['net'], 2),
        ))->implode('');

        $html = <<<HTML
        <html><head><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
            h1 { font-size: 16px; } .muted { color: #64748b; }
            table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
            th { background: #f1f5f9; }
        </style></head><body>
            <h1>{$project->name} — ledger report</h1>
            <p class="muted">Netvork · generated {$this->nowString()} · purpose: {$project->purpose}</p>
            <table>
                <tr><th>Date</th><th>Description</th><th>Party</th><th>Mode</th><th>Amount</th></tr>
                {$rows}
                {$totals}
            </table>
        </body></html>
        HTML;

        $pdf = Pdf::loadHTML($html)->setPaper('a4')->output();

        return [$pdf, str($project->name)->slug() . '-ledger-' . now()->format('Ymd') . '.pdf', 'application/pdf'];
    }

    protected function nowString(): string
    {
        return now()->format('d M Y, H:i');
    }
}
