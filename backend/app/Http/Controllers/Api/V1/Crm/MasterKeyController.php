<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Models\User;
use App\Notifications\SocialNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * The company's master key, and putting somebody back in with it.
 *
 * What this replaces is an admin inventing a password on the phone on a Monday
 * morning — a different one each time, said out loud, written on something.
 * One value, set once, and a button.
 *
 * Everything in here is about keeping that convenience from becoming a
 * back door, because the same button is exactly what somebody who has taken
 * over an admin account would reach for:
 *
 *   - Only an admin. Not a subadmin, whatever rights they hold: this opens
 *     every staff account in the company, and it is not a grantable module
 *     permission but the authority of the person who runs the place.
 *   - Never on a fellow admin, and never on yourself. Resetting a peer
 *     director's password is a takeover, not a lockout fix, and the platform's
 *     own super admin is the right route for it.
 *   - Setting the key asks for the admin's own password first. A hijacked
 *     session should not be able to mint a key that opens the whole company
 *     without knowing the password of the account it hijacked.
 *   - A reset forces a password change at the next sign-in, so the master key
 *     is a doorway and never a resting password on anybody's account.
 *   - A reset takes the target's live sessions down. Changing a password while
 *     leaving the old tokens working is not a lockout at all — whoever was in
 *     there stays in there.
 *   - The person is told, in the app and by email. A silent password change on
 *     somebody's account is how an account takeover hides; if this ever
 *     happens without the employee expecting it, they need to know the same
 *     day.
 *   - It is written to the activity log, without the key.
 */
class MasterKeyController extends Controller
{
    /** Whether there is one, when it was set, and by whom. Never what it is. */
    public function show(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $org->loadMissing('masterKeySetBy:id,name');

        return response()->json(['data' => [
            'is_set' => $org->hasMasterKey(),
            'set_at' => $org->master_key_set_at?->toDateTimeString(),
            'set_by' => $org->masterKeySetBy?->name,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $me = $this->admin($request);

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'master_key' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()->symbols()],
        ], [
            'master_key.confirmed' => 'The two copies of the master key do not match.',
        ]);

        /*
         * The admin's own password, checked by hand rather than with the
         * `current_password` rule, so the refusal reads as what it is rather
         * than as a field validation error on a form about something else.
         */
        if (! Hash::check($data['current_password'], $me->user->password)) {
            return response()->json(['message' => 'That is not your password.'], 422);
        }

        $existed = $org->hasMasterKey();

        // Assigned rather than filled: master_key is deliberately not in
        // $fillable, so that nothing else in the app can ever set it.
        $org->master_key = $data['master_key'];
        $org->master_key_set_at = now();
        $org->master_key_set_by = $me->user_id;
        $org->save();

        // The trail records that it changed and never what to.
        ActivityLog::record($me, $org->id, 'settings.master_key', $org, [
            'action' => $existed ? 'replaced' : 'set',
            'by' => $me->user?->name,
        ]);

        return response()->json([
            'message' => $existed ? 'Master key replaced.' : 'Master key set.',
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $me = $this->admin($request);

        $org->master_key = null;
        $org->master_key_set_at = null;
        $org->master_key_set_by = null;
        $org->save();

        ActivityLog::record($me, $org->id, 'settings.master_key', $org, [
            'action' => 'cleared',
            'by' => $me->user?->name,
        ]);

        return response()->json(['message' => 'Master key removed. Resets are no longer possible until a new one is set.']);
    }

    /** Put one employee's account back onto the master key. */
    public function reset(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $me = $this->admin($request);

        abort_unless($org->hasMasterKey(), 422, 'Set a master key first, under Settings.');

        /** @var Member $target */
        $target = Member::visible()->with('user.roles:id,slug')
            ->where('organization_id', $org->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_unless($target->user, 422, 'That employee has no Netvork account to reset.');
        abort_if($target->user_id === $me->user_id, 422, 'You cannot reset your own password this way. Use Settings.');
        abort_if($target->crm_role === 'admin', 403,
            'An admin\'s password cannot be reset with the master key. Ask the platform to do it.');

        /*
         * And nobody who holds a platform role.
         *
         * A company admin's authority runs to their own staff. Somebody who is
         * also a Netvork admin or salesperson has an account that reaches past
         * this company, and a company-set key must not open it.
         */
        $platform = $target->user->roles->pluck('slug')
            ->intersect(['super_admin', 'admin', 'subadmin', 'salesperson']);
        abort_if($platform->isNotEmpty(), 403,
            'That account holds a platform role and cannot be reset from here.');

        $target->user->forceFill([
            'password' => $org->master_key,
            'force_password_change' => true,
        ])->save();

        /*
         * And out of every session they had.
         *
         * A password changed while the old tokens go on working is not a
         * lockout: whoever was signed in stays signed in, which is precisely
         * the case — a laptop left at a former desk, an account somebody else
         * has got into — that this button gets pressed for.
         */
        $target->user->tokens()->delete();

        ActivityLog::record($me, $org->id, 'employee.password_reset', $target, [
            'employee' => $target->user->name,
            'by' => $me->user?->name,
        ]);

        $this->tell($target->user, $me);

        return response()->json([
            'message' => $target->user->name . "'s password is now the master key. "
                . 'They will be asked to change it when they next sign in.',
        ]);
    }

    /**
     * Tell the person it happened.
     *
     * Both channels, and neither carries the new password: an email that
     * contains the key would put it in a mailbox and in every server between
     * here and there, and the admin is handing it over in person anyway. What
     * the message is for is the other case — the one where the employee reads
     * it and knows they did not ask for this.
     */
    protected function tell(User $user, Member $admin): void
    {
        $line = 'Your password was reset by ' . ($admin->user?->name ?? 'your company admin')
            . '. If this was not expected, tell them straight away.';

        // 'account_security' is an existing kind, and the one that puts this on
        // the phone's "Your account" channel rather than among the shares.
        $user->notify(new SocialNotification(
            'account_security',
            $line,
            ['by' => $admin->user?->name],
            '/settings',
            'password-reset-' . now()->timestamp,
        ));
    }

    /**
     * The caller, and only if they run the place.
     *
     * The route sits behind crm.manager like its neighbours, which admits a
     * subadmin. This is the check that does not — kept here rather than in the
     * route so the refusal can say which authority is missing.
     */
    protected function admin(Request $request): Member
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        abort_unless($me?->crm_role === 'admin', 403,
            'Only a company admin can use the master key.');

        $me->loadMissing('user');

        return $me;
    }
}
