<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Somebody the company pays who is not on the rolls - no account, no seat.
 * They have a salary structure of their own and payslips like anyone else,
 * but their pay is counted in the P&L as Offline Salary, never in the payroll.
 */
class OfflineEmployee extends Model
{
    use HasUuids;

    protected $table = 'crm_offline_employees';

    protected $attributes = ['status' => 'active'];

    protected $fillable = [
        'organization_id', 'employee_code', 'name', 'designation', 'joined_on', 'monthly_amount', 'structure',
        'bank_name', 'account_holder', 'account_no', 'ifsc', 'status', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'monthly_amount' => 'decimal:2',
            'structure' => 'array',
            'joined_on' => 'date',
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

    /**
     * What they earn a month, line by line. Somebody set up before structures
     * existed has one line: the monthly amount.
     *
     * @return list<array{key: string, label: string, amount: float}>
     */
    public function earningLines(): array
    {
        $lines = collect($this->structure['earnings'] ?? [])
            ->filter(fn ($l) => (float) ($l['amount'] ?? 0) > 0)
            ->map(fn ($l) => ['key' => (string) ($l['key'] ?? 'line'), 'label' => (string) $l['label'], 'amount' => round((float) $l['amount'], 2)])
            ->values()->all();

        return $lines ?: [['key' => 'salary', 'label' => 'Monthly salary', 'amount' => round((float) $this->monthly_amount, 2)]];
    }

    /** @return list<array{key: string, label: string, amount: float}> */
    public function deductionLines(): array
    {
        return collect($this->structure['deductions'] ?? [])
            ->filter(fn ($l) => (float) ($l['amount'] ?? 0) > 0)
            ->map(fn ($l) => ['key' => (string) ($l['key'] ?? 'line'), 'label' => (string) $l['label'], 'amount' => round((float) $l['amount'], 2)])
            ->values()->all();
    }
}
