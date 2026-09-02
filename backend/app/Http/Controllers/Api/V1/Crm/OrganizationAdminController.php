<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * The Super Admin side of the addon: turning the CRM on for a company.
 * Creating an organization names its first CRM admin — an existing Netvork
 * account by email, or a fresh one created on the spot.
 */
class OrganizationAdminController extends Controller
{
    public function index(): JsonResponse
    {
        $orgs = Organization::withCount([
            'members' => fn ($q) => $q->where('is_oversight', false),
            'members as active_members_count' => fn ($q) => $q->where('is_oversight', false)->where('status', 'active'),
        ])
            ->latest()
            ->get()
            ->map(fn ($o) => [
                'uuid' => $o->uuid,
                'name' => $o->name,
                'code' => $o->code,
                'status' => $o->status,
                'impersonation_level' => $o->impersonation_level,
                'members' => $o->members_count,
                'active_members' => $o->active_members_count,
                'admins' => Member::visible()->with('user:id,name,email')
                    ->where('organization_id', $o->id)
                    ->where('crm_role', 'admin')
                    ->get()
                    ->map(fn ($m) => ['name' => $m->user?->name, 'email' => $m->user?->email]),
                'created_at' => $o->created_at?->toDateTimeString(),
            ]);

        return response()->json(['data' => $orgs]);
    }

    public function store(Request $request, AppIdService $appIds): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32', 'alpha_dash', 'unique:crm_organizations,code'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['nullable', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $org = DB::transaction(function () use ($data, $request, $appIds) {
            $org = Organization::create([
                'name' => $data['name'],
                'code' => ($data['code'] ?? null) ?: Str::upper(Str::random(6)),
                'created_by' => $request->user()->id,
            ]);

            $user = User::where('email', $data['admin_email'])->first();
            if (! $user) {
                if (empty($data['admin_password'])) {
                    abort(422, 'A password is required when the admin email is not an existing Netvork account.');
                }
                $user = User::create([
                    'name' => $data['admin_name'],
                    'email' => $data['admin_email'],
                    'password' => $data['admin_password'],
                ]);
                // Created by the super admin — the address is taken as proven.
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->profile()->create([]);
                $user->settings()->create([]);
                $appIds->generateFor($user);
                $role = Role::where('slug', 'user')->first();
                if ($role) {
                    $user->roles()->attach($role->id, ['assigned_by' => $request->user()->id]);
                }
            }

            Member::create([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'crm_role' => 'admin',
                'joined_at' => now()->toDateString(),
            ]);

            return $org;
        });

        return response()->json(['message' => 'CRM enabled for ' . $org->name . '.', 'data' => ['uuid' => $org->uuid]], 201);
    }

    /**
     * The Super Admin's all-companies view: every employee of one
     * organization, with role, manager and status — read-only oversight
     * without joining the org.
     */
    public function members(Organization $organization): JsonResponse
    {
        $members = Member::visible()->with(['user:id,name,email', 'manager.user:id,name'])
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->get()
            ->map(fn (Member $m) => [
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'employee_code' => $m->employee_code,
                'crm_role' => $m->crm_role,
                'department' => $m->department,
                'designation' => $m->designation,
                'reports_to' => $m->manager?->user?->name,
                'status' => $m->status,
                'joined_at' => $m->joined_at?->toDateString(),
            ]);

        return response()->json(['data' => [
            'organization' => ['name' => $organization->name, 'code' => $organization->code],
            'members' => $members,
        ]]);
    }

    /**
     * The Super Admin steps into a company workspace: they become (or
     * already are) an admin member of it, and the frontend switches its
     * org hat. This is what turns oversight into view/edit access.
     */
    public function enter(Request $request, Organization $organization): JsonResponse
    {
        $member = Member::firstOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $request->user()->id],
            // Oversight: full admin access, invisible inside the company.
            ['crm_role' => 'admin', 'is_oversight' => true, 'joined_at' => now()->toDateString()],
        );

        // A previously deactivated or demoted entry comes back as admin —
        // the super admin's access to a workspace is never second-class.
        if ($member->status !== 'active' || $member->crm_role !== 'admin') {
            $member->update(['status' => 'active', 'crm_role' => 'admin']);
        }

        return response()->json(['message' => 'Opened ' . $organization->name . ' as admin.', 'data' => [
            'organization_uuid' => $organization->uuid,
        ]]);
    }

    /**
     * Switch the addon off for good: the organization and everything filed
     * under it - members, leads, clients, invoices, payroll - go together,
     * because a CRM without its own records is not a thing anyone wants.
     *
     * Two rails, because this cannot be undone. It has to be suspended
     * first, which is a deliberate second visit rather than a slip of the
     * mouse on a live company; and the code has to be typed back, so the
     * row being deleted is the row that was read. The Netvork accounts
     * themselves are untouched - people keep their logins and their
     * personal side, they simply stop being employees of this company.
     */
    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate(['confirm' => ['required', 'string']]);

        abort_if($organization->status !== 'suspended', 422,
            'Suspend the organization first. Deleting a live CRM takes its whole history with it.');
        abort_if(! hash_equals($organization->code, trim($data['confirm'])), 422,
            'The code did not match, so nothing was deleted.');

        $name = $organization->name;
        $counts = [
            'members' => Member::where('organization_id', $organization->id)->count(),
            'code' => $organization->code,
        ];

        \App\Models\AuditLog::record($request->user(), 'crm.organization_deleted', $organization, $counts);
        $organization->delete();

        return response()->json(['message' => $name . ' and its CRM records have been deleted.']);
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32', 'alpha_dash',
                \Illuminate\Validation\Rule::unique('crm_organizations', 'code')->ignore($organization->id)],
            'status' => ['nullable', \Illuminate\Validation\Rule::in(['active', 'suspended'])],
            /*
             * How far this company's admin may sit in a member's seat.
             *
             * Only ever set from here. The company cannot grant it to itself
             * or widen what it was given — which is the whole reason it lives
             * on the organization rather than in the company's own settings
             * screen, where the person it restrains would be the person
             * editing it.
             */
            'impersonation_level' => ['nullable', \Illuminate\Validation\Rule::in(Organization::IMPERSONATION_LEVELS)],
            // Resetting an org admin's password: pick the admin by email.
            'admin_email' => ['nullable', 'email', 'required_with:admin_password'],
            'admin_password' => ['nullable', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $organization->update(array_filter(
            collect($data)->only(['name', 'code', 'status', 'impersonation_level'])->all(),
            fn ($v) => $v !== null,
        ));

        if (($data['impersonation_level'] ?? null) !== null) {
            // Written down, because this is the grant that lets one person
            // read another's screen. Who widened it, and to what, should not
            // be a thing anybody has to reconstruct.
            \App\Models\AuditLog::record($request->user(), 'crm.impersonation.granted', $organization, [
                'organization' => $organization->code,
                'level' => $data['impersonation_level'],
            ]);
        }

        $passwordReset = false;
        if (! empty($data['admin_password'])) {
            // Only accounts that actually admin THIS organization can be
            // reset here — this is org management, not a general user tool.
            $admin = Member::with('user')
                ->where('organization_id', $organization->id)
                ->where('crm_role', 'admin')
                ->get()
                ->first(fn (Member $m) => strcasecmp((string) $m->user?->email, $data['admin_email']) === 0);

            if (! $admin?->user) {
                abort(422, 'That email is not a CRM admin of this organization.');
            }

            $admin->user->update(['password' => $data['admin_password']]);
            $passwordReset = true;
        }

        return response()->json(['message' => 'Organization updated.' . ($passwordReset ? ' Admin password reset.' : ''), 'data' => [
            'uuid' => $organization->uuid,
            'name' => $organization->name,
            'code' => $organization->code,
            'status' => $organization->status,
        ]]);
    }
}
