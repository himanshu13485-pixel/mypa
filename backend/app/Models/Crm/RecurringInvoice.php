<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One repeating bill: a source document, a cadence, and a next date. */
class RecurringInvoice extends Model
{
    use HasUuids;

    protected $table = 'crm_recurring_invoices';

    public const FREQUENCIES = ['once', 'weekly', 'monthly', 'quarterly', 'half_yearly', 'yearly'];

    public const FREQUENCY_LABELS = [
        // Not a cycle at all: one fresh copy on the chosen date, then done.
        'once' => 'One time',
        'weekly' => 'Every week',
        'monthly' => 'Every month',
        'quarterly' => 'Every 3 months',
        'half_yearly' => 'Every 6 months',
        'yearly' => 'Every year',
    ];

    protected $attributes = ['frequency' => 'monthly', 'status' => 'active', 'occurrences' => 0];

    protected $fillable = [
        'organization_id', 'source_invoice_id', 'client_id', 'member_id',
        'frequency', 'starts_on', 'next_run_on', 'ends_on', 'max_occurrences',
        'occurrences', 'counts_source', 'auto_email', 'auto_payment_link', 'show_on_document', 'status',
        'last_invoice_id', 'last_run_at', 'last_error', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'next_run_on' => 'date',
            'ends_on' => 'date',
            'counts_source' => 'boolean',
            'auto_email' => 'boolean',
            'auto_payment_link' => 'boolean',
            'show_on_document' => 'boolean',
            'last_run_at' => 'datetime',
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

    public function source(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function lastInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'last_invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The nth run date, counted from the start — never from the last run, so
     * a bill anchored to the 31st does not drift to the 28th forever after
     * one February.
     */
    public function runDate(int $occurrence): \Illuminate\Support\Carbon
    {
        $start = $this->starts_on->copy();

        return match ($this->frequency) {
            // One-time: the only run is the start date. (The guard in
            // hasRunsLeft() stops a second; the addDays keeps this function
            // monotonic so no loop can ever spin on it.)
            'once' => $start->addDays($occurrence),
            'weekly' => $start->addWeeks($occurrence),
            'quarterly' => $start->addMonthsNoOverflow(3 * $occurrence),
            'half_yearly' => $start->addMonthsNoOverflow(6 * $occurrence),
            'yearly' => $start->addYearsNoOverflow($occurrence),
            default => $start->addMonthsNoOverflow($occurrence),
        };
    }

    /**
     * The words a copy carries on its face — "Recurring · 2 of 12". When the
     * source document counts as cycle 1, the copies start at 2; a schedule
     * with no fixed end has a position but no total.
     */
    public function noteFor(int $occurrence): ?string
    {
        if (! $this->show_on_document) {
            return null;
        }

        // A one-time copy is not part of a series — it just says what it is.
        if ($this->frequency === 'once') {
            return 'Repeat of ' . ($this->source?->number ?? 'the original');
        }

        $position = $occurrence + 1 + ($this->counts_source ? 1 : 0);
        $total = $this->max_occurrences !== null
            ? $this->max_occurrences + ($this->counts_source ? 1 : 0)
            : null;

        return 'Recurring · ' . $position . ($total !== null ? ' of ' . $total : '');
    }

    /** Is there another run after this many, or is the schedule spent? */
    public function hasRunsLeft(): bool
    {
        // One time means exactly one, whatever else the row says.
        if ($this->frequency === 'once' && $this->occurrences >= 1) {
            return false;
        }
        if ($this->max_occurrences !== null && $this->occurrences >= $this->max_occurrences) {
            return false;
        }

        return $this->ends_on === null || ! $this->runDate($this->occurrences)->isAfter($this->ends_on);
    }
}
