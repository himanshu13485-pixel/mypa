<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
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
        'approval_status', 'approval_reason', 'matched_client_id',
    ];

    /**
     * On the books for real - not a record waiting for the Admin to say yes.
     *
     * A client added with a contact the company already knows under another
     * company name sits at 'pending' until somebody decides; until then it
     * cannot be billed.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', '!=', 'pending');
    }

    /** The e-mails on this record, lowercased, for "is this the same person?". */
    public static function emailsOf(array|self $client): array
    {
        $read = fn (string $f) => trim(mb_strtolower((string) (is_array($client) ? ($client[$f] ?? '') : ($client->{$f} ?? ''))));

        return array_values(array_filter([$read('email'), $read('alternate_email')]));
    }

    /**
     * The phone numbers on this record, as the last ten digits.
     *
     * "+91 93103 25393" and "9310325393" are one number, and a country code
     * or a spacing habit must not decide whether two records are one person.
     */
    public static function phonesOf(array|self $client): array
    {
        $read = function (string $f) use ($client) {
            $raw = preg_replace('/\D/', '', (string) (is_array($client) ? ($client[$f] ?? '') : ($client->{$f} ?? '')));

            return strlen((string) $raw) >= 7 ? substr((string) $raw, -10) : '';
        };

        return array_values(array_filter([$read('mobile'), $read('telephone')]));
    }

    /**
     * Every way somebody might look for a client.
     *
     * Whoever raises an invoice has whatever the enquiry gave them — a
     * company name, a person, an e-mail, a mobile, a GST number — and any of
     * them should find the record. The Clients screen and the picker on a new
     * invoice both ask through here, so a client findable on one is findable
     * on the other; they used to search different sets of fields.
     *
     * Telephone and the alternate e-mail are in because the number on a
     * letterhead is rarely the mobile, and accounts departments write from
     * an address nobody put in the main field.
     */
    public function scopeMatching(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            foreach ([
                'company_name', 'contact_person', 'email', 'alternate_email',
                'mobile', 'telephone', 'gst_no', 'city',
            ] as $field) {
                $q->orWhere($field, 'like', '%' . $term . '%');
            }
        });
    }

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

    /** The client whose contact details this one matched, when it needed a nod. */
    public function matchedClient(): BelongsTo
    {
        return $this->belongsTo(self::class, 'matched_client_id');
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
