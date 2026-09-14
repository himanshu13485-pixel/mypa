<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody the company pays who is not on the rolls - no account, no seat,
 * no salary structure. Counted in the P&L, never in the payroll.
 */
class OfflineEmployee extends Model
{
    use HasUuids;

    protected $table = 'crm_offline_employees';

    protected $attributes = ['status' => 'active'];

    protected $fillable = [
        'organization_id', 'employee_code', 'name', 'monthly_amount', 'status', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'monthly_amount' => 'decimal:2',
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

    public function salaries(): HasMany
    {
        return $this->hasMany(OfflineSalary::class, 'offline_employee_id');
    }
}
