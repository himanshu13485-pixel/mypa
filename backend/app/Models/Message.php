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

    /**
     * How long a sent message stays editable.
     *
     * Named here rather than typed into the controller and the client
     * separately: the button that disappears and the request that is refused
     * have to agree, or one of them is lying to somebody.
     */
    public const EDIT_WINDOW_MINUTES = 60;

    protected $fillable = [
        'conversation_id', 'user_id', 'type', 'body', 'reply_to_id', 'edited_at',
        'is_forwarded', 'pinned_at', 'pinned_by_id', 'broadcast_id',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'is_forwarded' => 'boolean',
            'pinned_at' => 'datetime',
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

    /** Everyone who kept this message. Private to each of them. */
    public function stars(): HasMany
    {
        return $this->hasMany(MessageStar::class);
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_id');
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

    /**
     * The one send this copy came out of, when it came out of one.
     *
     * Null for every ordinary message, and never shown to anybody but the
     * sender — see the note in serializeFor.
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
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

    /**
     * @param  \Illuminate\Support\Carbon|null  $othersReadAt  How far the OTHER
     *   members have read to — the earliest of their last_read_at, so in a
     *   group a message counts as read only once everyone has seen it. Null
     *   means at least one of them has never opened the conversation.
     */
    public function serializeFor(User $viewer, $othersReadAt = null): array
    {
        $deletedForEveryone = $this->trashed();

        return [
            // Drives the tick on your own messages. It used to be hardcoded to
            // a double tick, which meant every message you ever sent looked
            // like it had been read.
            'read_by_others' => $othersReadAt !== null && $this->created_at <= $othersReadAt,
            'uuid' => $this->uuid,
            'type' => $deletedForEveryone ? 'text' : $this->type,
            'body' => $deletedForEveryone ? null : $this->body,
            'is_deleted' => $deletedForEveryone,
            'is_own' => $this->user_id === $viewer->id,
            /*
             * Passed on, not written here.
             *
             * "The meeting is cancelled" carries one weight from the person
             * who decided it and another from somebody relaying it, and a
             * forward reads exactly like the first unless it is marked.
             */
            'is_forwarded' => (bool) $this->is_forwarded,
            /*
             * Starred is answered for the viewer alone. Whether somebody else
             * kept this message is their business, and saying so would turn a
             * private bookmark into a public one.
             */
            'is_starred' => $this->relationLoaded('stars')
                ? $this->stars->contains('user_id', $viewer->id)
                : false,
            'pinned_at' => $this->pinned_at?->toIso8601String(),
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
            /*
             * Only ever answered for the person who sent it.
             *
             * This is the whole privacy contract of a broadcast, and it lives
             * here rather than in the controller on purpose: every screen in
             * the app reads its messages through this one method, so a screen
             * written next year cannot forget to strip it.
             *
             * A recipient's copy is a message from somebody they know, in
             * their own conversation, and it stays exactly that — the count is
             * null for them whether or not the relation happens to be loaded.
             * Their reply comes back to the sender alone; there is no room to
             * be in and no one else's name to leak, which is why the count
             * being private is the only secret there is to keep.
             */
            'broadcast_to' => $this->user_id === $viewer->id && $this->broadcast_id
                && $this->relationLoaded('broadcast')
                ? $this->broadcast?->recipient_count
                : null,
            'edited_at' => $this->edited_at,
            'created_at' => $this->created_at,
        ];
    }
}
