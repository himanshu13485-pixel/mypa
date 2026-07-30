<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password as PasswordRule;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::with(['appId', 'roles', 'profile', 'salesperson']);

        if ($q = $request->query('q')) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhereHas('appId', fn ($a) => $a->where('app_id', 'like', "%{$q}%")));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($role = $request->query('role')) {
            $query->whereHas('roles', fn ($r) => $r->where('slug', $role));
        }

        $users = $query->latest()->paginate(20);

        // Attach the effective plan (active/trial subscription, else Free).
        $entitlements = app(\App\Services\SubscriptionEntitlementService::class);
        $users->getCollection()->each(
            fn ($user) => $user->setAttribute('plan_slug', $entitlements->planFor($user)->slug),
        );

        return UserResource::collection($users);
    }

    public function store(Request $request, AppIdService $appIds): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'password' => ['required', PasswordRule::min(8)->letters()->numbers()],
            'role' => ['required', 'exists:roles,slug'],
        ]);

        // Only a super admin may create admins (or other super admins).
        if (in_array($data['role'], ['admin', 'super_admin']) && ! $request->user()->isSuperAdmin()) {
            abort(403, 'Only a super admin can create admin accounts.');
        }

        $user = DB::transaction(function () use ($data, $request, $appIds) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'mobile' => $data['mobile'] ?? null,
                'password' => $data['password'],
                'email_verified_at' => now(),
            ]);

            $user->profile()->create([]);
            $user->settings()->create([]);
            $appIds->generateFor($user);

            $role = Role::where('slug', $data['role'])->firstOrFail();
            $user->roles()->attach($role->id, ['assigned_by' => $request->user()->id]);

            return $user;
        });

        return response()->json([
            'message' => 'User created.',
            'data' => new UserResource($user->load(['appId', 'roles', 'profile'])),
        ], 201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load(['appId', 'roles', 'profile', 'settings']));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $this->guardTargetEditable($request, $user);

        $user->update($data);

        return response()->json([
            'message' => 'User updated.',
            'data' => new UserResource($user->fresh()->load(['appId', 'roles', 'profile'])),
        ]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->guardTargetEditable($request, $user);
        abort_if($user->id === $request->user()->id, 422, 'You cannot suspend your own account.');

        $user->update(['status' => 'suspended']);
        $user->tokens()->delete();
        \App\Models\AuditLog::record($request->user(), 'user.suspended', $user);

        return response()->json(['message' => 'User suspended.']);
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $this->guardTargetEditable($request, $user);

        $user->update(['status' => 'active']);
        \App\Models\AuditLog::record($request->user(), 'user.activated', $user);

        return response()->json(['message' => 'User activated.']);
    }

    public function syncRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['exists:roles,slug'],
        ]);

        $this->guardTargetEditable($request, $user);

        $touchesAdmin = collect($data['roles'])->intersect(['admin', 'super_admin'])->isNotEmpty()
            || $user->hasRole('admin', 'super_admin');

        if ($touchesAdmin && ! $request->user()->isSuperAdmin()) {
            abort(403, 'Only a super admin can grant or change admin roles.');
        }

        $roleIds = Role::whereIn('slug', $data['roles'])->pluck('id');
        $user->roles()->sync($roleIds->mapWithKeys(
            fn ($id) => [$id => ['assigned_by' => $request->user()->id]]
        ));
        \App\Models\AuditLog::record($request->user(), 'user.roles_changed', $user, ['roles' => $data['roles']]);

        return response()->json([
            'message' => 'Roles updated.',
            'data' => new UserResource($user->fresh()->load(['appId', 'roles'])),
        ]);
    }

    public function regenerateAppId(Request $request, User $user, AppIdService $appIds): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only a super admin can regenerate App IDs.');

        $record = $appIds->regenerateFor($user);
        \App\Models\AuditLog::record($request->user(), 'user.app_id_regenerated', $user, [
            'previous' => $record->regenerated_from,
            'new' => $record->app_id,
        ]);

        return response()->json([
            'message' => 'App ID regenerated.',
            'data' => ['app_id' => $record->app_id, 'previous' => $record->regenerated_from],
        ]);
    }

    /** View the user's active mobile-verification OTP (admin relays it if needed). */
    public function activeOtp(Request $request, User $user): JsonResponse
    {
        $otp = \App\Models\MobileOtp::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        return response()->json([
            'data' => $otp ? [
                'code' => $otp->code,
                'mobile' => $otp->mobile,
                'purpose' => $otp->purpose,
                'expires_at' => $otp->expires_at,
            ] : null,
        ]);
    }

    /** Re-issue the in-app OTP for a user (admin-side send). */
    public function resendOtp(Request $request, User $user): JsonResponse
    {
        $service = app(\App\Services\MobileOtpService::class);

        // Email-first flow: re-send the account-confirmation code by email when
        // the address is unverified; otherwise fall back to the in-app channel.
        if ($user->email && $user->email_verified_at === null) {
            $otp = $service->issueEmail($user, $user->email);
        } else {
            $otp = $service->issue($user, $user->mobile ?? 'app-inbox');
        }

        \App\Models\AuditLog::record($request->user(), 'user.otp_resent', $user);

        return response()->json([
            'message' => 'A new code was sent to the user.',
            'data' => ['code' => $otp->code, 'expires_at' => $otp->expires_at],
        ]);
    }

    /** Admin-editable app settings (username cooldown, OTP expiry). */
    public function settings(Request $request): JsonResponse
    {
        $keys = array_keys(\App\Models\AppSetting::DEFAULTS);

        return response()->json([
            'data' => collect($keys)->mapWithKeys(
                fn ($key) => [$key => \App\Models\AppSetting::get($key)]
            ),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only a super admin can change settings.');

        $data = $request->validate([
            'username_change_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'otp_expiry_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
        ]);

        foreach ($data as $key => $value) {
            \App\Models\AppSetting::set($key, (string) $value);
        }
        \App\Models\AuditLog::record($request->user(), 'settings.updated', null, $data);

        return response()->json(['message' => 'Settings saved.']);
    }

    /** Members active in the last 24h with their latest login details. */
    public function activeMembers(Request $request): JsonResponse
    {
        $latestLogins = \App\Models\LoginHistory::with('user.appId', 'user.roles')
            ->where('logged_in_at', '>=', now()->subDay())
            ->orderByDesc('logged_in_at')
            ->limit(300)
            ->get()
            ->unique('user_id')
            ->take(100)
            ->values();

        return response()->json([
            'data' => $latestLogins->map(fn ($login) => [
                'uuid' => $login->user->uuid,
                'name' => $login->user->name,
                'username' => $login->user->username,
                'app_id' => $login->user->appId?->app_id,
                'mobile' => $login->user->mobile,
                'roles' => $login->user->roles->pluck('slug'),
                'status' => $login->user->status,
                'ip_address' => $login->ip_address,
                'device' => $login->device_name,
                'last_active_at' => $login->logged_in_at,
                'is_online' => $login->logged_in_at->gt(now()->subHour()) && $login->logged_out_at === null,
            ]),
        ]);
    }

    /** Per-user activity summary for the admin panel. */
    /**
     * Manually mark a user's email as verified — the escape hatch for when
     * the OTP mail does not arrive (spam filters, provider outages).
     */
    public function verifyEmail(Request $request, User $user): JsonResponse
    {
        abort_unless($user->email, 422, 'This user has no email on record.');

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
            \App\Models\AuditLog::record($request->user(), 'user.email_verified_manually', $user, [
                'email' => $user->email,
            ]);
        }

        return response()->json(['message' => 'Email marked as verified.']);
    }

    /** All salesperson accounts (for the assign dropdown). */
    public function salespeople(): JsonResponse
    {
        $rows = User::whereHas('roles', fn ($r) => $r->where('slug', 'salesperson'))
            ->orderBy('name')
            ->get(['id', 'uuid', 'name'])
            ->map(fn ($u) => ['uuid' => $u->uuid, 'name' => $u->name]);

        return response()->json(['data' => $rows]);
    }

    /** Assign (or clear) the salesperson who looks after this user. */
    public function assignSalesperson(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'salesperson_uuid' => ['nullable', 'uuid'],
        ]);

        if (empty($data['salesperson_uuid'])) {
            $user->forceFill(['salesperson_id' => null])->save();
            \App\Models\AuditLog::record($request->user(), 'salesperson.unassigned', $user);

            return response()->json(['message' => 'Salesperson unassigned.']);
        }

        $salesperson = User::where('uuid', $data['salesperson_uuid'])->firstOrFail();
        abort_unless($salesperson->hasRole('salesperson'), 422, 'That account is not a salesperson.');

        $user->forceFill(['salesperson_id' => $salesperson->id])->save();
        \App\Models\AuditLog::record($request->user(), 'salesperson.assigned', $user, [
            'salesperson' => $salesperson->name,
        ]);

        return response()->json(['message' => "Assigned to {$salesperson->name}."]);
    }

    public function summary(Request $request, User $user): JsonResponse
    {
        $lastLogin = $user->loginHistories()->latest('logged_in_at')->first();
        $entitlements = app(\App\Services\SubscriptionEntitlementService::class);

        return response()->json([
            'data' => [
                'user' => ['uuid' => $user->uuid, 'name' => $user->name, 'username' => $user->username],
                'last_login' => $lastLogin ? [
                    'at' => $lastLogin->logged_in_at,
                    'ip' => $lastLogin->ip_address,
                    'device' => $lastLogin->device_name,
                ] : null,
                'member_since' => $user->created_at,
                'plan' => $entitlements->planFor($user)->slug,
                'tasks' => [
                    'total' => $user->tasks()->count(),
                    'completed' => $user->tasks()->where('status', 'completed')->count(),
                    'created_this_week' => $user->tasks()->where('created_at', '>=', now()->subWeek())->count(),
                ],
                'notes' => $user->notes()->count(),
                'files' => [
                    'count' => $user->files()->count(),
                    'storage_bytes' => (int) $user->files()->sum('size'),
                ],
                'groups_owned' => \App\Models\Group::where('owner_id', $user->id)->count(),
                'messages_sent' => \App\Models\Message::where('user_id', $user->id)->count(),
                'logins_this_week' => $user->loginHistories()->where('logged_in_at', '>=', now()->subWeek())->count(),
                'reports_against' => \App\Models\Report::where('reported_user_id', $user->id)->count(),
                'open_reports_against' => \App\Models\Report::where('reported_user_id', $user->id)->where('status', 'open')->count(),
            ],
        ]);
    }

    /** Subadmin module rights (view/edit/delete per admin area). */
    public function modulePermissions(Request $request, User $user): JsonResponse
    {
        $rows = \Illuminate\Support\Facades\DB::table('user_module_permissions')
            ->where('user_id', $user->id)->get()->keyBy('module');

        $modules = ['users', 'approvals', 'moderation', 'activity'];

        return response()->json([
            'data' => collect($modules)->mapWithKeys(fn ($module) => [$module => [
                'can_view' => (bool) ($rows[$module]->can_view ?? ($module === 'approvals')),
                'can_edit' => (bool) ($rows[$module]->can_edit ?? ($module === 'approvals')),
                'can_delete' => (bool) ($rows[$module]->can_delete ?? false),
            ]]),
        ]);
    }

    public function updateModulePermissions(Request $request, User $user): JsonResponse
    {
        abort_unless($user->hasRole('subadmin'), 422, 'Module rights apply to subadmin accounts.');

        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*.can_view' => ['required', 'boolean'],
            'permissions.*.can_edit' => ['required', 'boolean'],
            'permissions.*.can_delete' => ['required', 'boolean'],
        ]);

        foreach ($data['permissions'] as $module => $abilities) {
            if (! in_array($module, ['users', 'approvals', 'moderation', 'activity'], true)) {
                continue;
            }
            \Illuminate\Support\Facades\DB::table('user_module_permissions')->updateOrInsert(
                ['user_id' => $user->id, 'module' => $module],
                [
                    'can_view' => $abilities['can_view'],
                    'can_edit' => $abilities['can_edit'],
                    'can_delete' => $abilities['can_delete'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        \App\Models\AuditLog::record($request->user(), 'subadmin.rights_changed', $user, $data['permissions']);

        return response()->json(['message' => 'Rights updated for ' . $user->name . '.']);
    }

    /** Admins cannot modify super admins; nobody edits a super admin except a super admin. */
    protected function guardTargetEditable(Request $request, User $user): void
    {
        if ($user->isSuperAdmin() && ! $request->user()->isSuperAdmin()) {
            abort(403, 'You cannot modify a super admin account.');
        }
    }
}
