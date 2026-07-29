<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status === 'suspended') {
            $user->currentAccessToken()?->delete();

            return response()->json([
                'message' => 'Your account has been suspended. Contact support.',
            ], 403);
        }

        return $next($request);
    }
}
