<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Salesperson workspace: the users assigned to me, with their plan and an
 * activity summary. Admins may pass ?salesperson=<uuid> to view any book.
 */
class SalespersonController extends Controller
{
    public function myUsers(Request $request): AnonymousResourceCollection
    {
        $me = $request->user();

        $salespersonId = $me->id;
        if ($me->isAdmin() && $request->query('salesperson')) {
            $salespersonId = User::where('uuid', $request->query('salesperson'))->firstOrFail()->id;
        }

        $users = User::with(['appId', 'roles', 'profile'])
            ->where('salesperson_id', $salespersonId)
            ->latest()
            ->paginate(20);

        $entitlements = app(\App\Services\SubscriptionEntitlementService::class);
        $users->getCollection()->each(
            fn ($user) => $user->setAttribute('plan_slug', $entitlements->planFor($user)->slug),
        );

        return UserResource::collection($users);
    }

    /** Activity summary for one of my assigned users (admins: any user). */
    public function summary(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        abort_unless($me->isAdmin() || $user->salesperson_id === $me->id, 403,
            'This user is not assigned to you.');

        return app(UserController::class)->summary($request, $user);
    }
}
