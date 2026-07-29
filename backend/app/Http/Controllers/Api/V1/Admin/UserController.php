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
        $query = User::with(['appId', 'roles', 'profile']);

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

        return UserResource::collection($query->latest()->paginate(20));
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

    /** Admins cannot modify super admins; nobody edits a super admin except a super admin. */
    protected function guardTargetEditable(Request $request, User $user): void
    {
        if ($user->isSuperAdmin() && ! $request->user()->isSuperAdmin()) {
            abort(403, 'You cannot modify a super admin account.');
        }
    }
}
