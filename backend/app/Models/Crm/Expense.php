<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Expense extends Model
{
    use HasUuids;

    protected $table = 'crm_expenses';

    protected $fillable = [
        'organization_id', 'expense_date', 'due_date', 'issuing_company_id', 'invoice_id',
        'vendor_id', 'vendor_name', 'vendor_gstin', 'category', 'description',
        'base_amount', 'cgst_amount', 'sgst_amount', 'igst_amount',
        'cgst_rate', 'sgst_rate', 'igst_rate',
        'other_tax_label', 'other_tax_rate', 'other_tax_amount', 'total_amount',
        'amount_paid', 'payment_status', 'bill_available',
        'gst_claimed', 'payment_mode', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'due_date' => 'date',
            'base_amount' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'cgst_rate' => 'decimal:3',
            'sgst_rate' => 'decimal:3',
            'igst_rate' => 'decimal:3',
            'other_tax_rate' => 'decimal:3',
            'other_tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'bill_available' => 'boolean',
            'gst_claimed' => 'boolean',
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

    /** The supplier this bill belongs to. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class, 'expense_id');
    }

    /** What is still owed on this bill. */
    public function balance(): float
    {
        return round((float) $this->total_amount - (float) $this->amount_paid, 2);
    }

    /**
     * Re-read what has actually gone out and restate the bill's standing.
     * The status is never typed — it follows the payment rows, so removing
     * a wrong entry puts the bill straight back where it belongs.
     */
    public function recomputePayment(): void
    {
        $paid = round((float) $this->payments()->sum('amount'), 2);
        $total = round((float) $this->total_amount, 2);

        $this->update([
            'amount_paid' => $paid,
            'payment_status' => $paid <= 0 ? 'unpaid' : ($paid + 0.01 >= $total ? 'paid' : 'part'),
        ]);
    }

    /** Past its due date with money still owed. */
    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->payment_status !== 'paid'
            && $this->due_date->isPast();
    }

    /** The sale this money went out against — set for commissions. */
    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function issuingCompany(): BelongsTo
    {
        return $this->belongsTo(IssuingCompany::class, 'issuing_company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Bill scans ride the shared CRM document store. */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
