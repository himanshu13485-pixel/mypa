<?php

use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\StatsController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\AppIdController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BlockController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ConnectionController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // --- Public auth (strictly throttled) --------------------------------
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::post('/auth/login', [AuthController::class, 'login']);
        Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    });

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

        // Dashboard
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

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
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

        // Events & calendar
        Route::get('/calendar/feed', [EventController::class, 'feed']);
        Route::get('/calendar/export.ics', [EventController::class, 'exportIcs']);
        Route::apiResource('events', EventController::class);
        Route::post('/events/{event}/respond', [EventController::class, 'respond']);

        // --- Admin --------------------------------------------------------
        Route::prefix('admin')->middleware('role:admin,super_admin')->group(function () {
            Route::get('/stats', [StatsController::class, 'index']);
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::post('/users', [AdminUserController::class, 'store']);
            Route::get('/users/{user}', [AdminUserController::class, 'show']);
            Route::put('/users/{user}', [AdminUserController::class, 'update']);
            Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend']);
            Route::post('/users/{user}/activate', [AdminUserController::class, 'activate']);
            Route::post('/users/{user}/roles', [AdminUserController::class, 'syncRoles']);
            Route::post('/users/{user}/app-id/regenerate', [AdminUserController::class, 'regenerateAppId']);
            Route::get('/roles', [RoleController::class, 'roles']);
            Route::get('/permissions', [RoleController::class, 'permissions']);
            Route::get('/login-histories', [RoleController::class, 'loginHistories']);
        });
    });
});
