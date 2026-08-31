<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement in an employee's paid-leave account: a day earned, a day
 * spent, or days cashed out at the end of the year. The balance is the sum
 * of these rows and is never stored on its own, so it cannot drift.
 */
class LeaveLedger extends Model
{
    use HasUuids;

    protected $table = 'crm_leave_ledger';

    public const KINDS = ['credit', 'debit', 'encash'];

    protected $fillable = [
        'organization_id', 'member_id', 'financial_year', 'kind', 'days',
        'effective_on', 'period', 'leave_id', 'amount', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['effective_on' => 'date', 'days' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function leave(): BelongsTo
    {
        return $this->belongsTo(Leave::class, 'leave_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
