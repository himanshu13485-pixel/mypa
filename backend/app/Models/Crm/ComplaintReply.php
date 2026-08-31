<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in a complaint's conversation. `client` is what the client is
 * told; `internal` is the office talking among itself, and no screen the
 * client can reach ever renders it.
 */
class ComplaintReply extends Model
{
    use HasUuids;

    protected $table = 'crm_complaint_replies';

    public const AUDIENCES = ['client', 'internal'];

    protected $fillable = ['complaint_id', 'member_id', 'audience', 'body'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class, 'complaint_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
