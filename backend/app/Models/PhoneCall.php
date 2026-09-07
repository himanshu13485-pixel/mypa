<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A call that left the app on somebody's own SIM.
 *
 * The counterpart to Call, which is a Netvork call between two accounts. This
 * one has a phone number on the far end and nobody to attach.
 *
 * Read the migration for why the outcome columns are nullable: the attempt is
 * ours to know and everything after it happens where an app cannot look.
 */
class PhoneCall extends Model
{
    use HasUuids;

    /**
     * How a call ended, as the person who made it describes it.
     *
     * Short and closed, because the value of this field is being able to
     * count it — "no answer" three times on one lead is a fact worth seeing,
     * and free text never adds up. Anything more belongs in notes.
     */
    public const OUTCOMES = [
        'connected' => 'Spoke to them',
        'no_answer' => 'No answer',
        'busy' => 'Busy',
        'wrong_number' => 'Wrong number',
        'unreachable' => 'Switched off / unreachable',
    ];

    protected $fillable = [
        'user_id', 'organization_id', 'subject_type', 'subject_id',
        'number', 'label', 'placed_from', 'placed_at',
        'connected_at', 'ended_at', 'duration_seconds', 'outcome', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'placed_at' => 'datetime',
            'connected_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether anybody has said how it went.
     *
     * A call nobody logged the outcome of is not a failed call — it is an
     * unanswered question, and the two read differently on a lead's history.
     */
    public function isLogged(): bool
    {
        return $this->outcome !== null;
    }

    /**
     * How long it lasted, in seconds, however that was recorded.
     *
     * A caller may give the length directly, or the app may have watched the
     * clock between connecting and hanging up. Either is the same answer, and
     * neither is the carrier's.
     */
    public function seconds(): ?int
    {
        if ($this->duration_seconds !== null) {
            return $this->duration_seconds;
        }

        if ($this->connected_at && $this->ended_at) {
            return (int) $this->connected_at->diffInSeconds($this->ended_at);
        }

        return null;
    }

    public function serialize(): array
    {
        return [
            'uuid' => $this->uuid,
            'channel' => 'phone',
            'number' => $this->number,
            'label' => $this->label,
            'placed_from' => $this->placed_from,
            'placed_at' => $this->placed_at?->toIso8601String(),
            'connected_at' => $this->connected_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration_seconds' => $this->seconds(),
            'outcome' => $this->outcome,
            'outcome_label' => $this->outcome ? (self::OUTCOMES[$this->outcome] ?? $this->outcome) : null,
            'notes' => $this->notes,
            /*
             * Said plainly on every row, because a duration somebody typed
             * and a duration a network metered are not the same evidence, and
             * a column that looks identical either way invites them to be
             * read as one.
             */
            'duration_is_reported' => true,
            'caller' => $this->relationLoaded('user') && $this->user ? [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
            ] : null,
        ];
    }
}
