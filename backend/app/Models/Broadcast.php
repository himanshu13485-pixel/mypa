<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing written once, delivered as private messages.
 *
 * This row belongs to the sender and to nobody else. The recipients have
 * messages; only the person who wrote it has the fact that there were others,
 * and Message::serializeFor is where that boundary is actually enforced.
 */
class Broadcast extends Model
{
    use HasUuids;

    /**
     * How many people one broadcast may reach.
     *
     * A ceiling rather than no ceiling, because a message that arrives looking
     * like a private one is the most persuasive message there is, and a
     * feature with no limit on that is a mailing list with the disclosure
     * taken off. Set where a person can still reasonably say they know
     * everybody on the list.
     */
    public const MAX_RECIPIENTS = 50;

    protected $fillable = ['user_id', 'body', 'recipient_count'];

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

    /** The copies that were delivered. */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
