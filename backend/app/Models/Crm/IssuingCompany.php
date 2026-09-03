<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A billing entity the organization raises invoices from. Each keeps its own
 * numbering counters so INV / PI series never collide across companies.
 */
class IssuingCompany extends Model
{
    protected $table = 'crm_issuing_companies';

    protected $fillable = [
        'organization_id', 'name', 'address', 'gstin', 'pan', 'state_code',
        'phone', 'email', 'invoice_prefix', 'proforma_prefix',
        'next_invoice_no', 'next_proforma_no', 'is_active',
        'logo_path', 'stamp_path', 'currency', 'pays_salary',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'pays_salary' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * Claim the next number in the series. Callers must hold a transaction;
     * the row lock makes two simultaneous saves take consecutive numbers
     * instead of the same one.
     */
    public function claimNumber(string $kind): string
    {
        $fresh = self::whereKey($this->id)->lockForUpdate()->first();

        if ($kind === 'invoice') {
            $number = $fresh->invoice_prefix . $fresh->next_invoice_no;
            $fresh->increment('next_invoice_no');
        } else {
            $number = $fresh->proforma_prefix . $fresh->next_proforma_no;
            $fresh->increment('next_proforma_no');
        }

        return $number;
    }
}
