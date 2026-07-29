<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'conversation_id', 'user_id', 'type', 'body', 'reply_to_id', 'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function deletions(): HasMany
    {
        return $this->hasMany(MessageDeletion::class);
    }

    public function serializeFor(User $viewer): array
    {
        $deletedForEveryone = $this->trashed();

        return [
            'uuid' => $this->uuid,
            'type' => $deletedForEveryone ? 'text' : $this->type,
            'body' => $deletedForEveryone ? null : $this->body,
            'is_deleted' => $deletedForEveryone,
            'is_own' => $this->user_id === $viewer->id,
            'sender' => $this->relationLoaded('user') && $this->user ? [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
            ] : null,
            'reply_to' => $this->relationLoaded('replyTo') && $this->replyTo ? [
                'uuid' => $this->replyTo->uuid,
                'body' => $this->replyTo->trashed() ? null : str($this->replyTo->body ?? '')->limit(80)->toString(),
                'sender_name' => $this->replyTo->user?->name,
            ] : null,
            'attachments' => $deletedForEveryone ? [] : $this->attachments->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'mime_type' => $a->mime_type,
                'size' => $a->size,
                'duration_seconds' => $a->duration_seconds,
            ]),
            'reactions' => $this->reactions
                ->groupBy('emoji')
                ->map(fn ($group, $emoji) => [
                    'emoji' => $emoji,
                    'count' => $group->count(),
                    'mine' => $group->contains('user_id', $viewer->id),
                ])->values(),
            'edited_at' => $this->edited_at,
            'created_at' => $this->created_at,
        ];
    }
}
