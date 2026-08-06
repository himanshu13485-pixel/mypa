<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id', 'folder_id', 'group_id', 'name', 'path', 'mime_type', 'size',
        'share_token', 'share_expires_at', 'shared_at',
    ];

    /* share_token is hidden for the same reason path is: it is a capability.
       Anyone holding it can download the file, so it is returned only from the
       endpoint that mints it, never incidentally in a listing. */
    protected $hidden = ['path', 'share_token'];

    protected function casts(): array
    {
        return [
            'share_expires_at' => 'datetime',
            'shared_at' => 'datetime',
        ];
    }

    /** A link exists and has not lapsed. */
    public function linkIsLive(): bool
    {
        return $this->share_token !== null
            && ($this->share_expires_at === null || $this->share_expires_at->isFuture());
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

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function sharedWith(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'file_shares')
            ->withPivot('permission')
            ->withTimestamps();
    }

    /** Files a user can access: own + shared + shared-folder + group files. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhereHas('sharedWith', fn ($s) => $s->where('users.id', $user->id))
                ->orWhereHas('folder.sharedWith', fn ($s) => $s->where('users.id', $user->id))
                ->orWhereHas('group.members', fn ($m) => $m->where('users.id', $user->id));
        });
    }
}
