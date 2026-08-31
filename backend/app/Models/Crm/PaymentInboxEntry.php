<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentInboxEntry extends Model
{
    use HasUuids;

    protected $table = 'crm_payment_inbox';

    // In-memory defaults: a freshly created entry must serialise correctly
    // without a refetch (DB defaults only exist after a round-trip).
    protected $attributes = ['status' => 'unclaimed', 'currency' => 'INR'];

    protected $fillable = [
        'organization_id', 'received_on', 'issuing_company_id', 'bank_account_id',
        'payment_mode', 'amount', 'currency', 'details', 'reference_no', 'status',
        'claimed_invoice_id', 'invoice_payment_id', 'claimed_member_id',
        'claimed_by', 'claimed_at', 'note', 'created_by',
        'settlement_mode', 'settled_by', 'settled_at', 'source_proforma_id',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'amount' => 'decimal:2',
            'claimed_at' => 'datetime',
            'settled_at' => 'datetime',
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

    public function issuingCompany(): BelongsTo
    {
        return $this->belongsTo(IssuingCompany::class, 'issuing_company_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function claimedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'claimed_invoice_id');
    }

    public function invoicePayment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }

    public function claimedMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'claimed_member_id');
    }

    /** The proforma the money came in against, once it became an invoice. */
    public function sourceProforma(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_proforma_id');
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }
}
