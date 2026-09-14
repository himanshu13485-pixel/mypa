<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Notifications\SocialNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Resetting a password from the Users screen.
 *
 * Everybody - an employee, a Subadmin - may change their own, and only with
 * the current one, the same as Settings asks. Anybody else's is the Company
 * Admin's: a new password they hand over, after which that person is signed
 * out everywhere and asked to choose their own at the next sign-in.
 *
 * A Subadmin does not reset an employee's password, whatever else they
 * manage: it is the one act that opens somebody's whole Netvork account.
 */
class EmployeePasswordController extends Controller
{
    /** Roles that make an account reach past this company. */
    private const PLATFORM_ROLES = ['super_admin', 'admin', 'subadmin', 'salesperson'];

    public function update(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $target = Member::with('user.roles:id,slug')
            ->where('organization_id', $org->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_unless($target->user, 422, 'That member has no Netvork account.');

        $self = $target->user_id === $request->user()->id;

        abort_unless(
            $self || $me->crm_role === 'admin',
            403,
            'You can reset your own password. Anybody else’s is the Company Admin’s to reset.',
        );

        $rule = PasswordRule::min(8)->letters()->numbers();

        if ($self) {
            $data = $request->validate([
                'current_password' => ['required', 'current_password'],
                'password' => ['required', 'confirmed', $rule],
            ]);

            $target->user->forceFill(['password' => $data['password'], 'force_password_change' => false])->save();

            // Every other device out; this one stays signed in.
            $current = $request->user()->currentAccessToken();
            $currentId = $current instanceof \Laravel\Sanctum\PersonalAccessToken ? $current->id : null;
            $target->user->tokens()
                ->when($currentId !== null, fn ($q) => $q->where('id', '!=', $currentId))
                ->delete();

            ActivityLog::record($me, $org->id, 'employee.password_changed', $target, [
                'employee' => $target->user->name,
            ]);

            return response()->json(['message' => 'Your password is changed. Your other devices were signed out.']);
        }

        // An account that is also Netvork's own staff is not a company's to open.
        abort_if(
            $target->user->roles->pluck('slug')->intersect(self::PLATFORM_ROLES)->isNotEmpty(),
            403,
            'That account holds a platform role and cannot be reset from here.',
        );

        $data = $request->validate([
            'password' => ['required', 'confirmed', $rule],
        ]);

        $target->user->forceFill(['password' => $data['password'], 'force_password_change' => true])->save();

        // A reset that leaves the old sessions working is not a reset.
        $target->user->tokens()->delete();

        ActivityLog::record($me, $org->id, 'employee.password_reset', $target, [
            'employee' => $target->user->name,
            'by' => $me->user?->name,
        ]);

        // Told it happened - never told the password.
        $target->user->notify(new SocialNotification(
            'account_security',
            'Your password was reset by ' . ($me->user?->name ?? 'your company admin')
                . '. If this was not expected, tell them straight away.',
            ['by' => $me->user?->name],
            '/settings',
        ));

        return response()->json([
            'message' => $target->user->name . '’s password is reset. They are signed out everywhere and will choose their own at the next sign-in.',
        ]);
    }
}
