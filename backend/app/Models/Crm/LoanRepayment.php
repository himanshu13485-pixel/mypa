<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One piece of a loan coming back — off a payslip, or in cash. */
class LoanRepayment extends Model
{
    protected $table = 'crm_loan_repayments';

    protected $fillable = [
        'loan_id', 'salary_slip_id', 'amount', 'repaid_on', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['repaid_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'loan_id');
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(SalarySlip::class, 'salary_slip_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
