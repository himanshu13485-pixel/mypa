<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The service panel is for accounts an application signs in as, and only those.
 *
 * 404 rather than 403: to an ordinary account these routes should not appear to
 * exist at all. There is nothing here a person could be granted access to, so
 * saying "forbidden" would only describe a door that is not for them.
 */
class EnsureServiceAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_service_account, 404);

        return $next($request);
    }
}
