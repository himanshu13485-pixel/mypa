<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Meeting extends Model
{
    use HasUuids;

    protected $fillable = [
        'host_id', 'code', 'title', 'type', 'is_screen', 'status', 'scheduled_at', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'is_screen' => 'boolean',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** Meetings are addressed by their shareable code. */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** Meet-style join code: xxx-xxxx-xxx (unambiguous lowercase letters). */
    public static function generateCode(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz';
        $part = fn (int $len) => collect(range(1, $len))
            ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
            ->implode('');

        do {
            $code = $part(3) . '-' . $part(4) . '-' . $part(3);
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_participants')
            ->withPivot(['status', 'display_name', 'joined_at', 'left_at'])
            ->withTimestamps();
    }
}
