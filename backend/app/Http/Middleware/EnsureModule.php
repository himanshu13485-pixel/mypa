<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModule
{
    /** Usage: ->middleware('module:moderation,edit') */
    public function handle(Request $request, Closure $next, string $module, string $ability = 'view'): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canModule($module, $ability)) {
            abort(403, 'You do not have ' . $ability . ' rights for this section.');
        }

        return $next($request);
    }
}
