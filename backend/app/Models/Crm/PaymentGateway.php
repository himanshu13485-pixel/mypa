<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One company's payment gateway account. The secret is encrypted at rest and
 * never leaves the server — the UI only ever sees whether one is on file.
 */
class PaymentGateway extends Model
{
    protected $table = 'crm_payment_gateways';

    protected $attributes = ['provider' => 'cashfree', 'mode' => 'sandbox', 'is_active' => false];

    protected $fillable = ['organization_id', 'provider', 'mode', 'app_id', 'secret', 'is_active'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'is_active' => 'boolean'];
    }

    protected $hidden = ['secret'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /** Live only when it is switched on and both halves are on file. */
    public function isUsable(): bool
    {
        return $this->is_active && filled($this->app_id) && filled($this->secret);
    }

    public function baseUrl(): string
    {
        return $this->mode === 'production'
            ? 'https://api.cashfree.com/pg'
            : 'https://sandbox.cashfree.com/pg';
    }
}
