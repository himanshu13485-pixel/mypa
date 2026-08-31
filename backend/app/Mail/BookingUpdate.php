<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The only thing the person who booked ever receives.
 *
 * One class for all three moments rather than three near-identical ones,
 * because they differ by two sentences and a heading and share everything that
 * actually matters: the time written in the reader's own timezone, and the
 * links. Splitting them would mean maintaining the timezone formatting and the
 * link building in triplicate.
 *
 * This email is also the guest's only credential. They have no account, so the
 * manage link inside it is the sole way back to a booking they have made —
 * which is worth saying plainly in the message itself.
 */
class BookingUpdate extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $kind, // confirmed | rescheduled | cancelled
    ) {
    }

    public function envelope(): Envelope
    {
        $host = $this->booking->host?->name ?? 'Netvork';
        $when = $this->localTime();

        return new Envelope(subject: match ($this->kind) {
            'cancelled' => "Cancelled — your meeting with {$host}",
            'rescheduled' => "Moved to {$when} — your meeting with {$host}",
            default => "Confirmed: {$when} with {$host}",
        });
    }

    /**
     * The booking's time as the person reading it experiences it.
     *
     * Stored in UTC and shown in the timezone they were in when they booked,
     * with the zone named. A confirmation that says "3pm" without saying whose
     * 3pm is the single most reliable way to make somebody miss a meeting.
     */
    private function localTime(): string
    {
        return $this->booking->starts_at
            ->setTimezone($this->booking->guest_timezone)
            ->format('D j M Y, g:ia T');
    }

    public function content(): Content
    {
        $booking = $this->booking;
        $host = e($booking->host?->name ?? 'your host');
        $when = e($this->localTime());
        $minutes = $booking->starts_at->diffInMinutes($booking->ends_at);
        $front = rtrim((string) config('mypa.frontend_url'), '/');
        $manage = "{$front}/booking/{$booking->manage_token}";

        if ($this->kind === 'cancelled') {
            $html = <<<HTML
            <p>Hello {$booking->name},</p>
            <p>Your {$minutes}-minute meeting with <b>{$host}</b> on <b>{$when}</b> has been
            cancelled. Nothing further is needed from you.</p>
            <p>You can book another time whenever suits: <a href="{$front}/book/{$booking->page?->slug}">book again</a>.</p>
            HTML;

            return new Content(htmlString: $html);
        }

        $lead = $this->kind === 'rescheduled'
            ? "Your meeting with <b>{$host}</b> has been moved. It is now:"
            : "Your {$minutes}-minute meeting with <b>{$host}</b> is confirmed for:";

        $join = $booking->meeting
            ? "{$front}/join/{$booking->meeting->code}"
            : $front;
        $passcode = e((string) $booking->meeting?->passcode);

        $room = $booking->meeting
            ? <<<HTML
            <p><b>Joining</b><br>
            <a href="{$join}">{$join}</a><br>
            Meeting password: <b style="letter-spacing:2px">{$passcode}</b></p>
            <p style="color:#64748b;font-size:12px">You do not need an account — open the link, enter
            the password and your name.</p>
            HTML
            : '';

        $html = <<<HTML
        <p>Hello {$booking->name},</p>
        <p>{$lead}</p>
        <p style="font-size:18px;font-weight:bold">{$when}</p>
        {$room}
        <p><b>Need to change it?</b><br>
        <a href="{$manage}">{$manage}</a></p>
        <p style="color:#64748b;font-size:12px">That link cancels or moves this booking. Keep this
        email — it is the only way back to it.</p>
        HTML;

        return new Content(htmlString: $html);
    }
}
