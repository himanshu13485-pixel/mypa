<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Client extends Model
{
    use HasUuids;

    protected $table = 'crm_clients';

    public const CATEGORIES = [
        'new', 'existing', 'global_new', 'global_existing', 'sez_new', 'sez_existing',
    ];

    protected $fillable = [
        'organization_id', 'company_name', 'title', 'contact_person', 'designation',
        'address', 'city', 'state', 'pincode', 'country', 'telephone', 'mobile',
        'email', 'alternate_email', 'website', 'gst_no', 'pan_no', 'category',
        'is_repeat', 'repeat_count',
        'assigned_member_id', 'status', 'notes', 'custom_fields', 'created_by',
    ];

    protected function casts(): array
    {
        return ['custom_fields' => 'array', 'is_repeat' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function assignedMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'assigned_member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'client_id');
    }

    /** Colleagues let in on this client besides its owner. */
    public function sharedWith(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'crm_client_shares', 'client_id', 'member_id')
            ->withPivot('shared_by')
            ->withTimestamps();
    }

    public function accessRequests(): HasMany
    {
        return $this->hasMany(ClientAccessRequest::class, 'client_id');
    }

    /**
     * The comparison key for "is this the same company?" — case, spacing and
     * punctuation are noise ("Bhavya Steel" == "BHAVYA  STEEL.").
     */
    public static function matchKey(?string $companyName): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $companyName));
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * The clients one member may see, on the same rule the list uses.
     *
     * Lifted out of ClientController so the call log can ask the identical
     * question rather than carry a second copy of the answer.
     */
    public function scopeVisibleTo($query, \App\Models\Crm\Member $me)
    {
        if (in_array($me->crm_role, ['admin', 'subadmin'], true)) {
            return $query;
        }

        $team = $me->teamMemberIds();

        return $query->where(fn ($q) => $q->whereIn('assigned_member_id', $team)
            ->orWhereHas('sharedWith', fn ($sh) => $sh->whereIn('crm_members.id', $team)));
    }
}
