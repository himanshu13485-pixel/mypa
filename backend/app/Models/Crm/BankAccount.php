<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    protected $table = 'crm_bank_accounts';

    protected $fillable = [
        'organization_id', 'issuing_company_id', 'label', 'bank_name', 'account_no', 'ifsc', 'is_active',
        // What a payment from abroad needs, and a note for either kind.
        'is_swift', 'beneficiary_name', 'swift_code', 'receiving_bank', 'aba_routing', 'aba_routing_alt',
        'intermediary_swift', 'account_type', 'beneficiary_address', 'receiving_bank_address', 'note',
    ];

    /**
     * The account as an invoice should print it.
     *
     * Label and value, in the order somebody paying reads them, with
     * everything blank left out - a printed "IFSC: —" on a wire transfer
     * helps nobody and makes the page look unfinished.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function payingLines(): array
    {
        $rows = $this->is_swift
            ? [
                ['Beneficiary', $this->beneficiary_name],
                ['Beneficiary address', $this->beneficiary_address],
                ['Account number', $this->account_no],
                ['Account type', $this->account_type],
                ['Receiving bank', $this->receiving_bank ?: $this->bank_name],
                ['Bank address', $this->receiving_bank_address],
                ['SWIFT / BIC', $this->swift_code],
                ['ABA routing', $this->aba_routing],
                ['ABA routing (alternate)', $this->aba_routing_alt],
                ['Intermediary bank SWIFT', $this->intermediary_swift],
            ]
            : [
                ['Bank', $this->bank_name],
                ['Account number', $this->account_no],
                ['IFSC', $this->ifsc],
            ];

        $rows[] = ['Note', $this->note];

        return collect($rows)
            ->filter(fn ($row) => filled($row[1]))
            ->map(fn ($row) => ['label' => $row[0], 'value' => (string) $row[1]])
            ->values()->all();
    }

    /**
     * The bank's own paperwork, kept with the account.
     *
     * A cancelled cheque, a bank letter, a W-9 - the papers a client asks
     * for once and then every time somebody new joins their accounts team.
     * Held here so a person emailing an invoice can tick them on rather
     * than hunting for the file again.
     */
    public function documents(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** The registered company this account belongs to, if assigned. */
    public function issuingCompany(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IssuingCompany::class, 'issuing_company_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_swift' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
