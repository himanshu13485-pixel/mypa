<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line said in a meeting or a screen share.
 *
 * Kept so the conversation outlives the call: somebody who joined late can
 * read what was said before they arrived, and somebody looking a week later
 * can find the link that was pasted into it.
 *
 * Nobody edits one. A transcript that can be rewritten afterwards is not a
 * transcript.
 */
class MeetingMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'meeting_id', 'user_id', 'display_name', 'body', 'to_user_id', 'meeting_file_id',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(MeetingFile::class, 'meeting_file_id');
    }

    /** A private line is the two people on it, and nobody else - host included. */
    public function visibleTo(User $user): bool
    {
        return $this->to_user_id === null
            || $this->user_id === $user->id
            || $this->to_user_id === $user->id;
    }
}
