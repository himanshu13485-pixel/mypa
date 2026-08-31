<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    protected $table = 'crm_bank_accounts';

    protected $fillable = ['organization_id', 'issuing_company_id', 'label', 'bank_name', 'account_no', 'ifsc', 'is_active'];

    /** The registered company this account belongs to, if assigned. */
    public function issuingCompany(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IssuingCompany::class, 'issuing_company_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
