<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalarySlip extends Model
{
    use HasUuids;

    protected $table = 'crm_salary_slips';

    protected $fillable = [
        'organization_id', 'member_id', 'year', 'month', 'monthly_salary', 'month_days', 'payable_days', 'lop_days',
        'earnings', 'deduction_lines', 'incentive_amount', 'incentive_breakdown',
        'incentive_month', 'net_without_incentive',
        'payable', 'additions', 'deductions', 'deduction_note', 'net_salary',
        'bank_name', 'account_holder', 'account_no', 'ifsc', 'status',
        'paid_on', 'payment_mode', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'monthly_salary' => 'decimal:2',
            'payable' => 'decimal:2',
            'additions' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_salary' => 'decimal:2',
            'net_without_incentive' => 'decimal:2',
            'incentive_amount' => 'decimal:2',
            'earnings' => 'array',
            'deduction_lines' => 'array',
            'incentive_breakdown' => 'array',
            'paid_on' => 'date',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
