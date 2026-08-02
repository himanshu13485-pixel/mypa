<?php

namespace App\Mail;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectDailyReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Project $project,
        public int $changesCount,
        public array $summary,        // per-currency totals
        public string $fileContents,  // csv text or pdf binary
        public string $fileName,
        public string $fileMime,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Daily ledger report — {$this->project->name} (" . now()->format('d M Y') . ')',
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->body());
    }

    protected function body(): string
    {
        $rows = collect($this->summary)->map(fn ($s) => sprintf(
            '<tr><td style="padding:4px 12px">%s</td><td style="padding:4px 12px;color:#059669">+%s</td><td style="padding:4px 12px;color:#dc2626">−%s</td><td style="padding:4px 12px;font-weight:bold">%s</td></tr>',
            htmlspecialchars($s['currency']),
            number_format($s['credit'], 2),
            number_format($s['debit'], 2),
            number_format($s['net'], 2),
        ))->implode('');

        return <<<HTML
        <p>Hello,</p>
        <p>Your project <b>{$this->project->name}</b> had <b>{$this->changesCount}</b> change(s) today.
        The full ledger is attached.</p>
        <table style="border-collapse:collapse;font-family:sans-serif;font-size:14px">
            <tr style="background:#f1f5f9"><th style="padding:4px 12px;text-align:left">Currency</th>
            <th style="padding:4px 12px">In</th><th style="padding:4px 12px">Out</th><th style="padding:4px 12px">Net</th></tr>
            {$rows}
        </table>
        <p style="color:#64748b;font-size:12px">You receive this only on days the ledger changes.
        Turn it off in Projects &rarr; your project &rarr; Edit.</p>
        HTML;
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->fileContents, $this->fileName)
                ->withMime($this->fileMime),
        ];
    }
}
