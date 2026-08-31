<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Lead extends Model
{
    use HasUuids;

    protected $table = 'crm_leads';

    public const STATUSES = ['follow_up', 'not_interested', 'unattended', 'closed', 'transferred'];
    public const TYPES = ['new', 'existing'];

    protected $fillable = [
        'organization_id', 'lead_no', 'assigned_member_id', 'company_name',
        'contact_person', 'phone', 'mobile', 'email', 'amount', 'lead_status',
        'follow_up_at', 'subject', 'requirement', 'lead_type', 'source',
        'client_id', 'created_by', 'updated_by', 'reopen_count', 'closed_at',
        'is_urgent', 'duplicate_settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'follow_up_at' => 'datetime',
            'closed_at' => 'datetime',
            'is_urgent' => 'boolean',
            'duplicate_settled_at' => 'datetime',
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function assignedMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'assigned_member_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Colleagues let in on this lead besides its owner. */
    public function sharedWith(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'crm_lead_shares', 'lead_id', 'member_id')
            ->withPivot('shared_by')
            ->withTimestamps();
    }

    public function accessRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LeadAccessRequest::class, 'lead_id');
    }

    /**
     * The person behind a lead has one mobile, one phone, one e-mail —
     * these keys are how "the same lead" is recognised, punctuation and
     * country-code noise stripped. A number keeps its last ten digits.
     */
    public static function contactKeys(?string $mobile, ?string $phone, ?string $email): array
    {
        $keys = [];
        foreach ([$mobile, $phone] as $number) {
            $digits = preg_replace('/\D+/', '', (string) $number);
            if (strlen($digits) >= 7) {
                $keys[] = 'n:' . substr($digits, -10);
            }
        }
        $mail = mb_strtolower(trim((string) $email));
        if ($mail !== '') {
            $keys[] = 'e:' . $mail;
        }

        return array_values(array_unique($keys));
    }

    public function logs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'subject')->latest();
    }

    /** Next lead number for the org. Callers must hold a transaction. */
    public static function nextNumber(int $organizationId): int
    {
        $max = static::where('organization_id', $organizationId)->lockForUpdate()->max('lead_no');

        return ($max ?? 0) + 1;
    }
}
