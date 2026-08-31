<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one employee earns above the salary. Everyone has a plan — the plan
 * may be "none" — and each is a dated row, so changing a slab never
 * rewrites the months already paid under the old one.
 */
class IncentivePlan extends Model
{
    use HasUuids;

    protected $table = 'crm_incentive_plans';

    public const KINDS = [
        'none' => 'No incentive',
        'flat_percent' => 'Flat % of effective sale',
        'slab' => 'Slab rates by sale band',
        'percent_minus_base' => '% of sale minus a base amount',
        // The subscription-safe shape: the sale's incentive is divided over
        // N months instead of paid in one go, so a client who cancels — or
        // an executive who leaves — stops costing incentive that the sale
        // never earned.
        'spread' => '% of sale, spread over months',
    ];

    protected $fillable = [
        'member_id', 'effective_from', 'kind', 'config',
        'release_offset_months', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'config' => 'array'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
