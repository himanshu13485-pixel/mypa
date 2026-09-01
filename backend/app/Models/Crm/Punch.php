<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Punch extends Model
{
    protected $table = 'crm_punches';

    public const STATUSES = ['present', 'late', 'half_day', 'sunday', 'holiday', 'absent'];

    protected $fillable = [
        'organization_id', 'member_id', 'work_date', 'punch_in', 'punch_out',
        'in_ip', 'out_ip', 'in_device', 'out_device',
        'in_lat', 'in_lng', 'in_distance_m',
        'status', 'status_source', 'note',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'punch_in' => 'datetime',
            'punch_out' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /** Worked hours so far, or null before punch-in. */
    public function hours(): ?float
    {
        if (! $this->punch_in || ! $this->punch_out) {
            return null;
        }

        return round($this->punch_in->diffInMinutes($this->punch_out) / 60, 2);
    }
}
