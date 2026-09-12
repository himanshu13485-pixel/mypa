<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One birthday wish, from one person to one person, for one year - and the
 * thank-you that came back.
 */
class BirthdayWish extends Model
{
    use HasUuids;

    protected $table = 'crm_birthday_wishes';

    protected $fillable = [
        'organization_id', 'from_member_id', 'to_member_id', 'birthday_year',
        'message', 'reply', 'replied_at', 'seen_at',
    ];

    protected function casts(): array
    {
        return [
            'replied_at' => 'datetime',
            'seen_at' => 'datetime',
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

    public function from(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'from_member_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'to_member_id');
    }
}
