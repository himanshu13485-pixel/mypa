<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One month's payment to an offline employee. */
class OfflineSalary extends Model
{
    use HasUuids;

    protected $table = 'crm_offline_salaries';

    protected $fillable = [
        'organization_id', 'offline_employee_id', 'year', 'month', 'amount', 'paid_on', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(OfflineEmployee::class, 'offline_employee_id');
    }
}
