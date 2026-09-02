<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Borrowing a member's seat.
 *
 * A company admin who is told "the leads page is empty for me" cannot see
 * what that person sees: rights, team scope and ownership all conspire to
 * make one screen mean different things to different people, and describing
 * a screen down the phone is not how anybody finds out. So they can sit in
 * the seat and look.
 *
 * Everything about this is about keeping that a narrow thing. Signing in as
 * somebody else is the most powerful act in the product, and the account
 * being borrowed is not merely an employee record — it is a person's Netvork,
 * with their private notes, their files and their messages in it.
 *
 * Four rails, and none of them is optional:
 *
 * The platform decides, not the company. A company admin cannot grant
 * themselves this, or widen it; the level lives on the organization and only
 * a super admin writes it. A company that has not been given it does not get
 * a button.
 *
 * The scope travels with the token. The level chosen is stamped into the
 * token's abilities at the moment it is issued, so what the borrowed session
 * may reach is decided once, by the grant, and enforced on every request by
 * middleware rather than by whichever controller remembers to ask.
 *
 * It never reaches upwards. An admin may sit in a subadmin's or an employee's
 * seat, and in nobody else's — not another admin's, not their own, and never
 * an account carrying platform roles, which would turn a company login into
 * the platform's admin panel.
 *
 * It is written down. Both taking the seat and giving it back are audited,
 * with who, whose, and at what level.
 */
class ImpersonationController extends Controller
{
    /** Whose seat an admin may sit in. Never 'admin' — that is sideways. */
    protected const BORROWABLE = ['employee', 'subadmin'];

    /**
     * Roles that make an account unborrowable whatever the CRM says.
     *
     * A company admin borrowing a seat must not end up holding the platform.
     * These are Netvork's own roles, granted outside the company entirely, so
     * an employee who is also a platform admin is simply not available.
     */
    protected const PLATFORM_ROLES = ['admin', 'super_admin', 'subadmin', 'salesperson'];

    public function start(Request $request, string $uuid): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        /** @var Organization $org */
        $org = $request->attributes->get('crm_org');

        abort_unless($me->crm_role === 'admin', 403, 'Only the company admin can open a member\'s workspace.');

        $level = $org->impersonation_level ?? 'none';
        abort_if(
            ! in_array($level, ['crm_read', 'crm', 'account'], true),
            403,
            'Opening a member\'s workspace is not enabled for this company. Ask Netvork to switch it on.',
        );

        $target = Member::visible()->with('user.roles')
            ->where('organization_id', $org->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_if($target->id === $me->id, 422, 'You are already signed in as yourself.');
        abort_if($target->status !== 'active', 422, 'That member is not active.');
        abort_unless(
            in_array($target->crm_role, self::BORROWABLE, true),
            403,
            'Only an employee or a subadmin\'s workspace can be opened this way.',
        );

        $user = $target->user;
        abort_if(! $user, 422, 'That member has no Netvork account to open.');
        abort_if(
            $user->roles->pluck('slug')->intersect(self::PLATFORM_ROLES)->isNotEmpty(),
            403,
            'That account holds Netvork platform roles and cannot be opened from here.',
        );

        /*
         * The scope is the token.
         *
         * Sanctum abilities are the one thing that travels with every request
         * the borrowed session makes, so the grant is stamped here and read by
         * ImpersonationScope thereafter. A controller that forgets to check
         * cannot therefore be the hole — there is nothing for it to forget.
         */
        $token = $user->createToken('impersonation', [
            'impersonate',
            'impersonation-level:' . $level,
            'impersonated-by:' . $request->user()->uuid,
        ])->plainTextToken;

        AuditLog::record($request->user(), 'crm.impersonation.started', $target, [
            'organization' => $org->code,
            'member' => $target->employee_code,
            'member_name' => $user->name,
            'crm_role' => $target->crm_role,
            'level' => $level,
        ]);

        return response()->json([
            'message' => 'Opened ' . $user->name . '\'s workspace.',
            'data' => [
                'token' => $token,
                'user' => new UserResource($user->load(['profile', 'settings', 'appId', 'roles'])),
                'impersonation' => [
                    'level' => $level,
                    'name' => $user->name,
                    'employee_code' => $target->employee_code,
                    'crm_role' => $target->crm_role,
                    'organization_uuid' => $org->uuid,
                ],
            ],
        ]);
    }

    /**
     * Give the seat back.
     *
     * The borrowed token is destroyed here rather than merely dropped by the
     * browser, because a token the client has forgotten is still a token: it
     * would sit in the database until it expired, valid, belonging to nobody
     * who remembers having it.
     *
     * The admin's own session is not touched and never was — it is held by
     * their browser throughout, so coming back is restoring what they still
     * have rather than being issued something new. There is nothing here that
     * could hand a session to whoever asks.
     */
    public function stop(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        abort_unless(self::isBorrowed($token), 422, 'This session is not a borrowed one.');

        AuditLog::record($request->user(), 'crm.impersonation.ended', null, [
            'level' => $this->levelOf($token),
            'by' => $this->borrowerOf($token),
        ]);

        $token->delete();

        return response()->json(['message' => 'Workspace closed.']);
    }

    /**
     * The abilities actually written on a token.
     *
     * A session authenticated any way other than by a bearer token carries a
     * TransientToken, which has no abilities at all and — worse — answers
     * can() with true for everything. Asking that "can you impersonate?"
     * returns yes about a session that is nothing of the kind, so the test
     * has to be what the token IS, not what it says it can do.
     *
     * @return list<string>
     */
    public static function abilitiesOf(?object $token): array
    {
        return $token instanceof \Laravel\Sanctum\PersonalAccessToken
            ? array_values((array) $token->abilities)
            : [];
    }

    /** Is this session sitting in somebody else's seat? */
    public static function isBorrowed(?object $token): bool
    {
        return in_array('impersonate', self::abilitiesOf($token), true);
    }

    /** The granted level carried by a borrowed token. */
    public static function levelOf(?object $token): ?string
    {
        foreach (self::abilitiesOf($token) as $ability) {
            if (str_starts_with((string) $ability, 'impersonation-level:')) {
                return substr((string) $ability, strlen('impersonation-level:'));
            }
        }

        return null;
    }

    /** The uuid of whoever borrowed the seat, for the audit trail. */
    public static function borrowerOf(?object $token): ?string
    {
        foreach (self::abilitiesOf($token) as $ability) {
            if (str_starts_with((string) $ability, 'impersonated-by:')) {
                return substr((string) $ability, strlen('impersonated-by:'));
            }
        }

        return null;
    }
}
