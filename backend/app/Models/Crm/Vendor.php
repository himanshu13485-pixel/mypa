<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier the company buys from. Registered once, exactly as a client is,
 * so every bill points at one record instead of a name typed afresh each
 * time — which is what makes "what do we owe them?" answerable at all.
 */
class Vendor extends Model
{
    use HasUuids;

    protected $table = 'crm_vendors';

    protected $fillable = [
        'organization_id', 'company_name', 'contact_person', 'designation', 'address',
        'city', 'state', 'pincode', 'country', 'telephone', 'mobile', 'email',
        'website', 'gst_no', 'pan_no', 'category', 'payment_terms_days',
        'bank_name', 'bank_account_no', 'bank_ifsc', 'bank_branch',
        'status', 'notes', 'created_by',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'vendor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The comparison key for "is this the same supplier?" — case, spacing
     * and punctuation are noise, the same rule clients are matched by.
     */
    public static function matchKey(?string $companyName): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $companyName));
    }
}
