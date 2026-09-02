<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'username',
        'email',
        'mobile',
        'country_code',
        'password',
        'status',
        'force_password_change',
        'last_login_at',
        'username_changed_at',
        'mobile_verified_at',
        'guest_meeting_id',
        'guest_expires_at',
        'guest_token',
    ];

    /**
     * Guests are hidden from every ordinary query.
     *
     * A meeting guest is a real user row so that the participant pivot,
     * presence and signalling all keep working — but they must never surface
     * anywhere a person is looked up: not in connection suggestions, not in
     * group member search, not in an admin list. Doing that here rather than
     * in each query means a query written later cannot forget.
     *
     * The one place that needs them is authentication of the guest itself,
     * which asks for them explicitly with withoutGlobalScope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('withoutMeetingGuests', function (\Illuminate\Database\Eloquent\Builder $q) {
            $q->whereNull($q->getModel()->getTable().'.guest_meeting_id');
        });
    }

    /** Someone who joined a single meeting by link, with no account. */
    public function isGuest(): bool
    {
        return $this->guest_meeting_id !== null;
    }

    /** Their pass has run out — they cannot rejoin or keep signalling. */
    public function guestPassExpired(): bool
    {
        return $this->isGuest()
            && $this->guest_expires_at !== null
            && $this->guest_expires_at->isPast();
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mobile_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'force_password_change' => 'boolean',
            'username_changed_at' => 'datetime',
            'password' => 'hashed',
            'guest_expires_at' => 'datetime',
            'last_active_at' => 'datetime',
            'presence_updated_at' => 'datetime',
            // Deliberately not fillable: this is set by mypa:service-account,
            // never by anything a request can reach.
            'is_service_account' => 'boolean',
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

    // --- Relations -----------------------------------------------------------

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function appId(): HasOne
    {
        return $this->hasOne(AppId::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    /** The staff member who looks after this user commercially. */
    public function salesperson(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'salesperson_id');
    }

    public function assignedUsers(): HasMany
    {
        return $this->hasMany(self::class, 'salesperson_id');
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /** Long enough to ride out a poll gap, short enough to mean "now". */
    public const ONLINE_WITHIN_SECONDS = 120;

    /** Idle this long and the dot turns amber. */
    public const AWAY_AFTER_SECONDS = 180;

    /** Idle this long and they are treated as gone. */
    public const OFFLINE_AFTER_SECONDS = 600;

    /**
     * How long a browser's own account of itself is believed.
     *
     * The client beats every 45 seconds, so a report older than this means the
     * beating stopped — asleep, crashed, or the tab was killed without the
     * closing beacon getting out — and the answer falls back to request
     * traffic. Without the expiry a laptop lid closing would leave somebody
     * green until they next opened the app.
     */
    public const HEARTBEAT_TRUSTED_SECONDS = 150;

    public const PRESENCE_STATES = ['online', 'away', 'offline'];

    /**
     * Where this person is, in three words: online, away, or offline.
     *
     * The browser is asked first, because it is the only thing that can tell
     * the difference between a person reading and a person who left the tab
     * open and went home — both look identical from the server, where a chat
     * screen polls all night either way. It reports 'away' once nobody has
     * touched it, 'offline' once that has gone on long enough, and 'offline'
     * again through a closing beacon as the tab goes.
     *
     * Its word is only taken while it is fresh. A silent client decays through
     * last_active_at instead, which is the same ladder measured in requests:
     * something recent means online, a few minutes of nothing means away, and
     * ten means they are gone.
     */
    public function presenceState(): string
    {
        $reported = in_array($this->presence_state, self::PRESENCE_STATES, true)
            ? $this->presence_state
            : null;

        if (
            $reported
            && $this->presence_updated_at
            && $this->presence_updated_at->gt(now()->subSeconds(self::HEARTBEAT_TRUSTED_SECONDS))
        ) {
            return $reported;
        }

        if (! $this->last_active_at) {
            return 'offline';
        }

        $idle = $this->last_active_at->diffInSeconds(now());

        $fallback = match (true) {
            $idle <= self::ONLINE_WITHIN_SECONDS => 'online',
            $idle <= self::OFFLINE_AFTER_SECONDS => 'away',
            default => 'offline',
        };

        /*
         * A stale report still counts for something: it is the last thing
         * the browser said, and request traffic must not talk over it.
         *
         * Without this, somebody who went idle — reported away — and then
         * shut the lid would turn green again the moment their heartbeat
         * expired, because the polling their open tab had been doing right
         * up to that second looks exactly like a person. The fallback may
         * move them further away, never nearer.
         */
        if ($reported) {
            $rank = ['online' => 0, 'away' => 1, 'offline' => 2];

            return $rank[$fallback] >= $rank[$reported] ? $fallback : $reported;
        }

        return $fallback;
    }

    /**
     * That state, as far as $viewer is allowed to know it.
     *
     * Null means "do not show a dot at all" — which is not the same as
     * offline, and the difference matters: somebody who has hidden their
     * status should not be reported as gone, because "gone" is itself an
     * answer to the question they declined.
     *
     * The privacy check is the one isOnlineFor has always made: 'nobody'
     * hides it from everyone, 'connections' from strangers, 'everyone' from
     * no one.
     */
    public function presenceFor(?self $viewer): ?string
    {
        if (! $viewer) {
            return null;
        }
        if ($viewer->id === $this->id) {
            return $this->presenceState();
        }

        return $this->presenceVisibleTo($viewer, 'online_status_visibility')
            ? $this->presenceState()
            : null;
    }

    /**
     * May $viewer see this person's presence, under $key?
     *
     * Two conditions, and the second is the one people expect without being
     * able to name it: whoever hides their own is not shown anybody else's.
     *
     * A setting that takes without giving is not a privacy setting, it is an
     * advantage — you would see who is at their desk while they could not see
     * you, which is precisely the arrangement the person switching it off was
     * trying to prevent for themselves. Every messenger that has ever shipped
     * this setting made it reciprocal, and for the same reason.
     *
     * Only 'nobody' costs you the view. 'connections' is not hiding; it is
     * answering a narrower question, and it goes on being answered both ways.
     */
    public function presenceVisibleTo(?self $viewer, string $key = 'online_status_visibility'): bool
    {
        if (! $viewer) {
            return false;
        }
        if ($viewer->id === $this->id) {
            return true;
        }

        if (($viewer->settings?->privacyValue($key) ?? 'connections') === 'nobody') {
            return false;
        }

        return match ($this->settings?->privacyValue($key) ?? 'connections') {
            'nobody' => false,
            'connections' => app(\App\Services\AppIdService::class)->areConnected($viewer, $this),
            default => true,
        };
    }

    /**
     * Is this person using the app right now, as far as $viewer may know?
     *
     * Two questions in one, deliberately — asking "are they online" without
     * asking "may this person see that" is how a privacy setting ends up
     * being decorative. Kept beside presenceFor() because plenty of screens
     * only ever wanted the green dot, and a boolean is what they read.
     */
    public function isOnlineFor(?self $viewer): bool
    {
        return $this->presenceFor($viewer) === 'online';
    }

    /**
     * Everyone whose screen shows this person's dot.
     *
     * Two audiences, because there are two places a dot appears: the people
     * they are connected to, and the people they share a conversation with —
     * a group can hold somebody they never connected to. Returned as uuids
     * because that is what the channel names are made of.
     *
     * Capped, and deliberately: this list becomes the channel list of a
     * broadcast, and a state change is not worth an unbounded fan-out. The
     * ones past the cap find out on their next poll, which is what used to
     * happen to everybody.
     *
     * @return list<string>
     */
    public function presenceAudience(int $limit = 200): array
    {
        if (($this->settings?->privacyValue('online_status_visibility') ?? 'connections') === 'nobody') {
            return [];
        }

        $connected = Connection::query()
            ->where('status', 'accepted')
            ->where(fn ($q) => $q->where('requester_id', $this->id)->orWhere('addressee_id', $this->id))
            ->get(['requester_id', 'addressee_id'])
            ->map(fn ($c) => $c->requester_id === $this->id ? $c->addressee_id : $c->requester_id);

        $roomMates = \Illuminate\Support\Facades\DB::table('conversation_members as mine')
            ->join('conversation_members as theirs', 'theirs.conversation_id', '=', 'mine.conversation_id')
            ->where('mine.user_id', $this->id)
            ->where('theirs.user_id', '!=', $this->id)
            ->distinct()
            ->pluck('theirs.user_id');

        $ids = $connected->merge($roomMates)->unique()->take($limit);

        if ($ids->isEmpty()) {
            return [];
        }

        /*
         * And not to anybody who hides their own.
         *
         * The read paths already answer null for them, but a live broadcast
         * would arrive anyway and their screen would light up with dots the
         * API refuses to confirm — the setting would look like it worked
         * until the page was reloaded.
         */
        return self::with('settings')->whereIn('id', $ids)->get()
            ->reject(fn (self $viewer) => ($viewer->settings?->privacyValue('online_status_visibility') ?? 'connections') === 'nobody')
            ->pluck('uuid')->all();
    }

    /**
     * Where a broadcast notification is delivered.
     *
     * Laravel's default is a channel named after the class and primary key —
     * App.Models.User.{id} — which this app has no authorisation rule for and
     * would never let anybody subscribe to. It already has the right channel:
     * user.{uuid} is authorised in channels.php, is what calls and meeting
     * signals already travel on, and its comment has always said
     * "notifications" among them. It simply was not being used for any.
     *
     * Naming it here is what lets the bell hear a notification the moment it
     * is created, instead of finding out on its next poll.
     */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'user.' . $this->uuid;
    }

    /** Installed Android apps that can be rung. The native twin of the above. */
    public function fcmTokens(): HasMany
    {
        return $this->hasMany(FcmToken::class);
    }

    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function assignedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_assignments')
            ->withPivot(['status', 'note', 'assigned_by'])
            ->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'group_members')
            ->withPivot(['role', 'added_by'])
            ->withTimestamps();
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }

    public function sentConnections(): HasMany
    {
        return $this->hasMany(Connection::class, 'requester_id');
    }

    public function receivedConnections(): HasMany
    {
        return $this->hasMany(Connection::class, 'addressee_id');
    }

    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'blocked_users', 'user_id', 'blocked_user_id')
            ->withPivot('reason')
            ->withTimestamps();
    }

    // --- Role helpers --------------------------------------------------------

    public function hasRole(string ...$slugs): bool
    {
        return $this->roles->pluck('slug')->intersect($slugs)->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('super_admin', 'admin');
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', fn ($q) => $q->where('slug', $slug))
            ->exists();
    }

    /**
     * Subadmin module rights. Admins and super admins always pass; subadmins
     * need an explicit grant, except the approvals module which stays open
     * (its review flow predates the grants system).
     */
    /** Staff = anyone with an admin-panel presence. */
    public function isStaff(): bool
    {
        return $this->hasRole('super_admin', 'admin', 'subadmin', 'salesperson');
    }

    public function canModule(string $module, string $ability = 'view'): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        // Salespersons: Internal Work only.
        if ($this->hasRole('salesperson')) {
            return $module === 'internal';
        }
        if (! $this->hasRole('subadmin')) {
            return false;
        }

        $grant = \Illuminate\Support\Facades\DB::table('user_module_permissions')
            ->where('user_id', $this->id)
            ->where('module', $module)
            ->first();

        if (! $grant) {
            return $module === 'approvals';
        }

        return (bool) ($grant->{'can_' . $ability} ?? false);
    }

    public function hasBlocked(User $other): bool
    {
        return $this->blockedUsers()->where('blocked_user_id', $other->id)->exists();
    }

    public function isBlockedBy(User $other): bool
    {
        return $other->hasBlocked($this);
    }
}
