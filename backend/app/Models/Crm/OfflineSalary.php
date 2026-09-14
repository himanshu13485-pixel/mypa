<?php

namespace App\Models\Crm;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month's salary slip for an offline employee.
 *
 * `amount` is the net - what is paid, and what the P&L counts as Offline
 * Salary. `structure` is the copy of the person's structure the month was
 * made from, so changing somebody's pay later never rewrites a past slip.
 */
class OfflineSalary extends Model
{
    use HasUuids;

    protected $table = 'crm_offline_salaries';

    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'organization_id', 'offline_employee_id', 'year', 'month', 'structure',
        'month_days', 'lop_days', 'payable_days', 'earnings', 'deduction_lines',
        'gross', 'additions', 'addition_note', 'deductions', 'other_deductions', 'other_deduction_note',
        'amount', 'status', 'paid_on', 'payment_mode', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'structure' => 'array',
            'earnings' => 'array',
            'deduction_lines' => 'array',
            'amount' => 'decimal:2',
            'gross' => 'decimal:2',
            'additions' => 'decimal:2',
            'deductions' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'lop_days' => 'decimal:2',
            'payable_days' => 'decimal:2',
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

    /**
     * Work the slip out from its own copy of the structure.
     *
     * Earnings are prorated by the days paid; the fixed deductions are not -
     * an advance being recovered does not shrink because somebody was away.
     * Then the hand-typed money on either side, and the net.
     */
    public function rebuild(): void
    {
        $days = (int) ($this->month_days ?: Carbon::create((int) $this->year, (int) $this->month, 1)->daysInMonth);
        $lop = min(max(0.0, (float) $this->lop_days), (float) $days);
        $payable = round($days - $lop, 2);
        $ratio = $days > 0 ? $payable / $days : 1.0;

        $snapshot = $this->structure ?: [
            'earnings' => $this->earnings ?: [['key' => 'salary', 'label' => 'Monthly salary', 'amount' => (float) $this->amount]],
            'deductions' => $this->deduction_lines ?: [],
        ];

        $line = fn (array $l, float $factor) => [
            'key' => (string) ($l['key'] ?? 'line'),
            'label' => (string) ($l['label'] ?? 'Line'),
            'amount' => round((float) ($l['amount'] ?? 0) * $factor, 2),
        ];

        $earnings = collect($snapshot['earnings'] ?? [])->map(fn ($l) => $line($l, $ratio))
            ->filter(fn ($l) => $l['amount'] > 0)->values()->all();
        $deductions = collect($snapshot['deductions'] ?? [])->map(fn ($l) => $line($l, 1.0))
            ->filter(fn ($l) => $l['amount'] > 0)->values()->all();

        $gross = round(collect($earnings)->sum('amount'), 2);
        $fixed = round(collect($deductions)->sum('amount'), 2);

        $this->fill([
            'structure' => $snapshot,
            'month_days' => $days,
            'lop_days' => $lop,
            'payable_days' => $payable,
            'earnings' => $earnings,
            'deduction_lines' => $deductions,
            'gross' => $gross,
            'deductions' => $fixed,
            'amount' => round($gross + (float) $this->additions - $fixed - (float) $this->other_deductions, 2),
        ]);
    }
}
