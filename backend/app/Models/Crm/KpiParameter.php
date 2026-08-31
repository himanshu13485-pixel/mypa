<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiParameter extends Model
{
    protected $table = 'crm_kpi_parameters';

    public const UNITS = ['count', 'percent', 'currency', 'boolean'];

    protected $fillable = ['organization_id', 'name', 'unit', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /** A starter catalog distilled from the old CRM's 78 columns. */
    public static function seedDefaults(int $organizationId): void
    {
        $defaults = [
            ['Todays Fresh Call', 'count'],
            ['Todays Connected Call', 'count'],
            ['Todays Follow-up Call', 'count'],
            ['Leads Attended', 'count'],
            ['Leads Generated', 'count'],
            ['Demo Scheduled', 'count'],
            ['Proposal Sent', 'count'],
            ['Sample Sent', 'count'],
            ['Todays Closing (INR)', 'currency'],
            ['Number of Closures', 'count'],
            ['Todays New Prospects (INR)', 'currency'],
            ['Data Collected', 'count'],
            ['Data Validated', 'count'],
            ['Invoices Generated', 'count'],
            ['Payment Follow-ups Completed', 'count'],
            ['Daily Data Dispatched (%)', 'percent'],
            ['On-Time Delivery (%)', 'percent'],
            ['Escalations Closed Same Day (%)', 'percent'],
            ['CRM Compliance (%)', 'percent'],
            ['Daily Report Submitted', 'boolean'],
        ];

        foreach ($defaults as $i => [$name, $unit]) {
            static::firstOrCreate(
                ['organization_id' => $organizationId, 'name' => $name],
                ['unit' => $unit, 'sort' => $i],
            );
        }
    }
}
