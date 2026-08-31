<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A client complaint, from the moment it arrives to the moment it is closed
 * and someone owns the mistake.
 */
class Complaint extends Model
{
    use HasUuids;

    protected $table = 'crm_complaints';

    /** The life of a complaint, in the words the old screen used. */
    public const STATUSES = [
        'unattended' => 'Unattended',
        'in_progress' => 'In Progress',
        'closed_satisfied' => 'Closed With Satisfaction',
        'closed_dissatisfied' => 'Closed With Dissatisfaction',
    ];

    /**
     * Who it happened because of. Fixed, because these four are the whole
     * point of the question — a company that could rename them would soon
     * have no answer at all.
     */
    public const ERROR_TYPES = [
        'common' => 'Common Error',
        'executive' => 'Executive Error',
        'client' => 'Client Error',
        'backend' => 'Backend Error',
    ];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    protected $fillable = [
        'organization_id', 'cms_no', 'complained_on', 'client_id', 'company_name',
        'contact_person', 'mobile', 'phone', 'email',
        'alt_contact_person', 'alt_mobile', 'alt_phone', 'alt_email',
        'invoice_id', 'source', 'subject', 'complaint_type', 'mode', 'details',
        'raised_by_member_id', 'allocated_by_member_id', 'allocated_to_member_id',
        'key_responsible_member_id', 'status', 'priority', 'due_at',
        'in_progress_at', 'first_response_at', 'closed_at', 'closed_by', 'resolution',
        'final_error_type', 'final_error_member_id', 'final_error_note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'complained_on' => 'date',
            'due_at' => 'datetime',
            'in_progress_at' => 'datetime',
            'first_response_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'raised_by_member_id');
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'allocated_by_member_id');
    }

    public function allocatedTo(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'allocated_to_member_id');
    }

    public function keyResponsible(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'key_responsible_member_id');
    }

    public function errorOwner(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'final_error_member_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ComplaintReply::class, 'complaint_id');
    }

    /** Screenshots, mail trails and whatever else proves the story. */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function isClosed(): bool
    {
        return str_starts_with($this->status, 'closed');
    }

    /** Past its promised time with nobody having closed it. */
    public function isOverdue(): bool
    {
        return $this->due_at !== null && ! $this->isClosed() && $this->due_at->isPast();
    }

    /**
     * The desks that may see this complaint: whoever logged it, whoever it
     * is allocated to, and whoever is answerable for it. Managers see the
     * whole floor, so this scope simply steps aside for them.
     */
    public function scopeVisibleTo($query, Member $me)
    {
        if (in_array($me->crm_role, ['admin', 'subadmin'], true)) {
            return $query;
        }

        // A Team Head answers for their people's complaints as well.
        $team = $me->teamMemberIds();

        return $query->where(fn ($q) => $q
            ->whereIn('raised_by_member_id', $team)
            ->orWhereIn('allocated_to_member_id', $team)
            ->orWhereIn('key_responsible_member_id', $team));
    }

    /** The next number in this company's own run: CMS-1, CMS-2, … */
    public static function nextNumber(int $organizationId): string
    {
        $last = static::where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->value('cms_no');

        $n = $last ? ((int) preg_replace('/\D/', '', $last)) + 1 : 1;

        return 'CMS-' . $n;
    }
}
