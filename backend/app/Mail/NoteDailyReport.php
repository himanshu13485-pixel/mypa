<?php

namespace App\Mail;

use App\Models\Note;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Yesterday's note, this morning.
 *
 * A locked note is named and not quoted. Mail is the least private place a
 * note can end up - an inbox, a phone's lock screen, somebody's backup - and
 * a note its owner thought worth a password has no business being posted out
 * in plain text. The letter says it changed; the reading happens in the app.
 */
class NoteDailyReport extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Note $note, public bool $locked = false)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your note changed — {$this->note->title}");
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->body());
    }

    protected function body(): string
    {
        $title = htmlspecialchars($this->note->title, ENT_QUOTES);
        $when = $this->note->updated_at?->format('d M Y, H:i') ?? '';

        if ($this->locked) {
            return "<p>Your note <strong>{$title}</strong> changed on {$when}.</p>"
                . '<p>It is password protected, so its contents are not included here. '
                . 'Open it in Netvork to read it.</p>';
        }

        if ($this->note->type === 'checklist') {
            $items = collect($this->note->checklist ?? [])
                ->map(fn ($item) => sprintf(
                    '<li>%s %s</li>',
                    ! empty($item['done']) ? '&#9745;' : '&#9744;',
                    htmlspecialchars((string) ($item['text'] ?? ''), ENT_QUOTES),
                ))
                ->implode('');

            return "<p>Your note <strong>{$title}</strong> changed on {$when}.</p><ul>{$items}</ul>";
        }

        /*
         * The body is already cleaned when it is saved - the note editor
         * writes HTML through the same sanitiser the mail reader uses - so
         * what is posted out is what the app would show.
         */
        $body = (string) $this->note->body;
        if (! str_contains($body, '<')) {
            $body = '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES)) . '</p>';
        }

        return "<p>Your note <strong>{$title}</strong> changed on {$when}.</p><hr>{$body}";
    }
}
