<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Channel auth for the token-based SPA: POST /api/broadcasting/auth with a Bearer token.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        // A meeting guest has no Sanctum token, so they are resolved first and
        // Sanctum then finds somebody already signed in. The channel rules do
        // the scoping — a guest is only ever let onto their own channel.
        ['prefix' => 'api', 'middleware' => ['api', \App\Http\Middleware\ResolveMeetingGuest::class, 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        // Presence, taken from the traffic an open app already makes. It does
        // its work in terminate() — see the class — because global middleware
        // runs before route middleware, so during handle() the auth on these
        // routes has not happened and there is nobody to record yet.
        $middleware->append(\App\Http\Middleware\TrackActivity::class);
        // Session descriptions are CRLF-delimited and must keep their trailing
        // terminator: trimming it makes Chrome reject the whole offer with
        // "Invalid SDP line", so WebRTC never connects between browsers.
        $middleware->trimStrings(except: ['payload.sdp', 'sdp']);
        /*
         * Keep the guest resolver in front of auth:sanctum.
         *
         * Listing it first in withBroadcasting() is not enough. Laravel sorts
         * every route's middleware by $middlewarePriority before running it,
         * and Authenticate sits above SubstituteBindings in that list — so on
         * the broadcasting route it was hoisted past this middleware, which
         * carries no priority of its own, and ran first. Sanctum then saw a
         * guest pass it does not issue and answered "Unauthenticated.", so no
         * guest could authorise their channel and no offer or answer ever
         * reached them. route:list shows the unsorted order and looked right.
         */
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\ResolveMeetingGuest::class,
        );
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'active' => \App\Http\Middleware\EnsureActiveUser::class,
            'verified.email' => \App\Http\Middleware\EnsureVerifiedEmail::class,
            'guest.meeting' => \App\Http\Middleware\AuthenticateMeetingGuest::class,
            'module' => \App\Http\Middleware\EnsureModule::class,
            'service.account' => \App\Http\Middleware\EnsureServiceAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
