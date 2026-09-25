<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing instruction about arriving mail.
 *
 * "Anything with OTP in it wears the OTP label." Every test that is filled
 * in has to pass; the ones left blank are not asked about, so a filter with
 * only a subject in it is exactly as valid as one with six conditions.
 */
class MailFilter extends Model
{
    use HasUuids;

    protected $table = 'crm_mail_filters';

    protected $fillable = [
        'organization_id', 'member_id', 'mail_account_id', 'mail_label_id',
        'from_has', 'to_has', 'subject_has', 'body_has', 'body_lacks',
        'has_attachment', 'size_op', 'size_kb',
        'mark_read', 'star', 'skip_inbox', 'never_spam', 'is_active',
        'matched_count', 'last_matched_at',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'has_attachment' => 'boolean',
            'mark_read' => 'boolean',
            'star' => 'boolean',
            'skip_inbox' => 'boolean',
            'never_spam' => 'boolean',
            'is_active' => 'boolean',
            'size_kb' => 'integer',
            'matched_count' => 'integer',
            'last_matched_at' => 'datetime',
        ];
    }

    public function label(): BelongsTo
    {
        return $this->belongsTo(MailLabel::class, 'mail_label_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    /** Whether this filter asks anything at all. A filter with no test would match every mail. */
    public function asks(): bool
    {
        return filled($this->from_has) || filled($this->to_has) || filled($this->subject_has)
            || filled($this->body_has) || $this->has_attachment !== null
            || ($this->size_op !== null && $this->size_kb !== null);
    }

    /**
     * Does this mail answer every question the filter asks?
     *
     * Matching is done on lower-cased plain text, so "OTP" finds "otp" and a
     * word inside a sentence - what somebody typing one word into a filter
     * box expects, rather than an exact-phrase search that finds nothing.
     */
    public function matches(MailMessage $mail): bool
    {
        if (! $this->asks()) {
            return false;
        }

        $has = fn (?string $needle, string $hay) => blank($needle)
            || str_contains(mb_strtolower($hay), mb_strtolower(trim($needle)));

        // Recipients are kept as {email, name} pairs, and older rows as bare
        // strings. Both flatten to the same thing to search through.
        $recipients = collect(array_merge((array) ($mail->to ?? []), (array) ($mail->cc ?? [])))
            ->map(fn ($one) => is_array($one)
                ? trim(($one['name'] ?? '') . ' ' . ($one['email'] ?? ''))
                : (string) $one)
            ->implode(' ');
        $words = trim(($mail->subject ?? '') . ' ' . ($mail->body_text ?? '') . ' ' . ($mail->snippet ?? ''));

        if (! $has($this->from_has, ($mail->from_name ?? '') . ' ' . ($mail->from_email ?? ''))) {
            return false;
        }
        if (! $has($this->to_has, $recipients)) {
            return false;
        }
        if (! $has($this->subject_has, (string) $mail->subject)) {
            return false;
        }
        if (! $has($this->body_has, $words)) {
            return false;
        }
        // The one test that passes by NOT being found.
        if (filled($this->body_lacks) && str_contains(mb_strtolower($words), mb_strtolower(trim($this->body_lacks)))) {
            return false;
        }
        if ($this->has_attachment !== null && (bool) $mail->has_attachments !== $this->has_attachment) {
            return false;
        }
        if ($this->size_op !== null && $this->size_kb !== null) {
            $kb = (int) round(((int) $mail->size) / 1024);
            if ($this->size_op === 'gt' ? $kb <= $this->size_kb : $kb >= $this->size_kb) {
                return false;
            }
        }

        return true;
    }

    /** The rule in a line, for the list on screen. */
    public function inWords(): string
    {
        $parts = [];
        foreach ([
            'from_has' => 'from contains',
            'to_has' => 'to contains',
            'subject_has' => 'subject contains',
            'body_has' => 'has the words',
            'body_lacks' => "doesn't have",
        ] as $field => $said) {
            if (filled($this->{$field})) {
                $parts[] = $said . ' "' . $this->{$field} . '"';
            }
        }

        if ($this->has_attachment !== null) {
            $parts[] = $this->has_attachment ? 'has an attachment' : 'has no attachment';
        }
        if ($this->size_op && $this->size_kb) {
            $parts[] = ($this->size_op === 'gt' ? 'larger than ' : 'smaller than ') . $this->size_kb . ' KB';
        }

        return $parts ? implode(', ', $parts) : 'no conditions yet';
    }
}
