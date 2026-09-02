<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Newsletter extends Model
{
    use HasUuids;

    protected $table = 'crm_newsletters';

    public const AUDIENCES = ['active_clients', 'all_clients', 'leads', 'custom'];

    protected $attributes = ['status' => 'draft', 'audience' => 'active_clients'];

    protected $fillable = [
        'organization_id', 'subject', 'body', 'audience', 'custom_recipients',
        'status', 'sent_at', 'sent_count', 'failed_count', 'created_by',
    ];

    protected function casts(): array
    {
        return ['custom_recipients' => 'array', 'sent_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Whose mailbox this leaves from, when the company has one of its own. */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
