<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsPost extends Model
{
    use HasUuids;

    protected $table = 'crm_cms_posts';

    public const KINDS = ['announcement', 'policy', 'holiday', 'news'];

    protected $attributes = ['status' => 'published', 'kind' => 'announcement'];

    protected $fillable = [
        'organization_id', 'title', 'body', 'kind', 'is_pinned', 'status',
        'publish_on', 'expires_on', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'publish_on' => 'date',
            'expires_on' => 'date',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
