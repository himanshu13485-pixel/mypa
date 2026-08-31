<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hold or cancellation on one sale's incentive installments. Months are
 * 'YYYY-MM' anchor months — the earned month, not the payroll month.
 */
class IncentiveHold extends Model
{
    use HasUuids;

    protected $table = 'crm_incentive_holds';

    protected $fillable = [
        'organization_id', 'member_id', 'invoice_id', 'kind', 'from_month',
        'only_month', 'recover', 'status', 'released_month', 'note', 'created_by', 'released_by',
    ];

    protected function casts(): array
    {
        return ['recover' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Does this row stop the installment of anchor month $month? */
    public function blocks(string $month): bool
    {
        if ($this->only_month !== null) {
            // A one-month hold blocks only its month, until it has paid out.
            return $this->only_month === $month
                && ($this->status === 'active' || ($this->released_month !== null && $this->released_month > $month));
        }
        if ($month < $this->from_month) {
            return false;
        }
        if ($this->status === 'active') {
            return true;
        }

        // Released: months before the release stay blocked — a hold pays
        // them out as an arrear instead, and a cancel loses them for good.
        return $this->released_month !== null && $month < $this->released_month;
    }
}
