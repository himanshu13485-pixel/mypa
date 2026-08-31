<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money the company put out and expects back through the payroll. */
class Loan extends Model
{
    use HasUuids;

    protected $table = 'crm_loans';

    protected $fillable = [
        'organization_id', 'member_id', 'kind', 'amount', 'monthly_installment',
        'taken_on', 'note', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'taken_on' => 'date',
            'amount' => 'decimal:2',
            'monthly_installment' => 'decimal:2',
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

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class, 'loan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function balance(): float
    {
        return round((float) $this->amount - (float) $this->repayments()->sum('amount'), 2);
    }

    /** What this month's slip should recover: the installment, capped at what is left. */
    public function dueInstallment(): float
    {
        if ($this->status !== 'open') {
            return 0.0;
        }
        $balance = $this->balance();
        $installment = (float) $this->monthly_installment;

        // An advance with no installment set comes back whole.
        if ($installment <= 0) {
            return $balance;
        }

        return round(min($installment, max(0, $balance)), 2);
    }
}
