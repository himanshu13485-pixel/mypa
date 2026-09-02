<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\Crm\ImpersonationController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a borrowed session may reach.
 *
 * The grant is stamped into the token's abilities when the seat is taken, and
 * this is the only thing that reads it. Deliberately one place and not
 * twenty: a scope enforced by each controller remembering to ask is a scope
 * with a hole in it the first time somebody adds a controller.
 *
 * Two rules, and they run in this order because they answer different
 * questions.
 *
 * First, the things nobody may do in somebody else's seat, at any level —
 * not even the widest one. These are not about privacy; they are about the
 * borrower being unable to keep the seat. Change the password and the owner
 * is locked out of their own account; change the e-mail and every future
 * sign-in code goes to the borrower instead; close the account and it is
 * gone. Spending is here too, because the money is the owner's. And a
 * borrowed session cannot borrow again, or the trail of who did what stops
 * at the first hop.
 *
 * Second, the reach the level actually grants. 'account' is the whole of
 * Netvork and passes. The two CRM levels are an allow-list rather than a
 * deny-list — everything is shut, and the workspace plus the handful of calls
 * the shell needs to draw itself are opened — because a deny-list is a list
 * of the private things somebody thought of, and the notes, files, chats,
 * calls, meetings and bills of a person are exactly the things it is easy to
 * not think of. 'crm_read' additionally holds the session to reading: safe
 * methods only, so looking cannot become doing.
 */
class ImpersonationScope
{
    /**
     * Never, at any level. Paths are matched with Request::is(), so a `*`
     * covers everything beneath.
     *
     * @var list<string>
     */
    protected const FORBIDDEN = [
        // Keeping the seat.
        'api/v1/auth/change-password',
        'api/v1/auth/sessions*',
        'api/v1/auth/email/*',
        'api/v1/auth/mobile/*',
        // Spending the owner's money.
        'api/v1/subscription/checkout',
        'api/v1/subscription/cancel',
        'api/v1/payments/*/verify',
        // Borrowing from inside a borrowed seat.
        'api/v1/crm/employees/*/impersonate',
    ];

    /**
     * What a CRM-level borrowed session may reach. Everything else is shut.
     *
     * /me is here because the shell cannot draw a name or a photo without it,
     * and closing the seat obviously has to work from inside it.
     *
     * @var list<string>
     */
    protected const CRM_ALLOWED = [
        'api/v1/crm/*',
        'api/v1/me',
        'api/v1/impersonation/stop',
        'api/v1/auth/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! ImpersonationController::isBorrowed($token)) {
            return $next($request);
        }

        if ($request->is(...self::FORBIDDEN)) {
            abort(403, 'That cannot be done from a borrowed workspace.');
        }

        // Deleting the account is a DELETE on /me, which is otherwise the one
        // call the shell cannot do without — so it is the method that is
        // refused here, not the path.
        if ($request->is('api/v1/me') && $request->isMethod('delete')) {
            abort(403, 'That cannot be done from a borrowed workspace.');
        }

        $level = ImpersonationController::levelOf($token);

        if ($level === 'account') {
            return $next($request);
        }

        if (! $request->is(...self::CRM_ALLOWED)) {
            abort(403, 'This workspace was opened for the company CRM only.');
        }

        if ($level === 'crm_read' && ! $request->isMethodSafe()) {
            abort(403, 'This workspace was opened to look at, not to work in.');
        }

        return $next($request);
    }
}
