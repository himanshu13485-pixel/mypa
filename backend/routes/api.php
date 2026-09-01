<?php

use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\StatsController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AppIdController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BlockController;
use App\Http\Controllers\Api\V1\BookingPageController;
use App\Http\Controllers\Api\V1\CallController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\ConnectionController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\NoteController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

// Gateway webhooks (unauthenticated; protected by signature verification).
Route::post('/webhooks/cashfree', [\App\Http\Controllers\Api\WebhookController::class, 'cashfree'])
    ->middleware('throttle:120,1');

Route::prefix('v1')->group(function () {

    // Cashfree telling one CRM company that a payment link was paid.
    // Unauthenticated by nature: the signature over the raw body, checked
    // against that company's own secret, is the whole guard. The company is
    // in the path because each brings its own Cashfree account.
    Route::post('/crm/webhooks/cashfree/{organizationUuid}', [\App\Http\Controllers\Api\V1\Crm\CashfreeWebhookController::class, 'handle'])
        ->middleware('throttle:120,1');

    // Public pricing
    Route::get('/plans', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'plans'])
        ->middleware('throttle:30,1');

    // Public file share link. The token is the whole check, so this is
    // throttled to make guessing one impractical.
    Route::get('/f/{token}', [FileController::class, 'downloadByLink'])
        ->middleware('throttle:60,1')
        ->where('token', '[A-Za-z0-9]{32,64}');

    // Join a meeting with a passcode and no account. Throttled hard: this is
    // the one door into the app that no session guards.
    // A browser reporting that it broke. Open on purpose: the errors most worth
// knowing about are the ones that stop someone signing in, and those have no
// token to offer. Throttled, and everything it stores is truncated.
Route::post('/client-errors', [\App\Http\Controllers\Api\V1\ClientErrorController::class, 'store'])
    ->middleware('throttle:20,1');

// Is this code worth showing a password box for? Answered before anyone types
// anything, so a meeting with no password says "sign in" up front instead of
// after a failed attempt. Booleans only — no title, nothing a guessed code
// could harvest.
Route::get('/meetings/{code}/guest', [\App\Http\Controllers\Api\V1\MeetingGuestController::class, 'peek'])
    ->middleware('throttle:30,1');

Route::post('/meetings/{code}/guest', [\App\Http\Controllers\Api\V1\MeetingGuestController::class, 'join'])
        ->middleware('throttle:10,1');

// The browser moved a push subscription. Open because a service worker holds
// no session and this can fire with no tab to lend it one; the old endpoint is
// what stands in for the session, and only the device that had it knows it.
// The Android notification's Decline button. No sanctum here on purpose: the
// press happens in native code that holds no token, and the URL's signature
// (call + callee + one-minute expiry) is the entire authorisation.
Route::post('/push/calls/{call}/decline', [\App\Http\Controllers\Api\V1\CallController::class, 'declineFromPush'])
    ->name('push.calls.decline')
    ->middleware('signed');

Route::post('/push/rotate', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'rotate'])
    ->middleware('throttle:20,1');

/*
 * Booking links: the second door with no session behind it.
 *
 * Somebody who has been handed a link can see when the host is free and
 * take one of those times, and afterwards can move or cancel what they took
 * using the token in their confirmation email. None of it requires an
 * account, which is the entire point — and which is why every route here is
 * throttled and returns nothing about the host beyond what the link already
 * gave away.
 *
 * Reading is looser than writing. Browsing a fortnight of slots is a
 * handful of requests as somebody flicks between weeks; booking is once.
 */
Route::get('/book/{slug}', [\App\Http\Controllers\Api\V1\PublicBookingController::class, 'page'])
    ->middleware('throttle:60,1');
Route::get('/book/{slug}/slots', [\App\Http\Controllers\Api\V1\PublicBookingController::class, 'slots'])
    ->middleware('throttle:60,1');
Route::post('/book/{slug}', [\App\Http\Controllers\Api\V1\PublicBookingController::class, 'book'])
    ->middleware('throttle:10,1');

// Managing a booking already made. The token is the credential.
Route::get('/bookings/{token}', [\App\Http\Controllers\Api\V1\PublicBookingController::class, 'show'])
    ->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{64}');
Route::post('/bookings/{token}/cancel', [\App\Http\Controllers\Api\V1\PublicBookingController::class, 'cancel'])
    ->middleware('throttle:10,1')->where('token', '[A-Za-z0-9]{64}');
Route::post('/bookings/{token}/reschedule', [\App\Http\Controllers\Api\V1\PublicBookingController::class, 'reschedule'])
    ->middleware('throttle:10,1')->where('token', '[A-Za-z0-9]{64}');

    /*
     * What a guest may do, and nothing else.
     *
     * Everything a participant needs to be in a room — join, leave, keep
     * presence, signal, rename themselves, react, read the room, say something
     * — and none of what a host needs. Ending the meeting, admitting people,
     * host actions, approval settings and the file endpoints are all absent on
     * purpose, so a guest cannot reach them even if they know the URL.
     */
    Route::middleware('guest.meeting')->group(function () {
        Route::get('/guest/meetings/{meeting}', [\App\Http\Controllers\Api\V1\MeetingController::class, 'show']);
        Route::post('/guest/meetings/{meeting}/join', [\App\Http\Controllers\Api\V1\MeetingController::class, 'join']);
        Route::post('/guest/meetings/{meeting}/leave', [\App\Http\Controllers\Api\V1\MeetingController::class, 'leave']);
        Route::post('/guest/meetings/{meeting}/heartbeat', [\App\Http\Controllers\Api\V1\MeetingController::class, 'heartbeat']);
        Route::post('/guest/meetings/{meeting}/signal', [\App\Http\Controllers\Api\V1\MeetingController::class, 'signal'])
            ->middleware('throttle:240,1');
        Route::post('/guest/meetings/{meeting}/name', [\App\Http\Controllers\Api\V1\MeetingController::class, 'rename']);
        Route::post('/guest/meetings/{meeting}/react', [\App\Http\Controllers\Api\V1\MeetingController::class, 'react']);
        /*
         * Saying something is part of being in the room, and the panel is
         * already there for a guest — without this every message they sent
         * failed on a route that did not exist. The handler is the members'
         * one unchanged: it lets nobody chat who has not joined this meeting,
         * and a pass is only ever good for one meeting.
         *
         * Sharing a file stays out, as the note above says: that is the one
         * part of the panel a guest does not get.
         */
        Route::post('/guest/meetings/{meeting}/chat', [\App\Http\Controllers\Api\V1\MeetingController::class, 'chat'])
            ->middleware('throttle:60,1');
        // A guest in the room needs the same token a member does — they are
        // in the same meeting, and the pass they hold is only good for it.
        Route::post('/guest/meetings/{meeting}/realtime-token', [\App\Http\Controllers\Api\V1\MeetingController::class, 'realtimeToken']);
        Route::get('/guest/meetings/{meeting}/participants', [\App\Http\Controllers\Api\V1\MeetingController::class, 'participants']);
    });

    // --- Public auth (strictly throttled) --------------------------------
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
        Route::post('/auth/otp/request', [AuthController::class, 'requestLoginOtp']);
        // The second step of a password login, when a code was asked for.
        Route::post('/auth/login/verify', [AuthController::class, 'verifySignInCode']);
        Route::post('/auth/otp/login', [AuthController::class, 'loginWithOtp']);
    });

    Route::get('/auth/suggest-username', [AuthController::class, 'suggestUsername'])
        ->middleware('throttle:30,1');

    Route::get('/auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    // --- Authenticated ----------------------------------------------------
    // 60/min proved too tight for a realtime SPA: chat, badge and meeting
    // polling alone can approach it before the user does anything.
    //
    // 'verified.email' covers the whole group. Registration must hand back a
    // token (confirming the address is an authenticated call), and that token
    // used to unlock the entire app before the address was ever proven — so
    // the handful of routes an unverified account legitimately needs opt out
    // of it explicitly below, and nothing else does.
    Route::middleware(['auth:sanctum', 'active', 'verified.email', 'throttle:180,1'])->group(function () {

        // Session & account
        Route::post('/auth/logout', [AuthController::class, 'logout'])
            ->withoutMiddleware('verified.email');
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
        Route::post('/auth/email/verification-notification', [AuthController::class, 'resendVerification'])
            ->withoutMiddleware('verified.email')
            ->middleware('throttle:6,1');
        Route::post('/auth/mobile/verify', [AuthController::class, 'verifyMobile'])
            ->withoutMiddleware('verified.email')
            ->middleware('throttle:10,1');
        Route::post('/auth/mobile/resend-otp', [AuthController::class, 'resendMobileOtp'])
            ->withoutMiddleware('verified.email')
            ->middleware('throttle:5,1');
        Route::post('/auth/email/resend-otp', [AuthController::class, 'resendEmailOtp'])
            ->withoutMiddleware('verified.email')
            ->middleware('throttle:5,1');
        Route::post('/auth/email/verify-otp', [AuthController::class, 'verifyEmailOtp'])
            ->withoutMiddleware('verified.email')
            ->middleware('throttle:10,1');
        Route::get('/auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('/auth/sessions/{tokenId}', [AuthController::class, 'revokeSession']);
        Route::get('/auth/login-history', [AuthController::class, 'loginHistory']);

        // Me — readable while unverified so the client knows whose account it
        // is holding and can show the right address on the OTP screen.
        Route::get('/me', [ProfileController::class, 'me'])
            ->withoutMiddleware('verified.email');
        Route::put('/me/profile', [ProfileController::class, 'updateProfile']);
        Route::put('/me/settings', [ProfileController::class, 'updateSettings']);
        Route::post('/me/photo', [ProfileController::class, 'uploadPhoto']);
        // Closing an account for good. Throttled because it is irreversible
        // and there is no reason to attempt it more than once a minute.
        Route::delete('/me', [\App\Http\Controllers\Api\V1\AccountController::class, 'destroy'])
            ->middleware('throttle:5,1');
        Route::get('/me/app-id/qr', [AppIdController::class, 'myQr']);

        /*
         * The service panel: an application administering itself.
         *
         * Its own group rather than a corner of the authenticated one, because
         * the rule is the opposite of everything in there — these routes exist
         * only for accounts that are not people, and are invisible to the rest.
         */
        Route::prefix('service')->middleware('service.account')->group(function () {
            Route::get('/overview', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'overview']);
            Route::get('/tokens', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'tokens']);
            Route::post('/tokens', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'issueToken']);
            Route::get('/tokens/{id}/reveal', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'revealToken'])->whereNumber('id');
            Route::delete('/tokens/{id}', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'revokeToken'])
                ->whereNumber('id');
            Route::get('/connections', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'connections']);
            Route::delete('/connections/{uuid}', [\App\Http\Controllers\Api\V1\ServiceAccountController::class, 'disconnect']);
        });

        // App ID & connections
        Route::get('/app-id/search', [AppIdController::class, 'search']);
        Route::get('/connections', [ConnectionController::class, 'index']);
        Route::post('/connections', [ConnectionController::class, 'store']);
        Route::put('/connections/{connection}', [ConnectionController::class, 'respond']);
        Route::delete('/connections/{connection}', [ConnectionController::class, 'destroy']);
        Route::get('/blocks', [BlockController::class, 'index']);
        Route::post('/blocks', [BlockController::class, 'store']);
        Route::delete('/blocks/{appId}', [BlockController::class, 'destroy']);

        // Dashboard & sidebar badges
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/badges', [\App\Http\Controllers\Api\V1\BadgeController::class, 'index']);
        Route::post('/calls/seen', [\App\Http\Controllers\Api\V1\BadgeController::class, 'markCallsSeen']);

        // Web push subscriptions (system notifications on this device)
        // Your booking link: the page itself, and what people have booked.
        Route::get('/booking-page', [BookingPageController::class, 'show']);
        Route::put('/booking-page', [BookingPageController::class, 'update']);
        Route::get('/booking-page/bookings', [BookingPageController::class, 'bookings']);
        Route::post('/booking-page/bookings/{booking}/cancel', [BookingPageController::class, 'cancelBooking']);

        Route::get('/push/public-key', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'publicKey']);
        Route::post('/push/subscribe', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'subscribe']);
        Route::post('/push/unsubscribe', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'unsubscribe']);
        // The Android app's ring channel — see registerFcm for why it exists.
        Route::post('/push/fcm-token', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'registerFcm']);
        Route::delete('/push/fcm-token', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'unregisterFcm']);

        // Identity change requests (approval-based)
        Route::get('/me/change-requests', [\App\Http\Controllers\Api\V1\ChangeRequestController::class, 'index']);
        Route::post('/me/change-requests', [\App\Http\Controllers\Api\V1\ChangeRequestController::class, 'store'])
            ->middleware('throttle:10,1');

        // Categories
        Route::apiResource('categories', CategoryController::class);
        Route::post('/categories/{category}/share', [CategoryController::class, 'share']);

        // Tasks
        Route::apiResource('tasks', TaskController::class);
        Route::post('/tasks/{task}/status', [TaskController::class, 'updateStatus']);
        Route::post('/tasks/{task}/progress', [TaskController::class, 'updateProgress']);
        Route::post('/tasks/{task}/duplicate', [TaskController::class, 'duplicate']);
        Route::post('/tasks/{task}/toggle/{flag}', [TaskController::class, 'toggle']);
        Route::post('/tasks/{task}/assign', [TaskController::class, 'assign']);
        Route::get('/tasks/{task}/activity', [TaskController::class, 'activity']);
        Route::post('/tasks/{task}/checklist', [TaskController::class, 'addChecklistItem']);
        Route::put('/tasks/{task}/checklist/{itemId}', [TaskController::class, 'updateChecklistItem']);
        Route::delete('/tasks/{task}/checklist/{itemId}', [TaskController::class, 'deleteChecklistItem']);
        Route::post('/tasks/{task}/comments', [TaskController::class, 'addComment']);

        // Reminders
        Route::get('/reminders/upcoming', [ReminderController::class, 'upcoming']);
        Route::post('/reminders/{reminder}/snooze', [ReminderController::class, 'snooze']);
        Route::post('/reminders/{reminder}/acknowledge', [ReminderController::class, 'acknowledge']);

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/read-kinds', [NotificationController::class, 'markKindsRead']);
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

        // Events & calendar
        Route::get('/calendar/feed', [EventController::class, 'feed']);
        Route::get('/calendar/export.ics', [EventController::class, 'exportIcs']);
        Route::apiResource('events', EventController::class);
        Route::post('/events/{event}/respond', [EventController::class, 'respond']);

        // Notes
        Route::apiResource('notes', NoteController::class);
        Route::post('/notes/{note}/share', [NoteController::class, 'share']);
        Route::get('/notes/{note}/versions', [NoteController::class, 'versions']);

        Route::get('/connections/suggest', [\App\Http\Controllers\Api\V1\ConnectionController::class, 'suggest']);

        // Meetings (Meet-style link rooms)
        Route::get('/meetings', [\App\Http\Controllers\Api\V1\MeetingController::class, 'index']);
        Route::post('/meetings', [\App\Http\Controllers\Api\V1\MeetingController::class, 'store']);
        Route::get('/meetings/{meeting}', [\App\Http\Controllers\Api\V1\MeetingController::class, 'show']);
        Route::post('/meetings/{meeting}/join', [\App\Http\Controllers\Api\V1\MeetingController::class, 'join']);
        Route::post('/meetings/{meeting}/invite', [\App\Http\Controllers\Api\V1\MeetingController::class, 'invite']);
        Route::post('/meetings/{meeting}/leave', [\App\Http\Controllers\Api\V1\MeetingController::class, 'leave']);
        Route::post('/meetings/{meeting}/heartbeat', [\App\Http\Controllers\Api\V1\MeetingController::class, 'heartbeat']);
        Route::post('/meetings/{meeting}/host-action', [\App\Http\Controllers\Api\V1\MeetingController::class, 'hostAction']);
        Route::post('/meetings/{meeting}/end', [\App\Http\Controllers\Api\V1\MeetingController::class, 'end']);
        Route::delete('/meetings/{meeting}', [\App\Http\Controllers\Api\V1\MeetingController::class, 'destroy']);
        // A join token for the SFU. Only ever issued to somebody the room has
        // already admitted — see the controller.
        Route::post('/meetings/{meeting}/realtime-token', [\App\Http\Controllers\Api\V1\MeetingController::class, 'realtimeToken']);
        // WebRTC signalling posts one request per ICE candidate - with a TURN
        // server in play that is dozens per peer, so the ordinary per-minute
        // limit would drop candidates and strand the connection in "checking".
        Route::post('/meetings/{meeting}/signal', [\App\Http\Controllers\Api\V1\MeetingController::class, 'signal'])
            ->withoutMiddleware('throttle:180,1')
            ->middleware('throttle:1200,1');
        Route::post('/meetings/{meeting}/name', [\App\Http\Controllers\Api\V1\MeetingController::class, 'rename']);
        Route::post('/meetings/{meeting}/react', [\App\Http\Controllers\Api\V1\MeetingController::class, 'react']);
        Route::post('/meetings/{meeting}/admit', [\App\Http\Controllers\Api\V1\MeetingController::class, 'admit']);
        Route::put('/meetings/{meeting}/approval', [\App\Http\Controllers\Api\V1\MeetingController::class, 'setApproval']);
        Route::put('/meetings/{meeting}/passcode', [\App\Http\Controllers\Api\V1\MeetingController::class, 'setPasscode']);
        Route::post('/meetings/{meeting}/chat', [\App\Http\Controllers\Api\V1\MeetingController::class, 'chat']);
        Route::post('/meetings/{meeting}/chat-file', [\App\Http\Controllers\Api\V1\MeetingController::class, 'chatFile']);
        Route::get('/meetings/{meeting}/chat-file/{file}', [\App\Http\Controllers\Api\V1\MeetingController::class, 'chatFileDownload']);

        // Projects (money ledgers)
        Route::get('/projects', [\App\Http\Controllers\Api\V1\ProjectController::class, 'index']);
        Route::post('/projects', [\App\Http\Controllers\Api\V1\ProjectController::class, 'store']);
        Route::put('/projects/{project}', [\App\Http\Controllers\Api\V1\ProjectController::class, 'update']);
        Route::delete('/projects/{project}', [\App\Http\Controllers\Api\V1\ProjectController::class, 'destroy']);
        Route::get('/projects/{project}/entries', [\App\Http\Controllers\Api\V1\ProjectController::class, 'entries']);
        Route::post('/projects/{project}/entries', [\App\Http\Controllers\Api\V1\ProjectController::class, 'storeEntry']);
        Route::put('/projects/{project}/entries/{entry}', [\App\Http\Controllers\Api\V1\ProjectController::class, 'updateEntry']);
        Route::delete('/projects/{project}/entries/{entry}', [\App\Http\Controllers\Api\V1\ProjectController::class, 'destroyEntry']);
        Route::post('/projects/{project}/share', [\App\Http\Controllers\Api\V1\ProjectController::class, 'share']);
        Route::post('/projects/{project}/unshare', [\App\Http\Controllers\Api\V1\ProjectController::class, 'unshare']);
        Route::get('/projects/{project}/summary', [\App\Http\Controllers\Api\V1\ProjectController::class, 'summary']);
        Route::get('/projects/{project}/export', [\App\Http\Controllers\Api\V1\ProjectController::class, 'export']);
        Route::post('/projects/{project}/request-password-reset', [\App\Http\Controllers\Api\V1\ProjectController::class, 'requestPasswordReset']);
        Route::post('/projects/{project}/reset-password', [\App\Http\Controllers\Api\V1\ProjectController::class, 'resetPassword']);

        // Files & folders
        Route::get('/files/browse', [FileController::class, 'browse']);
        Route::get('/files/shared-with-me', [FileController::class, 'sharedWithMe']);
        Route::get('/files/usage', [FileController::class, 'usage']);
        Route::get('/files/trash', [FileController::class, 'trash']);
        Route::post('/files/upload', [FileController::class, 'upload']);
        Route::get('/files/{file}/download', [FileController::class, 'download']);
        Route::post('/files/{file}/share-link', [FileController::class, 'shareLink']);
        Route::delete('/files/{file}/share-link', [FileController::class, 'revokeShareLink']);
        Route::put('/files/{file}', [FileController::class, 'update']);
        Route::delete('/files/{file}', [FileController::class, 'destroy']);
        Route::post('/files/{uuid}/restore', [FileController::class, 'restore']);
        Route::delete('/files/{uuid}/force', [FileController::class, 'forceDelete']);
        Route::post('/files/{file}/share', [FileController::class, 'share']);
        Route::post('/folders', [FileController::class, 'storeFolder']);
        Route::post('/folders/{folder}/share', [FileController::class, 'shareFolder']);
        Route::get('/files/shared-by-me', [FileController::class, 'sharedByMe']);
        Route::post('/files/{file}/unshare', [FileController::class, 'unshare']);
        Route::post('/folders/{folder}/unshare', [FileController::class, 'unshareFolder']);
        Route::get('/folders/{folder}/shared-files', [FileController::class, 'sharedFolderFiles']);
        Route::put('/folders/{folder}', [FileController::class, 'updateFolder']);
        Route::delete('/folders/{folder}', [FileController::class, 'destroyFolder']);

        // Groups
        Route::apiResource('groups', GroupController::class);
        Route::post('/groups/{group}/members', [GroupController::class, 'addMember']);
        Route::put('/groups/{group}/members/{userUuid}', [GroupController::class, 'updateMember']);
        Route::delete('/groups/{group}/members/{userUuid}', [GroupController::class, 'removeMember']);
        Route::get('/groups/{group}/tasks', [GroupController::class, 'tasks']);

        // Chat
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/groups/{group}/conversation', [ConversationController::class, 'forGroup']);
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markRead']);
        // Fires on every few keystrokes, so it gets its own generous bucket
        // rather than eating the shared per-minute allowance.
        Route::post('/conversations/{conversation}/typing', [ConversationController::class, 'typing'])
            ->withoutMiddleware('throttle:180,1')
            ->middleware('throttle:600,1');
        // Disappearing messages: off unless somebody in the room says so.
        Route::post('/conversations/{conversation}/retention', [ConversationController::class, 'setRetention']);
        Route::post('/conversations/{conversation}/mute', [ConversationController::class, 'toggleMute']);
        Route::post('/conversations/{conversation}/archive', [ConversationController::class, 'toggleArchive']);
        Route::get('/conversations/{conversation}/members', [ConversationController::class, 'members']);
        Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
        Route::put('/conversations/{conversation}/messages/{message}', [MessageController::class, 'update']);
        Route::delete('/conversations/{conversation}/messages/{messageUuid}', [MessageController::class, 'destroy']);
        Route::post('/conversations/{conversation}/messages/{message}/react', [MessageController::class, 'react']);
        Route::get('/conversations/{conversation}/attachments/{attachmentId}', [MessageController::class, 'downloadAttachment']);

        // Calls
        /*
         * The ICE servers, which a meeting guest needs as much as a member —
         * a peer connection is built from them, so without this a guest who
         * had just been admitted got "Unauthenticated." the moment there was
         * somebody to connect to, and never saw the room. It only showed up
         * on admission because that is when the first peer appears.
         *
         * Resolved, not required: a signed-in member carries on to Sanctum
         * untouched, and anyone with neither is still turned away.
         */
        Route::get('/calls/config', [CallController::class, 'config'])
            ->middleware(\App\Http\Middleware\ResolveMeetingGuest::class);
        Route::get('/calls/history', [CallController::class, 'history']);
        // The recovery path for a ring whose websocket event never landed.
        Route::get('/calls/incoming', [CallController::class, 'incoming']);
        Route::post('/conversations/{conversation}/calls', [CallController::class, 'initiate']);
        Route::post('/calls/{call}/respond', [CallController::class, 'respond']);
        Route::post('/calls/{call}/end', [CallController::class, 'end']);
        Route::post('/calls/{call}/heartbeat', [CallController::class, 'heartbeat']);
        Route::post('/calls/{call}/signal', [CallController::class, 'signal'])
            ->withoutMiddleware('throttle:180,1')
            ->middleware('throttle:1200,1');
        Route::post('/calls/{call}/invite', [CallController::class, 'invite']);

        // Habits
        Route::get('/habits', [\App\Http\Controllers\Api\V1\HabitController::class, 'index']);
        Route::post('/habits', [\App\Http\Controllers\Api\V1\HabitController::class, 'store']);
        Route::put('/habits/{habit}', [\App\Http\Controllers\Api\V1\HabitController::class, 'update']);
        Route::delete('/habits/{habit}', [\App\Http\Controllers\Api\V1\HabitController::class, 'destroy']);
        Route::post('/habits/{habit}/log', [\App\Http\Controllers\Api\V1\HabitController::class, 'log']);

        // Goals
        Route::get('/goals', [\App\Http\Controllers\Api\V1\GoalController::class, 'index']);
        Route::post('/goals', [\App\Http\Controllers\Api\V1\GoalController::class, 'store']);
        Route::put('/goals/{goal}', [\App\Http\Controllers\Api\V1\GoalController::class, 'update']);
        Route::delete('/goals/{goal}', [\App\Http\Controllers\Api\V1\GoalController::class, 'destroy']);
        Route::post('/goals/{goal}/milestones', [\App\Http\Controllers\Api\V1\GoalController::class, 'addMilestone']);
        Route::post('/goals/{goal}/milestones/{milestoneId}/toggle', [\App\Http\Controllers\Api\V1\GoalController::class, 'toggleMilestone']);
        Route::delete('/goals/{goal}/milestones/{milestoneId}', [\App\Http\Controllers\Api\V1\GoalController::class, 'deleteMilestone']);

        // Bills
        Route::get('/bills', [\App\Http\Controllers\Api\V1\BillController::class, 'index']);
        Route::post('/bills', [\App\Http\Controllers\Api\V1\BillController::class, 'store']);
        Route::put('/bills/{bill}', [\App\Http\Controllers\Api\V1\BillController::class, 'update']);
        Route::delete('/bills/{bill}', [\App\Http\Controllers\Api\V1\BillController::class, 'destroy']);
        Route::post('/bills/{bill}/pay', [\App\Http\Controllers\Api\V1\BillController::class, 'markPaid']);

        // Subscription & billing
        Route::get('/subscription', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'mySubscription']);
        Route::post('/subscription/quote', [\App\Http\Controllers\Api\V1\BillingController::class, 'quote']);
        Route::post('/subscription/checkout', [\App\Http\Controllers\Api\V1\BillingController::class, 'checkout'])
            ->middleware('throttle:10,1');
        Route::post('/subscription/cancel', [\App\Http\Controllers\Api\V1\BillingController::class, 'cancelSubscription']);
        Route::post('/payments/{order}/verify', [\App\Http\Controllers\Api\V1\BillingController::class, 'verifyOrder'])
            ->middleware('throttle:30,1');
        Route::get('/payments', [\App\Http\Controllers\Api\V1\BillingController::class, 'payments']);
        Route::get('/invoices', [\App\Http\Controllers\Api\V1\BillingController::class, 'invoices']);
        Route::get('/invoices/{invoice}', [\App\Http\Controllers\Api\V1\BillingController::class, 'invoiceView']);

        // Voice assistant
        Route::post('/voice/interpret', [\App\Http\Controllers\Api\V1\VoiceController::class, 'interpret']);
        Route::post('/voice/transcribe', [\App\Http\Controllers\Api\V1\VoiceController::class, 'transcribe']);

        // Reports
        Route::get('/reports/summary', [ReportController::class, 'summary']);
        Route::get('/reports/productivity', [ReportController::class, 'productivity']);
        Route::get('/reports/export.csv', [ReportController::class, 'exportCsv']);

        // Report a user or message (moderation intake)
        Route::post('/reports', [\App\Http\Controllers\Api\V1\ReportUserController::class, 'store'])
            ->middleware('throttle:10,1');

        // --- Internal Work (Admin / Subadmin / Salesperson) ----------------
        Route::prefix('admin/internal')->middleware(['role:admin,super_admin,subadmin,salesperson', 'module:internal,view'])->group(function () {
            Route::post('lookup', [\App\Http\Controllers\Api\V1\Admin\InternalNoteController::class, 'lookup']);
            Route::delete('notes/{uuid}', [\App\Http\Controllers\Api\V1\Admin\InternalNoteController::class, 'destroy']);
            Route::get('/threads', [\App\Http\Controllers\Api\V1\Admin\InternalNoteController::class, 'threads']);
            Route::get('/users/{user}/notes', [\App\Http\Controllers\Api\V1\Admin\InternalNoteController::class, 'index']);
            Route::post('/users/{user}/notes', [\App\Http\Controllers\Api\V1\Admin\InternalNoteController::class, 'store']);
        });

        // --- Admin + Subadmin (module-gated) ------------------------------
        Route::prefix('admin')->middleware('role:admin,super_admin,subadmin')->group(function () {
            // Approvals (subadmin default-granted)
            Route::get('/change-requests', [\App\Http\Controllers\Api\V1\ChangeRequestController::class, 'pending'])
                ->middleware('module:approvals,view');
            Route::post('/change-requests/{changeRequest}', [\App\Http\Controllers\Api\V1\ChangeRequestController::class, 'review'])
                ->middleware('module:approvals,edit');

            // Users (subadmins need a grant)
            Route::get('/users', [AdminUserController::class, 'index'])->middleware('module:users,view');
            Route::get('/users/{user}/summary', [AdminUserController::class, 'summary'])->middleware('module:users,view');
            Route::get('/users/{user}/call-records', [AdminUserController::class, 'callRecords'])->middleware('module:users,view');
            Route::get('/users/{user}/message-records', [AdminUserController::class, 'messageRecords'])->middleware('module:users,view');
            Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend'])->middleware('module:users,delete');
            Route::post('/users/{user}/activate', [AdminUserController::class, 'activate'])->middleware('module:users,edit');

            // Moderation
            Route::get('/reports', [\App\Http\Controllers\Api\V1\Admin\ModerationController::class, 'index'])
                ->middleware('module:moderation,view');
            Route::post('/reports/{report}/act', [\App\Http\Controllers\Api\V1\Admin\ModerationController::class, 'act'])
                ->middleware('module:moderation,edit'); // delete-level actions re-checked in controller

            // Activity & logins
            Route::get('/active-members', [AdminUserController::class, 'activeMembers'])->middleware('module:activity,view');
            Route::get('/login-histories', [RoleController::class, 'loginHistories'])->middleware('module:activity,view');
            Route::get('/audit-logs', [RoleController::class, 'auditLogs'])->middleware('module:activity,view');
        });

        // --- Salesperson workspace (also open to admins) ------------------
        Route::prefix('admin/sales')->middleware('role:salesperson,admin,super_admin')->group(function () {
            Route::get('/my-users', [\App\Http\Controllers\Api\V1\Admin\SalespersonController::class, 'myUsers']);
            Route::get('/users/{user}/summary', [\App\Http\Controllers\Api\V1\Admin\SalespersonController::class, 'summary']);
        });

        // --- Admin only ---------------------------------------------------
        Route::prefix('admin')->middleware('role:admin,super_admin')->group(function () {
            Route::get('/stats', [StatsController::class, 'index']);

            /*
             * Service accounts, seen from outside.
             *
             * Admin-only rather than shared with subadmins: a token issued here
             * can send as an account everybody trusts, and revoking one cuts an
             * integration off mid-flight. Neither is a moderation decision.
             */
            Route::get('/service-accounts', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'index']);
            Route::post('/service-accounts', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'store']);
            Route::post('/service-accounts/{uuid}/tokens', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'issueToken']);
            Route::get('/service-accounts/{uuid}/tokens', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'tokens']);
            Route::get('/service-accounts/{uuid}/tokens/{id}/reveal', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'revealToken'])->whereNumber('id');
            Route::delete('/service-accounts/{uuid}/tokens/{id}', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'revokeToken'])->whereNumber('id');
            Route::post('/service-accounts/{uuid}/revoke-tokens', [\App\Http\Controllers\Api\V1\Admin\ServiceAccountAdminController::class, 'revokeTokens']);
            Route::post('/users', [AdminUserController::class, 'store']);
            Route::get('/users/{user}', [AdminUserController::class, 'show']);
            Route::put('/users/{user}', [AdminUserController::class, 'update']);
            Route::post('/users/{user}/roles', [AdminUserController::class, 'syncRoles']);
            Route::get('/users/{user}/module-permissions', [AdminUserController::class, 'modulePermissions']);
            Route::put('/users/{user}/module-permissions', [AdminUserController::class, 'updateModulePermissions']);
            Route::post('/users/{user}/app-id/regenerate', [AdminUserController::class, 'regenerateAppId']);
            Route::get('/users/{user}/otp', [AdminUserController::class, 'activeOtp']);
            Route::post('/users/{user}/otp/resend', [AdminUserController::class, 'resendOtp']);
            Route::post('/users/{user}/verify-email', [AdminUserController::class, 'verifyEmail']);
            Route::get('/salespeople', [AdminUserController::class, 'salespeople']);
            Route::post('/users/{user}/salesperson', [AdminUserController::class, 'assignSalesperson']);
            Route::get('/users/{user}/locked-projects', [AdminUserController::class, 'lockedProjects']);
            Route::post('/projects/{uuid}/send-password-reset', [AdminUserController::class, 'sendProjectPasswordReset']);
            // Live meetings: what is running right now, and stopping it.
            // What is breaking in people's browsers.
            Route::get('/client-errors', [\App\Http\Controllers\Api\V1\ClientErrorController::class, 'index']);
            Route::post('/client-errors/{clientError}/resolve', [\App\Http\Controllers\Api\V1\ClientErrorController::class, 'resolve']);
            Route::get('/live-meetings', [\App\Http\Controllers\Api\V1\Admin\LiveMeetingController::class, 'index']);
            Route::delete('/live-meetings/{meeting}', [\App\Http\Controllers\Api\V1\Admin\LiveMeetingController::class, 'destroy']);
            Route::get('/settings', [AdminUserController::class, 'settings']);
            Route::put('/settings', [AdminUserController::class, 'updateSettings']);
            Route::get('/plans', [\App\Http\Controllers\Api\V1\Admin\PlanController::class, 'index']);
            Route::put('/plans/{plan}', [\App\Http\Controllers\Api\V1\Admin\PlanController::class, 'update']);
            Route::post('/users/{user}/plan', [\App\Http\Controllers\Api\V1\Admin\PlanController::class, 'assign']);
            Route::get('/roles', [RoleController::class, 'roles']);
            Route::get('/permissions', [RoleController::class, 'permissions']);

            // Billing administration
            Route::get('/billing/payments', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'payments']);
            Route::get('/billing/webhooks', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'webhooks']);
            Route::get('/billing/coupons', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'coupons']);
            Route::post('/billing/coupons', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'storeCoupon']);
            Route::put('/billing/coupons/{coupon:code}', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'updateCoupon']);
            Route::get('/billing/refunds', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'refunds']);
            Route::post('/billing/payments/{payment}/refund', [\App\Http\Controllers\Api\V1\Admin\BillingAdminController::class, 'createRefund']);
        });

        /*
         * --- CRM addon -----------------------------------------------------
         *
         * A separate product living behind its own door. Nothing here touches
         * the personal Netvork surface: access requires an active crm_members
         * row (crm.member middleware), and per-module rights ride on it.
         * Super admins manage which organizations have the addon at all.
         */
        Route::prefix('crm')->group(function () {
            // Sidebar bootstrap — "not a member" is an answer, not an error.
            Route::get('/me', [\App\Http\Controllers\Api\V1\Crm\CrmController::class, 'me']);

            Route::middleware('crm.member')->group(function () {
                Route::get('/masters', [\App\Http\Controllers\Api\V1\Crm\CrmController::class, 'masters']);
                Route::get('/dashboard', [\App\Http\Controllers\Api\V1\Crm\CrmController::class, 'dashboard']);
                Route::get('/badges', [\App\Http\Controllers\Api\V1\Crm\CrmController::class, 'badges']);
            // "I have looked at this section" — the sidebar badge goes quiet.
            Route::post('/sections/{section}/seen', [\App\Http\Controllers\Api\V1\Crm\CrmController::class, 'markSectionSeen']);
            });

            // Employees
            // One's own record: profile, letters basis and documents — the
            // person's right, no employees module right needed.
            Route::get('/my/profile', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'myProfile'])->middleware('crm.member');
            Route::get('/my/documents/{documentUuid}', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'downloadMyDocument'])->middleware('crm.member');

            Route::middleware('crm.member:employees,view')->group(function () {
                Route::get('/employees', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'index']);
                Route::get('/employees/{uuid}', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'show']);
                Route::get('/employees/{uuid}/documents/{documentUuid}', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'downloadDocument']);
            });
            /*
             * Everything that CHANGES an employee — registering, profile
             * edits, salary, documents, deactivation — is company authority,
             * not a grantable right. A Team Head reads their subtree above;
             * only an admin or subadmin may write here.
             */
            Route::middleware(['crm.member', 'crm.manager'])->group(function () {
                // Fetch an existing Netvork account to register as an employee.
                Route::get('/employees-lookup', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'lookupAccount']);
                Route::post('/employees', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'store']);
                Route::put('/employees/{uuid}', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'update']);
                Route::post('/employees/{uuid}/salary', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'addSalary']);
                Route::delete('/employees/{uuid}/salary/{recordId}', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'deleteSalary']);
                // Compensation: the CTC structure, the incentive plan, and
                // loans — the employee's terms, so company authority too.
                Route::get('/employees/{uuid}/compensation', [\App\Http\Controllers\Api\V1\Crm\CompensationController::class, 'show']);
                Route::post('/employees/{uuid}/compensation/structures', [\App\Http\Controllers\Api\V1\Crm\CompensationController::class, 'storeStructure']);
                Route::delete('/employees/{uuid}/compensation/structures/{structureUuid}', [\App\Http\Controllers\Api\V1\Crm\CompensationController::class, 'deleteStructure']);
                Route::post('/employees/{uuid}/compensation/plans', [\App\Http\Controllers\Api\V1\Crm\CompensationController::class, 'storePlan']);
                Route::delete('/employees/{uuid}/compensation/plans/{planUuid}', [\App\Http\Controllers\Api\V1\Crm\CompensationController::class, 'deletePlan']);
                Route::get('/employees/{uuid}/compensation/incentive-preview', [\App\Http\Controllers\Api\V1\Crm\CompensationController::class, 'preview']);
                Route::post('/employees/{uuid}/compensation/payment-gate', [\App\Http\Controllers\Api\V1\Crm\CompensationController::class, 'setPaymentGate']);
                Route::post('/employees/{uuid}/compensation/loans', [\App\Http\Controllers\Api\V1\Crm\CompensationController::class, 'storeLoan']);
                Route::post('/employees/{uuid}/compensation/loans/{loanUuid}/repay', [\App\Http\Controllers\Api\V1\Crm\CompensationController::class, 'repayLoan']);
                Route::post('/employees/{uuid}/compensation/loans/{loanUuid}/close', [\App\Http\Controllers\Api\V1\Crm\CompensationController::class, 'closeLoan']);
                Route::post('/employees/{uuid}/documents', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'uploadDocument']);
                Route::delete('/employees/{uuid}/documents/{documentUuid}', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'deleteDocument']);
                Route::delete('/employees/{uuid}', [\App\Http\Controllers\Api\V1\Crm\EmployeeController::class, 'destroy']);
            });

            // Clients
            Route::middleware('crm.member:clients,view')->group(function () {
                Route::get('/clients', [\App\Http\Controllers\Api\V1\Crm\ClientController::class, 'index']);
                Route::get('/clients/options', [\App\Http\Controllers\Api\V1\Crm\ClientController::class, 'options']);
                Route::get('/clients/{uuid}', [\App\Http\Controllers\Api\V1\Crm\ClientController::class, 'show']);
            });
            Route::post('/clients', [\App\Http\Controllers\Api\V1\Crm\ClientController::class, 'store'])
                ->middleware('crm.member:clients,create');
            // "This client already exists — let me in on it."
            Route::get('/client-requests', [\App\Http\Controllers\Api\V1\Crm\ClientController::class, 'accessRequests'])
                ->middleware('crm.member');
            Route::post('/client-requests/{uuid}/decide', [\App\Http\Controllers\Api\V1\Crm\ClientController::class, 'decideAccessRequest'])
                ->middleware('crm.member:clients,edit');
            Route::put('/clients/{uuid}', [\App\Http\Controllers\Api\V1\Crm\ClientController::class, 'update'])
                ->middleware('crm.member:clients,edit');
            // Hand a client to another employee (managers only, enforced in
            // the controller — the rights matrix cannot express "not a lead").
            Route::post('/clients/{uuid}/transfer', [\App\Http\Controllers\Api\V1\Crm\ClientController::class, 'transfer'])
                ->middleware('crm.member:clients,edit');
            Route::delete('/clients/{uuid}', [\App\Http\Controllers\Api\V1\Crm\ClientController::class, 'destroy'])
                ->middleware('crm.member:clients,delete');

            // Lead Generation + Lead Log (the log is a view over the trail)
            Route::middleware('crm.member:leads,view')->group(function () {
                Route::get('/leads', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'index']);
                Route::get('/lead-log', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'log']);
                Route::get('/leads/{uuid}', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'show']);
                // Lead Duplication: the requests, own-only for non-deciders.
                Route::get('/lead-requests', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'accessRequests']);
                // What the follow-up popup nags about.
                Route::get('/leads-due', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'due']);
                // What the new-lead popup nags about.
                Route::get('/leads-new', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'fresh']);
            });
            Route::post('/leads', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'store'])
                ->middleware('crm.member:leads,create');
            Route::middleware('crm.member:leads,edit')->group(function () {
                Route::put('/leads/{uuid}', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'update']);
                // Deciding a duplication, moving or sharing a lead: managers,
            // checked in the controller.
            Route::post('/lead-requests/{uuid}/decide', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'decideAccessRequest'])
                ->middleware('crm.member:leads,edit');
            Route::post('/leads/{uuid}/transfer', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'transfer'])
                ->middleware('crm.member:leads,edit');
            // The reshuffle, and the client who came back.
            Route::post('/leads/bulk-transfer', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'bulkTransfer'])
                ->middleware('crm.member:leads,edit');
            Route::post('/leads/bulk-share', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'bulkShare'])
                ->middleware('crm.member:leads,edit');
            Route::post('/leads/{uuid}/reopen', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'reopen'])
                ->middleware('crm.member:leads,edit');
            Route::post('/leads/{uuid}/share', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'share'])
                ->middleware('crm.member:leads,edit');
            Route::post('/leads/{uuid}/followup', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'followUp']);
                Route::post('/leads/{uuid}/convert', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'convert']);
            });
            Route::delete('/leads/{uuid}', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'destroy'])
                ->middleware('crm.member:leads,delete');
            // Urgency is anyone-on-the-lead's; settling a duplicate is the
            // Admin's — both gates live in the controller, view scope here.
            Route::post('/leads/{uuid}/urgent', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'setUrgent'])
                ->middleware('crm.member:leads,view');
            Route::post('/leads/{uuid}/settle-duplicate', [\App\Http\Controllers\Api\V1\Crm\LeadController::class, 'settleDuplicate'])
                ->middleware('crm.member:leads,view');

            // Targets: read for members (own row), write for managers
            Route::get('/targets', [\App\Http\Controllers\Api\V1\Crm\TargetController::class, 'index'])
                ->middleware('crm.member:targets,view');
            Route::get('/targets/growth', [\App\Http\Controllers\Api\V1\Crm\TargetController::class, 'growth'])
                ->middleware('crm.member:targets,view');
            // Setting targets (and copying a month) is company authority —
            // the Admin and Subadmin, never a granted right.
            Route::middleware(['crm.member', 'crm.manager'])->group(function () {
                Route::post('/targets', [\App\Http\Controllers\Api\V1\Crm\TargetController::class, 'upsert']);
                Route::post('/targets/copy-previous', [\App\Http\Controllers\Api\V1\Crm\TargetController::class, 'copyPrevious']);
            });

            // Contests: every member plays; editing needs the module right
            Route::middleware('crm.member')->group(function () {
                Route::get('/contests', [\App\Http\Controllers\Api\V1\Crm\ContestController::class, 'index']);
                Route::get('/contests/{uuid}', [\App\Http\Controllers\Api\V1\Crm\ContestController::class, 'show']);
                Route::post('/contests/{uuid}/answer', [\App\Http\Controllers\Api\V1\Crm\ContestController::class, 'answer']);
                Route::get('/contests/{uuid}/results', [\App\Http\Controllers\Api\V1\Crm\ContestController::class, 'results']);
            });
            // Contests are company authority: the Admin/Subadmin set,
            // edit, replicate, grade and delete them. Everyone plays.
            Route::post('/contests', [\App\Http\Controllers\Api\V1\Crm\ContestController::class, 'store'])
                ->middleware(['crm.member', 'crm.manager']);
            Route::post('/contests/{uuid}/replicate', [\App\Http\Controllers\Api\V1\Crm\ContestController::class, 'replicate'])
                ->middleware(['crm.member', 'crm.manager']);
            Route::middleware(['crm.member', 'crm.manager'])->group(function () {
                Route::put('/contests/{uuid}', [\App\Http\Controllers\Api\V1\Crm\ContestController::class, 'update']);
                Route::post('/contests/{uuid}/answers/{answerId}/grade', [\App\Http\Controllers\Api\V1\Crm\ContestController::class, 'gradeAnswer']);
            });
            Route::delete('/contests/{uuid}', [\App\Http\Controllers\Api\V1\Crm\ContestController::class, 'destroy'])
                ->middleware(['crm.member', 'crm.manager']);

            // DWR: everyone submits their own; the org-wide window is scoped
            // inside the controller (dwr view right or admin/subadmin).
            Route::middleware('crm.member')->group(function () {
                Route::get('/dwr', [\App\Http\Controllers\Api\V1\Crm\DwrController::class, 'index']);
                Route::get('/dwr/stats', [\App\Http\Controllers\Api\V1\Crm\DwrController::class, 'stats']);
                Route::get('/dwr/my-kpis', [\App\Http\Controllers\Api\V1\Crm\DwrController::class, 'myKpis']);
                Route::get('/dwr/prefill', [\App\Http\Controllers\Api\V1\Crm\DwrController::class, 'prefill']);
                Route::post('/dwr', [\App\Http\Controllers\Api\V1\Crm\DwrController::class, 'store']);
                Route::get('/dwr/{uuid}', [\App\Http\Controllers\Api\V1\Crm\DwrController::class, 'show']);
            });
            // KPI catalog + per-employee assignment: company authority too.
            Route::middleware(['crm.member', 'crm.manager'])->group(function () {
                Route::get('/dwr-parameters', [\App\Http\Controllers\Api\V1\Crm\DwrController::class, 'parameters']);
                Route::post('/dwr-parameters', [\App\Http\Controllers\Api\V1\Crm\DwrController::class, 'storeParameter']);
                Route::put('/dwr-parameters/{id}', [\App\Http\Controllers\Api\V1\Crm\DwrController::class, 'updateParameter']);
                Route::get('/dwr-assignments/{memberUuid}', [\App\Http\Controllers\Api\V1\Crm\DwrController::class, 'assignments']);
                Route::put('/dwr-assignments/{memberUuid}', [\App\Http\Controllers\Api\V1\Crm\DwrController::class, 'saveAssignments']);
            });

            // Punch: everyone punches themselves; the report is scoped inside.
            Route::middleware('crm.member')->group(function () {
                Route::get('/punch/today', [\App\Http\Controllers\Api\V1\Crm\PunchController::class, 'today']);
                Route::post('/punch/in', [\App\Http\Controllers\Api\V1\Crm\PunchController::class, 'punchIn']);
                Route::post('/punch/out', [\App\Http\Controllers\Api\V1\Crm\PunchController::class, 'punchOut']);
                Route::get('/punch', [\App\Http\Controllers\Api\V1\Crm\PunchController::class, 'index']);
            });
            Route::put('/punch/{id}', [\App\Http\Controllers\Api\V1\Crm\PunchController::class, 'update'])
                ->middleware('crm.member:punch,edit');

            // Proforma + tax invoices (one engine; the kind rides on the row)
            Route::middleware('crm.member:invoices,view')->group(function () {
                Route::get('/invoices', [\App\Http\Controllers\Api\V1\Crm\InvoiceController::class, 'index']);
                Route::get('/invoices/{uuid}', [\App\Http\Controllers\Api\V1\Crm\InvoiceController::class, 'show']);
                // Invoice Log / Proforma Log — the trail, same ledger window.
                Route::get('/invoice-log', [\App\Http\Controllers\Api\V1\Crm\InvoiceController::class, 'log']);
                // The paper copy, rendered server-side (print dialogs are not
                // available in every browser the CRM runs in).
                Route::post('/invoices/{uuid}/email', [\App\Http\Controllers\Api\V1\Crm\InvoiceController::class, 'email']);
                Route::get('/exports/invoices', [\App\Http\Controllers\Api\V1\Crm\ExportController::class, 'invoices']);
                Route::get('/exports/payments', [\App\Http\Controllers\Api\V1\Crm\ExportController::class, 'payments']);
                Route::get('/invoices/{uuid}/pdf', [\App\Http\Controllers\Api\V1\Crm\InvoiceController::class, 'pdf']);
            });
            Route::post('/invoices', [\App\Http\Controllers\Api\V1\Crm\InvoiceController::class, 'store'])
                ->middleware('crm.member:invoices,create');
            Route::put('/invoices/{uuid}', [\App\Http\Controllers\Api\V1\Crm\InvoiceController::class, 'update'])
                ->middleware('crm.member:invoices,edit');
            Route::post('/invoices/{uuid}/cancel', [\App\Http\Controllers\Api\V1\Crm\InvoiceController::class, 'cancel'])
                ->middleware('crm.member:invoices,delete');
            Route::post('/invoices/{uuid}/convert', [\App\Http\Controllers\Api\V1\Crm\InvoiceController::class, 'convert'])
                ->middleware('crm.member:invoices,create');
            Route::post('/invoices/{uuid}/payments', [\App\Http\Controllers\Api\V1\Crm\InvoiceController::class, 'addPayment'])
                ->middleware('crm.member:payments,create');
            Route::put('/invoices/{uuid}/payments/{paymentId}/charge', [\App\Http\Controllers\Api\V1\Crm\InvoiceController::class, 'setPaymentCharge'])
                ->middleware('crm.member:payments,edit');
            Route::delete('/invoices/{uuid}/payments/{paymentId}', [\App\Http\Controllers\Api\V1\Crm\InvoiceController::class, 'deletePayment'])
                ->middleware('crm.member:payments,delete');

            // Payment inbox: bank credits logged, then claimed onto invoices
            // Money still owed, and the chasing of it.
            Route::middleware('crm.member:payments,view')->group(function () {
                Route::get('/payments/outstanding', [\App\Http\Controllers\Api\V1\Crm\PaymentReminderController::class, 'outstanding']);
                Route::get('/invoices/{invoiceUuid}/reminders', [\App\Http\Controllers\Api\V1\Crm\PaymentReminderController::class, 'index']);
            });
            Route::post('/invoices/{invoiceUuid}/reminders', [\App\Http\Controllers\Api\V1\Crm\PaymentReminderController::class, 'store'])
                ->middleware('crm.member:payments,create');

            Route::middleware('crm.member:payments,view')->group(function () {
                Route::get('/payments', [\App\Http\Controllers\Api\V1\Crm\PaymentInboxController::class, 'index']);
            });
            Route::post('/payments', [\App\Http\Controllers\Api\V1\Crm\PaymentInboxController::class, 'store'])
                ->middleware('crm.member:payments,create');
            Route::middleware('crm.member:payments,edit')->group(function () {
                Route::put('/payments/{uuid}', [\App\Http\Controllers\Api\V1\Crm\PaymentInboxController::class, 'update']);
                Route::post('/payments/{uuid}/claim', [\App\Http\Controllers\Api\V1\Crm\PaymentInboxController::class, 'claim']);
                // Settling and correcting are the Admin's, checked in the
                // controller — the rights matrix cannot say "not a lead".
                Route::post('/payments/{uuid}/settle', [\App\Http\Controllers\Api\V1\Crm\PaymentInboxController::class, 'settle']);
                Route::post('/payments/{uuid}/reclaim', [\App\Http\Controllers\Api\V1\Crm\PaymentInboxController::class, 'reclaim']);
                Route::post('/payments/{uuid}/unclaim', [\App\Http\Controllers\Api\V1\Crm\PaymentInboxController::class, 'unclaim']);
            });
            Route::delete('/payments/{uuid}', [\App\Http\Controllers\Api\V1\Crm\PaymentInboxController::class, 'destroy'])
                ->middleware('crm.member:payments,delete');

            // Vendors: registered before any bill can name them, exactly as
            // a client is registered before an invoice. They ride the
            // expenses rights, being the same head of work.
            Route::middleware('crm.member:expenses,view')->group(function () {
                Route::get('/vendors', [\App\Http\Controllers\Api\V1\Crm\VendorController::class, 'index']);
                Route::get('/vendors/options', [\App\Http\Controllers\Api\V1\Crm\VendorController::class, 'options']);
                Route::get('/vendors/{uuid}', [\App\Http\Controllers\Api\V1\Crm\VendorController::class, 'show']);
            });
            Route::post('/vendors', [\App\Http\Controllers\Api\V1\Crm\VendorController::class, 'store'])
                ->middleware('crm.member:expenses,create');
            Route::put('/vendors/{uuid}', [\App\Http\Controllers\Api\V1\Crm\VendorController::class, 'update'])
                ->middleware('crm.member:expenses,edit');
            Route::delete('/vendors/{uuid}', [\App\Http\Controllers\Api\V1\Crm\VendorController::class, 'destroy'])
                ->middleware('crm.member:expenses,delete');

            // HR Policy: the house rules everyone is measured against.
            // Readable by all — rules people are judged by should be
            // visible to them — and the Admin's alone to change.
            Route::middleware('crm.member')->group(function () {
                Route::get('/hr-policy', [\App\Http\Controllers\Api\V1\Crm\HrPolicyController::class, 'show']);
                Route::get('/hr-policy/holidays', [\App\Http\Controllers\Api\V1\Crm\HrPolicyController::class, 'holidays']);
                Route::get('/hr-policy/leave-accounts', [\App\Http\Controllers\Api\V1\Crm\HrPolicyController::class, 'leaveAccounts']);
                Route::get('/hr-policy/leave-accounts/{memberUuid}', [\App\Http\Controllers\Api\V1\Crm\HrPolicyController::class, 'leaveLedger']);
                Route::put('/hr-policy', [\App\Http\Controllers\Api\V1\Crm\HrPolicyController::class, 'update']);
                Route::put('/hr-policy/holidays', [\App\Http\Controllers\Api\V1\Crm\HrPolicyController::class, 'saveHolidays']);
                Route::delete('/hr-policy/holidays/{uuid}', [\App\Http\Controllers\Api\V1\Crm\HrPolicyController::class, 'deleteHoliday']);
                Route::post('/hr-policy/accrual', [\App\Http\Controllers\Api\V1\Crm\HrPolicyController::class, 'runAccrual']);
                Route::post('/hr-policy/year-end', [\App\Http\Controllers\Api\V1\Crm\HrPolicyController::class, 'runYearEnd']);
            });

            // Expenses
            Route::middleware('crm.member:expenses,view')->group(function () {
                Route::get('/expenses', [\App\Http\Controllers\Api\V1\Crm\ExpenseController::class, 'index']);
                Route::get('/expenses/{uuid}/bills/{documentUuid}', [\App\Http\Controllers\Api\V1\Crm\ExpenseController::class, 'downloadBill']);
            });
            Route::post('/expenses', [\App\Http\Controllers\Api\V1\Crm\ExpenseController::class, 'store'])
                ->middleware('crm.member:expenses,create');
            Route::middleware('crm.member:expenses,edit')->group(function () {
                Route::put('/expenses/{uuid}', [\App\Http\Controllers\Api\V1\Crm\ExpenseController::class, 'update']);
                Route::post('/expenses/{uuid}/payments', [\App\Http\Controllers\Api\V1\Crm\ExpenseController::class, 'pay']);
                Route::delete('/expenses/{uuid}/payments/{paymentUuid}', [\App\Http\Controllers\Api\V1\Crm\ExpenseController::class, 'unpay']);
                Route::post('/expenses/{uuid}/bills', [\App\Http\Controllers\Api\V1\Crm\ExpenseController::class, 'uploadBill']);
                Route::delete('/expenses/{uuid}/bills/{documentUuid}', [\App\Http\Controllers\Api\V1\Crm\ExpenseController::class, 'deleteBill']);
            });
            Route::delete('/expenses/{uuid}', [\App\Http\Controllers\Api\V1\Crm\ExpenseController::class, 'destroy'])
                ->middleware('crm.member:expenses,delete');

            // Salary: everyone reads (own slips scoped inside); managing needs rights
            Route::get('/salary', [\App\Http\Controllers\Api\V1\Crm\SalaryController::class, 'index'])
                ->middleware('crm.member');
            Route::post('/salary/generate', [\App\Http\Controllers\Api\V1\Crm\SalaryController::class, 'generate'])
                ->middleware('crm.member:salary,create');
            // The Incentives ledger: own by default; another's needs the
            // salary right. Rulings (hold/cancel/release) are manager acts.
            // Office Assets: everyone reads their own kit; the full
            // register needs the assets right (managers hold it by role).
            Route::get('/assets', [\App\Http\Controllers\Api\V1\Crm\AssetController::class, 'index'])->middleware('crm.member');
            Route::get('/assets/mine', [\App\Http\Controllers\Api\V1\Crm\AssetController::class, 'mine'])->middleware('crm.member');
            Route::get('/assets/member/{memberUuid}', [\App\Http\Controllers\Api\V1\Crm\AssetController::class, 'forMember'])->middleware('crm.member');
            Route::post('/assets', [\App\Http\Controllers\Api\V1\Crm\AssetController::class, 'store'])->middleware('crm.member');
            Route::put('/assets/{uuid}', [\App\Http\Controllers\Api\V1\Crm\AssetController::class, 'update'])->middleware('crm.member');
            Route::post('/assets/{uuid}/allocate', [\App\Http\Controllers\Api\V1\Crm\AssetController::class, 'allocate'])->middleware('crm.member');
            Route::post('/assets/{uuid}/return', [\App\Http\Controllers\Api\V1\Crm\AssetController::class, 'returnAsset'])->middleware('crm.member');
            Route::post('/assets/{uuid}/repaired', [\App\Http\Controllers\Api\V1\Crm\AssetController::class, 'repaired'])->middleware('crm.member');
            Route::delete('/assets/{uuid}', [\App\Http\Controllers\Api\V1\Crm\AssetController::class, 'destroy'])->middleware('crm.member');
            Route::get('/assets/{uuid}/history', [\App\Http\Controllers\Api\V1\Crm\AssetController::class, 'history'])->middleware('crm.member');
            // The P&L: the Admin's page alone (gate inside).
            Route::get('/pl', [\App\Http\Controllers\Api\V1\Crm\PlController::class, 'index'])->middleware('crm.member');
            Route::get('/pl/config', [\App\Http\Controllers\Api\V1\Crm\PlController::class, 'config'])->middleware('crm.member');
            Route::put('/pl/config', [\App\Http\Controllers\Api\V1\Crm\PlController::class, 'saveConfig'])->middleware('crm.member');
            Route::post('/pl/lines', [\App\Http\Controllers\Api\V1\Crm\PlController::class, 'storeLine'])->middleware('crm.member');
            Route::delete('/pl/lines/{id}', [\App\Http\Controllers\Api\V1\Crm\PlController::class, 'deleteLine'])->middleware('crm.member');
            // Churn: reads with the reports right.
            // Celebrations: festival vibes + the wishes wall.
            Route::get('/chat-directory', [\App\Http\Controllers\Api\V1\Crm\CrmController::class, 'chatDirectory'])->middleware('crm.member');
            Route::get('/celebration-today', [\App\Http\Controllers\Api\V1\Crm\CelebrationController::class, 'today'])->middleware('crm.member');
            Route::get('/wishes', [\App\Http\Controllers\Api\V1\Crm\CelebrationController::class, 'wishes'])->middleware('crm.member');
            Route::post('/wishes', [\App\Http\Controllers\Api\V1\Crm\CelebrationController::class, 'sendWish'])->middleware('crm.member');
            Route::get('/masters/festival-settings', [\App\Http\Controllers\Api\V1\Crm\CelebrationController::class, 'settings'])->middleware('crm.member:masters,edit');
            Route::put('/masters/festival-settings', [\App\Http\Controllers\Api\V1\Crm\CelebrationController::class, 'saveSettings'])->middleware('crm.member:masters,edit');
            Route::post('/masters/celebration-song', [\App\Http\Controllers\Api\V1\Crm\CelebrationController::class, 'uploadSong'])->middleware('crm.member:masters,edit');
            Route::get('/churn', [\App\Http\Controllers\Api\V1\Crm\ChurnController::class, 'index'])->middleware('crm.member');
            Route::get('/incentives', [\App\Http\Controllers\Api\V1\Crm\IncentiveController::class, 'index'])->middleware('crm.member');
            Route::post('/incentives/hold', [\App\Http\Controllers\Api\V1\Crm\IncentiveController::class, 'hold'])->middleware('crm.member');
            Route::post('/incentives/hold-all', [\App\Http\Controllers\Api\V1\Crm\IncentiveController::class, 'holdAll'])->middleware('crm.member');
            Route::post('/incentives/holds/{uuid}/release', [\App\Http\Controllers\Api\V1\Crm\IncentiveController::class, 'release'])->middleware('crm.member');
            Route::get('/salary/{uuid}/pdf', [\App\Http\Controllers\Api\V1\Crm\SalaryController::class, 'pdf'])
                ->middleware('crm.member');
            Route::post('/salary/{uuid}/recalculate', [\App\Http\Controllers\Api\V1\Crm\SalaryController::class, 'recalculate'])
                ->middleware('crm.member:salary,edit');
            Route::post('/salary/mark-paid', [\App\Http\Controllers\Api\V1\Crm\SalaryController::class, 'markPaid'])
                ->middleware('crm.member:salary,edit');
            Route::put('/salary/{uuid}', [\App\Http\Controllers\Api\V1\Crm\SalaryController::class, 'update'])
                ->middleware('crm.member:salary,edit');
            Route::delete('/salary/{uuid}', [\App\Http\Controllers\Api\V1\Crm\SalaryController::class, 'destroy'])
                ->middleware('crm.member:salary,delete');

            // Leaves: request your own; deciding needs the module right
            Route::middleware('crm.member')->group(function () {
                Route::get('/leaves', [\App\Http\Controllers\Api\V1\Crm\LeaveController::class, 'index']);
                Route::post('/leaves', [\App\Http\Controllers\Api\V1\Crm\LeaveController::class, 'store']);
                Route::delete('/leaves/{uuid}', [\App\Http\Controllers\Api\V1\Crm\LeaveController::class, 'cancel']);
            });
            Route::post('/leaves/{uuid}/decide', [\App\Http\Controllers\Api\V1\Crm\LeaveController::class, 'decide'])
                ->middleware('crm.member:leaves,edit');

            // Tasks with the approval flow
            Route::middleware('crm.member')->group(function () {
                Route::get('/tasks', [\App\Http\Controllers\Api\V1\Crm\TaskController::class, 'index']);
                Route::post('/tasks/{uuid}/progress', [\App\Http\Controllers\Api\V1\Crm\TaskController::class, 'progress']);
            });
            Route::post('/tasks', [\App\Http\Controllers\Api\V1\Crm\TaskController::class, 'store'])
                ->middleware('crm.member:tasks,create');
            Route::middleware('crm.member:tasks,edit')->group(function () {
                Route::put('/tasks/{uuid}', [\App\Http\Controllers\Api\V1\Crm\TaskController::class, 'update']);
                Route::post('/tasks/{uuid}/review', [\App\Http\Controllers\Api\V1\Crm\TaskController::class, 'review']);
            });
            Route::delete('/tasks/{uuid}', [\App\Http\Controllers\Api\V1\Crm\TaskController::class, 'destroy'])
                ->middleware('crm.member:tasks,delete');

            // Approvals register + inbox, and invoice update requests
            Route::middleware('crm.member')->group(function () {
                Route::get('/approvals', [\App\Http\Controllers\Api\V1\Crm\ApprovalController::class, 'index']);
                // What this member may point a request at.
                Route::get('/approvals/options', [\App\Http\Controllers\Api\V1\Crm\ApprovalController::class, 'options']);
                Route::post('/approvals', [\App\Http\Controllers\Api\V1\Crm\ApprovalController::class, 'store']);
                Route::get('/invoice-updates', [\App\Http\Controllers\Api\V1\Crm\ApprovalController::class, 'invoiceUpdates']);
                Route::post('/invoices/{invoiceUuid}/update-request', [\App\Http\Controllers\Api\V1\Crm\ApprovalController::class, 'requestInvoiceUpdate']);
            });
            Route::post('/approvals/{uuid}/decide', [\App\Http\Controllers\Api\V1\Crm\ApprovalController::class, 'decide'])
                ->middleware('crm.member:approvals,edit');
            Route::post('/invoice-updates/{uuid}/decide', [\App\Http\Controllers\Api\V1\Crm\ApprovalController::class, 'decideInvoiceUpdate'])
                ->middleware('crm.member:invoices,edit');

            // Newsletters
            Route::get('/newsletters', [\App\Http\Controllers\Api\V1\Crm\NewsletterController::class, 'index'])
                ->middleware('crm.member:newsletters,view');
            Route::post('/newsletters', [\App\Http\Controllers\Api\V1\Crm\NewsletterController::class, 'store'])
                ->middleware('crm.member:newsletters,create');
            Route::middleware('crm.member:newsletters,edit')->group(function () {
                Route::put('/newsletters/{uuid}', [\App\Http\Controllers\Api\V1\Crm\NewsletterController::class, 'update']);
                Route::post('/newsletters/{uuid}/send', [\App\Http\Controllers\Api\V1\Crm\NewsletterController::class, 'send']);
            });
            Route::delete('/newsletters/{uuid}', [\App\Http\Controllers\Api\V1\Crm\NewsletterController::class, 'destroy'])
                ->middleware('crm.member:newsletters,delete');

            // CMS notice board: everyone reads, editors manage
            // Complaint Management System: client issues and the office's
            // own working-out of them, in one record.
            Route::middleware('crm.member:complaints,view')->group(function () {
                Route::get('/complaints', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'index']);
                Route::get('/complaints-due', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'due']);
                Route::get('/complaints/options', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'options']);
                Route::get('/complaints/{uuid}', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'show']);
                Route::get('/complaints/{uuid}/files/{documentUuid}', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'downloadFile']);
                // Anyone who can see a complaint can talk on it — that is
                // what makes the internal thread worth having.
                Route::post('/complaints/{uuid}/replies', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'reply']);
                Route::delete('/complaints/{uuid}/replies/{replyUuid}', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'deleteReply']);
            });
            Route::post('/complaints', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'store'])
                ->middleware('crm.member:complaints,create');
            Route::middleware('crm.member:complaints,edit')->group(function () {
                Route::put('/complaints/{uuid}', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'update']);
                Route::post('/complaints/{uuid}/allocate', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'allocate']);
                Route::post('/complaints/{uuid}/status', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'status']);
                Route::post('/complaints/{uuid}/files', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'uploadFile']);
                Route::delete('/complaints/{uuid}/files/{documentUuid}', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'deleteFile']);
            });
            Route::delete('/complaints/{uuid}', [\App\Http\Controllers\Api\V1\Crm\ComplaintController::class, 'destroy'])
                ->middleware('crm.member:complaints,delete');

            Route::get('/cms', [\App\Http\Controllers\Api\V1\Crm\CmsController::class, 'index'])
                ->middleware('crm.member');
            Route::post('/cms', [\App\Http\Controllers\Api\V1\Crm\CmsController::class, 'store'])
                ->middleware('crm.member:cms,create');
            Route::put('/cms/{uuid}', [\App\Http\Controllers\Api\V1\Crm\CmsController::class, 'update'])
                ->middleware('crm.member:cms,edit');
            Route::delete('/cms/{uuid}', [\App\Http\Controllers\Api\V1\Crm\CmsController::class, 'destroy'])
                ->middleware('crm.member:cms,delete');

            /*
             * Dedicated Company Workspace (DCW): extra form fields a company
             * requests for itself. Managing them is company authority; the
             * Super Admin approves before anything appears on a form.
             */
            Route::middleware(['crm.member', 'crm.manager'])->group(function () {
                Route::get('/workspace-fields', [\App\Http\Controllers\Api\V1\Crm\CustomFieldController::class, 'index']);
                Route::post('/workspace-fields', [\App\Http\Controllers\Api\V1\Crm\CustomFieldController::class, 'store']);
                Route::delete('/workspace-fields/{uuid}', [\App\Http\Controllers\Api\V1\Crm\CustomFieldController::class, 'destroy']);
            });

            // Reports: the controller holds the stricter door — the Admin,
            // plus a Subadmin named with reports.view. The User Log keeps
            // the reports module right.
            Route::get('/reports/overview', [\App\Http\Controllers\Api\V1\Crm\ReportController::class, 'overview'])
                ->middleware('crm.member');
            Route::get('/user-log', [\App\Http\Controllers\Api\V1\Crm\ReportController::class, 'userLog'])
                ->middleware('crm.member:reports,view');

            // Commission to a client: an expense tied to a sale, never a
            // line on the invoice.
            Route::get('/commissions', [\App\Http\Controllers\Api\V1\Crm\CommissionController::class, 'index'])
                ->middleware('crm.member:expenses,view');
            Route::post('/commissions', [\App\Http\Controllers\Api\V1\Crm\CommissionController::class, 'store'])
                ->middleware('crm.member:expenses,create');
            Route::delete('/commissions/{uuid}', [\App\Http\Controllers\Api\V1\Crm\CommissionController::class, 'destroy'])
                ->middleware('crm.member:expenses,delete');

            // Internal notes on a document: whoever can see it can speak.
            Route::middleware('crm.member:invoices,view')->group(function () {
                Route::get('/invoices/{invoiceUuid}/notes', [\App\Http\Controllers\Api\V1\Crm\InvoiceNoteController::class, 'index']);
                Route::post('/invoices/{invoiceUuid}/notes', [\App\Http\Controllers\Api\V1\Crm\InvoiceNoteController::class, 'store']);
                Route::delete('/invoices/{invoiceUuid}/notes/{noteUuid}', [\App\Http\Controllers\Api\V1\Crm\InvoiceNoteController::class, 'destroy']);
            });

            // Subscriptions: a document told to happen again.
            Route::get('/recurring', [\App\Http\Controllers\Api\V1\Crm\RecurringInvoiceController::class, 'index'])
                ->middleware('crm.member:invoices,view');
            Route::post('/invoices/{invoiceUuid}/recurring', [\App\Http\Controllers\Api\V1\Crm\RecurringInvoiceController::class, 'store'])
                ->middleware('crm.member:invoices,create');
            Route::post('/recurring/{uuid}/decide', [\App\Http\Controllers\Api\V1\Crm\RecurringInvoiceController::class, 'decide'])
                ->middleware('crm.member:invoices,edit');
            Route::post('/recurring/{uuid}/run', [\App\Http\Controllers\Api\V1\Crm\RecurringInvoiceController::class, 'run'])
                ->middleware('crm.member:invoices,create');

            // "Pay online" links against a proforma or an invoice.
            Route::get('/invoices/{invoiceUuid}/payment-links', [\App\Http\Controllers\Api\V1\Crm\PaymentLinkController::class, 'index'])
                ->middleware('crm.member:payments,view');
            Route::post('/invoices/{invoiceUuid}/payment-links', [\App\Http\Controllers\Api\V1\Crm\PaymentLinkController::class, 'store'])
                ->middleware('crm.member:payments,create');
            Route::get('/masters/payment-gateway', [\App\Http\Controllers\Api\V1\Crm\PaymentLinkController::class, 'settings'])
                ->middleware('crm.member:masters,edit');
            Route::put('/masters/payment-gateway', [\App\Http\Controllers\Api\V1\Crm\PaymentLinkController::class, 'saveSettings'])
                ->middleware('crm.member:masters,edit');

            // How this company settles payments and chases unpaid invoices.
            Route::get('/masters/payment-settings', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'paymentSettings'])
                ->middleware('crm.member:payments,view');
            Route::get('/masters/lead-settings', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'leadSettings'])
                ->middleware('crm.member:leads,view');
            Route::get('/masters/lead-options', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'leadOptions'])
                ->middleware('crm.member:leads,view');
            Route::get('/masters/complaint-options', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'complaintOptions'])
                ->middleware('crm.member');
            Route::get('/masters/approval-types', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'approvalTypes'])
                ->middleware('crm.member:approvals,view');

            // Billing masters
            Route::middleware('crm.member:masters,edit')->group(function () {
                Route::put('/masters/payment-settings', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'savePaymentSettings']);
                Route::put('/masters/lead-settings', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'saveLeadSettings']);
                Route::put('/masters/lead-options', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'saveLeadOptions']);
                Route::put('/masters/approval-types', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'saveApprovalTypes']);
                Route::put('/masters/complaint-options', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'saveComplaintOptions']);
                Route::post('/masters/issuing-companies', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'storeCompany']);
                Route::put('/masters/issuing-companies/{id}', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'updateCompany']);
                Route::post('/masters/issuing-companies/{id}/logo', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'uploadCompanyLogo']);
                Route::get('/masters/fx-rate', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'fxRate']);
                Route::put('/masters/fx-settings', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'saveFxSettings']);
                Route::get('/masters/birthday-settings', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'birthdaySettings']);
                Route::put('/masters/birthday-settings', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'saveBirthdaySettings']);
                // The Office Assets category list, edited in Billing setup.
                Route::get('/masters/asset-categories', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'assetCategories']);
                Route::put('/masters/asset-categories', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'saveAssetCategories']);
                Route::get('/masters/communication', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'communicationSettings']);
                Route::put('/masters/communication', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'saveCommunicationSettings']);
                // Trying a mailbox before trusting it with anything.
                Route::post('/masters/communication/test', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'testMailbox']);
                Route::post('/masters/bank-accounts', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'storeBank']);
                Route::put('/masters/bank-accounts/{id}', [\App\Http\Controllers\Api\V1\Crm\MasterController::class, 'updateBank']);
            });
        });

        // The addon switch itself: super admin only.
        Route::prefix('admin/crm')->middleware('role:super_admin')->group(function () {
            Route::get('/organizations', [\App\Http\Controllers\Api\V1\Crm\OrganizationAdminController::class, 'index']);
            Route::post('/organizations', [\App\Http\Controllers\Api\V1\Crm\OrganizationAdminController::class, 'store']);
            Route::put('/organizations/{organization}', [\App\Http\Controllers\Api\V1\Crm\OrganizationAdminController::class, 'update']);
            Route::delete('/organizations/{organization}', [\App\Http\Controllers\Api\V1\Crm\OrganizationAdminController::class, 'destroy']);
            Route::get('/organizations/{organization}/members', [\App\Http\Controllers\Api\V1\Crm\OrganizationAdminController::class, 'members']);
            Route::post('/organizations/{organization}/enter', [\App\Http\Controllers\Api\V1\Crm\OrganizationAdminController::class, 'enter']);
            // DCW approvals across every company.
            Route::get('/field-requests', [\App\Http\Controllers\Api\V1\Crm\CustomFieldController::class, 'pending']);
            Route::post('/field-requests/{uuid}/decide', [\App\Http\Controllers\Api\V1\Crm\CustomFieldController::class, 'decide']);
        });
    });
});
