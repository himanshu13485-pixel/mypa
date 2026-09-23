<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One address this person has written to, or heard from.
 *
 * The name is the best one seen so far, and a name somebody typed
 * themselves is never overruled by one that arrives in a message - a
 * supplier who signs their mail "Accounts Dept" does not get to rename the
 * contact somebody carefully called "Ravi at Bharat Steel".
 */
class MailContact extends Model
{
    use HasUuids;

    protected $table = 'crm_mail_contacts';

    protected $fillable = [
        'organization_id', 'member_id', 'email', 'name', 'name_is_mine',
        'sent_count', 'received_count', 'last_used_at', 'is_blocked', 'note',
    ];

    protected function casts(): array
    {
        return [
            'name_is_mine' => 'boolean',
            'is_blocked' => 'boolean',
            'last_used_at' => 'datetime',
            'sent_count' => 'integer',
            'received_count' => 'integer',
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

    /**
     * Remember an address, and the name it came with.
     *
     * `$sent` says which way the mail went, which is what makes somebody
     * written to rank above somebody merely heard from. A blank name never
     * replaces one already known, and a name somebody set by hand is left
     * alone whatever arrives.
     */
    public static function remember(Member $member, string $email, ?string $name, bool $sent): ?self
    {
        $address = mb_strtolower(trim($email));
        if ($address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $contact = self::firstOrNew(['member_id' => $member->id, 'email' => $address]);
        $contact->organization_id ??= $member->organization_id;
        $contact->member_id = $member->id;

        $clean = trim((string) $name);
        // A name that is just the address again teaches nobody anything.
        if ($clean !== '' && mb_strtolower($clean) !== $address && ! $contact->name_is_mine) {
            $contact->name = mb_substr($clean, 0, 200);
        }

        $contact->sent_count += $sent ? 1 : 0;
        $contact->received_count += $sent ? 0 : 1;
        $contact->last_used_at = Carbon::now();
        $contact->save();

        return $contact;
    }

    /** "Kunal Chaudhari <kunal@bcg.com>", the way a mail header writes it. */
    public function label(): string
    {
        return $this->name ? $this->name . ' <' . $this->email . '>' : $this->email;
    }

    public function serialize(): array
    {
        return [
            'uuid' => $this->uuid,
            'email' => $this->email,
            'name' => $this->name,
            'label' => $this->label(),
            'name_is_mine' => $this->name_is_mine,
            'sent_count' => $this->sent_count,
            'received_count' => $this->received_count,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'is_blocked' => $this->is_blocked,
            'note' => $this->note,
        ];
    }
}
