<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePayment extends Model
{
    protected $table = 'crm_invoice_payments';

    protected $fillable = [
        'invoice_id', 'payment_no', 'amount', 'charge_amount', 'charge_note', 'charge_expense_id',
        'amount_fx', 'bank_account_id', 'payment_mode',
        'reference_no', 'drawee_bank', 'instrument_date', 'received_at', 'note',
        'created_by',
    ];

    protected static function booted(): void
    {
        // Every receipt gets its unique payment id the moment it exists —
        // the handle that ties a bank-statement line to its invoice.
        static::created(function (self $payment) {
            if (! $payment->payment_no) {
                $payment->forceFill([
                    'payment_no' => 'PAY-' . str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
                ])->saveQuietly();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'charge_amount' => 'decimal:2',
            'amount_fx' => 'decimal:2',
            'instrument_date' => 'date',
            'received_at' => 'date',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /**
     * What actually reached the bank: the client's gross payment less what
     * the gateway or bank kept. Arithmetic, never stored — two numbers that
     * must agree are one number too many.
     */
    public function netAmount(): float
    {
        return round((float) $this->amount - (float) $this->charge_amount, 2);
    }

    /** The cost of taking the money, booked as an expense of its own. */
    public function chargeExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'charge_expense_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
