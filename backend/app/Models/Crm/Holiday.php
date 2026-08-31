<?php

namespace App\Models\Crm;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A day the office is shut, declared a financial year at a time. */
class Holiday extends Model
{
    use HasUuids;

    protected $table = 'crm_holidays';

    protected $fillable = [
        'organization_id', 'holiday_date', 'name', 'financial_year',
        'is_optional', 'created_by',
    ];

    protected function casts(): array
    {
        return ['holiday_date' => 'date', 'is_optional' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The Indian financial year a date belongs to, named by its start year:
     * anything from 1 April 2026 to 31 March 2027 is 2026.
     */
    public static function financialYearOf(Carbon|string $date, int $startMonth = 4): int
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        return $date->month >= $startMonth ? $date->year : $date->year - 1;
    }

    /** @return array{0: Carbon, 1: Carbon} the first and last day of that year */
    public static function financialYearRange(int $year, int $startMonth = 4): array
    {
        $start = Carbon::create($year, $startMonth, 1)->startOfDay();

        return [$start, $start->copy()->addYear()->subDay()->endOfDay()];
    }
}
