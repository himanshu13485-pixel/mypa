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
    ];

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
