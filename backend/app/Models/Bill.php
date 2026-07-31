<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bill extends Model
{
    use HasUuids, SoftDeletes;

    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'half_yearly', 'yearly'];

    protected $fillable = [
        'user_id', 'group_id', 'name', 'category', 'amount', 'currency', 'due_on',
        'due_time', 'status', 'repeat_frequency', 'payment_account', 'remind_days_before',
        'remind_minutes_before', 'alarm_sent_at',
        'last_reminded_at', 'receipt_file_id', 'notes', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'last_reminded_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhereHas('group.members', fn ($m) => $m->where('users.id', $user->id));
        });
    }

    public function nextDueDate(): ?\Illuminate\Support\Carbon
    {
        if (! $this->repeat_frequency) {
            return null;
        }

        return match ($this->repeat_frequency) {
            'weekly' => $this->due_on->copy()->addWeek(),
            'monthly' => $this->due_on->copy()->addMonthNoOverflow(),
            'quarterly' => $this->due_on->copy()->addMonthsNoOverflow(3),
            'half_yearly' => $this->due_on->copy()->addMonthsNoOverflow(6),
            'yearly' => $this->due_on->copy()->addYearNoOverflow(),
            default => null,
        };
    }
}
