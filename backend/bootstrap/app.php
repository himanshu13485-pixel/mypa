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
        // Session descriptions are CRLF-delimited and must keep their trailing
        // terminator: trimming it makes Chrome reject the whole offer with
        // "Invalid SDP line", so WebRTC never connects between browsers.
        $middleware->trimStrings(except: ['payload.sdp', 'sdp']);
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'active' => \App\Http\Middleware\EnsureActiveUser::class,
            'verified.email' => \App\Http\Middleware\EnsureVerifiedEmail::class,
            'guest.meeting' => \App\Http\Middleware\AuthenticateMeetingGuest::class,
            'module' => \App\Http\Middleware\EnsureModule::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
