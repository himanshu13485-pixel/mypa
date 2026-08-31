<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One table for both document kinds: a proforma is an invoice that hasn't
 * been promised to the tax man yet. Converting keeps the proforma and links
 * the new tax invoice back to it, exactly like the old CRM's PI flow.
 */
class Invoice extends Model
{
    use HasUuids;

    protected $table = 'crm_invoices';

    public const KINDS = ['proforma', 'invoice'];
    public const PAYMENT_STATUSES = ['due', 'partial', 'paid', 'refunded', 'credit_note', 'bad_debt'];
    public const DISPATCH_STATUSES = ['pending', 'partial', 'dispatched', 'in_process'];
    public const STATUSES = ['draft', 'final', 'cancelled'];

    protected $fillable = [
        'organization_id', 'kind', 'number', 'issuing_company_id', 'client_id',
        'member_id', 'invoice_date', 'due_date', 'client_category', 'pricing_tier',
        'currency', 'terms_of_payment', 'subscription_type', 'subtotal', 'discount',
        'cgst', 'sgst', 'igst', 'other_tax', 'tds', 'total', 'fx_currency',
        'discount_rate', 'cgst_rate', 'sgst_rate', 'igst_rate', 'other_tax_rate', 'tds_rate',
        'fx_rate', 'subtotal_fx', 'total_fx', 'payment_status', 'dispatch_status',
        'status', 'notes', 'custom_fields', 'converted_from_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'cgst' => 'decimal:2',
            'sgst' => 'decimal:2',
            'igst' => 'decimal:2',
            'other_tax' => 'decimal:2',
            'tds' => 'decimal:2',
            'total' => 'decimal:2',
            'discount_rate' => 'decimal:3',
            'cgst_rate' => 'decimal:3',
            'sgst_rate' => 'decimal:3',
            'igst_rate' => 'decimal:3',
            'other_tax_rate' => 'decimal:3',
            'tds_rate' => 'decimal:3',
            'fx_rate' => 'decimal:4',
            'subtotal_fx' => 'decimal:2',
            'total_fx' => 'decimal:2',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * A member's own ledger: managers see the whole company, everybody else
     * sees the documents credited to their team (an ordinary employee's team
     * is just themselves) plus anything they raised before the salesperson
     * was recorded automatically.
     */
    public function scopeVisibleTo($query, Member $member)
    {
        if (in_array($member->crm_role, ['admin', 'subadmin'], true)) {
            return $query;
        }

        $team = $member->teamMemberIds();

        // Older documents carry no salesperson — before attribution became
        // automatic — so the fallback is who RAISED them, for the whole
        // subtree, not just the head themselves.
        return $query->where(fn ($q) => $q->whereIn('member_id', $team)
            ->orWhere(fn ($w) => $w->whereNull('member_id')
                ->whereIn('created_by', $member->teamUserIds())));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function issuingCompany(): BelongsTo
    {
        return $this->belongsTo(IssuingCompany::class, 'issuing_company_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /** Office talk about this document — never printed on it. */
    public function internalNotes(): HasMany
    {
        return $this->hasMany(InvoiceNote::class, 'invoice_id');
    }

    /** The "pay online" links raised against this document. */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class, 'invoice_id');
    }

    /** Every time this invoice has been chased. */
    public function reminders(): HasMany
    {
        return $this->hasMany(PaymentReminder::class, 'invoice_id');
    }

    /** This document's money lines, in the company's own setup. */
    public function taxes(): HasMany
    {
        return $this->hasMany(InvoiceTax::class, 'invoice_id')->orderBy('sort');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id')->orderBy('sort');
    }

    /** The schedule that raised this copy, when one did. */
    public function recurringSchedule(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class, 'recurring_invoice_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class, 'invoice_id')->orderByDesc('received_at');
    }

    public function convertedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'converted_from_id');
    }

    public function convertedTo(): HasOne
    {
        return $this->hasOne(self::class, 'converted_from_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function amountReceived(): string
    {
        return (string) $this->payments()->sum('amount');
    }

    /** Recompute payment_status from what has actually been received. */
    public function refreshPaymentStatus(): void
    {
        // Manual terminal states (refund, credit note, bad debt) are set by a
        // person and never overwritten by arithmetic.
        if (in_array($this->payment_status, ['refunded', 'credit_note', 'bad_debt'], true)) {
            return;
        }

        $received = (float) $this->payments()->sum('amount');
        $total = (float) $this->total;

        $this->update(['payment_status' => match (true) {
            $received <= 0 => 'due',
            $received + 0.01 < $total => 'partial',
            default => 'paid',
        }]);
    }
}
