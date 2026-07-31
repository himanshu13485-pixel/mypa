<?php

use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\StatsController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AppIdController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BlockController;
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

    // Public pricing
    Route::get('/plans', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'plans'])
        ->middleware('throttle:30,1');

    // --- Public auth (strictly throttled) --------------------------------
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
        Route::post('/auth/otp/request', [AuthController::class, 'requestLoginOtp']);
        Route::post('/auth/otp/login', [AuthController::class, 'loginWithOtp']);
    });

    Route::get('/auth/suggest-username', [AuthController::class, 'suggestUsername'])
        ->middleware('throttle:30,1');

    Route::get('/auth/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    // --- Authenticated ----------------------------------------------------
    Route::middleware(['auth:sanctum', 'active', 'throttle:60,1'])->group(function () {

        // Session & account
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
        Route::post('/auth/email/verification-notification', [AuthController::class, 'resendVerification'])
            ->middleware('throttle:6,1');
        Route::post('/auth/mobile/verify', [AuthController::class, 'verifyMobile'])
            ->middleware('throttle:10,1');
        Route::post('/auth/mobile/resend-otp', [AuthController::class, 'resendMobileOtp'])
            ->middleware('throttle:5,1');
        Route::post('/auth/email/resend-otp', [AuthController::class, 'resendEmailOtp'])
            ->middleware('throttle:5,1');
        Route::post('/auth/email/verify-otp', [AuthController::class, 'verifyEmailOtp'])
            ->middleware('throttle:10,1');
        Route::get('/auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('/auth/sessions/{tokenId}', [AuthController::class, 'revokeSession']);
        Route::get('/auth/login-history', [AuthController::class, 'loginHistory']);

        // Me
        Route::get('/me', [ProfileController::class, 'me']);
        Route::put('/me/profile', [ProfileController::class, 'updateProfile']);
        Route::put('/me/settings', [ProfileController::class, 'updateSettings']);
        Route::post('/me/photo', [ProfileController::class, 'uploadPhoto']);
        Route::get('/me/app-id/qr', [AppIdController::class, 'myQr']);

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
        Route::get('/push/public-key', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'publicKey']);
        Route::post('/push/subscribe', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'subscribe']);
        Route::post('/push/unsubscribe', [\App\Http\Controllers\Api\V1\PushSubscriptionController::class, 'unsubscribe']);

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
        Route::post('/meetings/{meeting}/leave', [\App\Http\Controllers\Api\V1\MeetingController::class, 'leave']);
        Route::post('/meetings/{meeting}/end', [\App\Http\Controllers\Api\V1\MeetingController::class, 'end']);
        Route::post('/meetings/{meeting}/signal', [\App\Http\Controllers\Api\V1\MeetingController::class, 'signal']);

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

        // Files & folders
        Route::get('/files/browse', [FileController::class, 'browse']);
        Route::get('/files/shared-with-me', [FileController::class, 'sharedWithMe']);
        Route::get('/files/usage', [FileController::class, 'usage']);
        Route::get('/files/trash', [FileController::class, 'trash']);
        Route::post('/files/upload', [FileController::class, 'upload']);
        Route::get('/files/{file}/download', [FileController::class, 'download']);
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
        Route::get('/calls/config', [CallController::class, 'config']);
        Route::get('/calls/history', [CallController::class, 'history']);
        Route::post('/conversations/{conversation}/calls', [CallController::class, 'initiate']);
        Route::post('/calls/{call}/respond', [CallController::class, 'respond']);
        Route::post('/calls/{call}/end', [CallController::class, 'end']);
        Route::post('/calls/{call}/signal', [CallController::class, 'signal']);
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
    });
});
