<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank document kept with the payroll month it belongs to.
 *
 * Addressed by uuid rather than id, like everything else a URL can name, so
 * the count of a company's paperwork is not written into its links.
 */
class SalaryDocument extends Model
{
    use HasUuids;

    protected $table = 'crm_salary_documents';

    protected $fillable = [
        'organization_id', 'year', 'month', 'member_id',
        'name', 'path', 'mime', 'size', 'note', 'uploaded_by',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
