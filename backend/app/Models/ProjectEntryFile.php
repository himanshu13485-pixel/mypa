<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The paperwork behind one line of a project's ledger.
 *
 * A bill, a receipt, a photograph of the delivery, the signed measurement
 * sheet - whatever proves the entry. Kept with the entry rather than in
 * somebody's phone, and carried out with the daily report when the entry it
 * belongs to has changed.
 */
class ProjectEntryFile extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'project_entry_id', 'name', 'path', 'mime', 'size', 'uploaded_by',
    ];

    /** Never handed out: where a file sits on disk is the server's business. */
    protected $hidden = ['path'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ProjectEntry::class, 'project_entry_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function serialize(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'mime' => $this->mime,
            'size' => $this->size,
            'created_at' => $this->created_at,
        ];
    }
}
