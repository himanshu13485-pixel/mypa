<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated CTC structure. A raise starts a new row; old payslips keep the
 * structure they were computed under.
 */
class SalaryStructure extends Model
{
    use HasUuids;

    protected $table = 'crm_salary_structures';

    /** The allowance heads the company's sheet uses, offered by default. */
    public const COMPONENT_LABELS = [
        'fix_allowance' => 'Fix Allowance / Incentive',
        'conveyance' => 'Conveyance Allowance',
        'medical' => 'Medical Allowance',
        'telephone' => 'Telephone Allowance',
        'entertainment' => 'Entertainment Allowance',
        'lta' => 'LTA',
        'special' => 'Special Allowance',
        'other' => 'Other Allowance',
    ];

    protected $fillable = [
        'member_id', 'effective_from', 'ctc_monthly', 'basic', 'hra',
        'components', 'has_pf', 'has_edli', 'has_esi', 'has_welfare', 'pt_amount',
        'tds_monthly', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'ctc_monthly' => 'decimal:2',
            'basic' => 'decimal:2',
            'hra' => 'decimal:2',
            'components' => 'array',
            'has_pf' => 'boolean',
            'has_edli' => 'boolean',
            'has_esi' => 'boolean',
            'has_welfare' => 'boolean',
            'pt_amount' => 'decimal:2',
            'tds_monthly' => 'decimal:2',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Basic + HRA + every component: the whole monthly gross. */
    public function grossMonthly(): float
    {
        return round((float) $this->basic + (float) $this->hra
            + collect($this->components ?? [])->sum(fn ($v) => (float) $v), 2);
    }
}
