<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Plan::withCount('subscriptions')->orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only a super admin can edit plans.');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'monthly_price' => ['sometimes', 'numeric', 'min:0'],
            'annual_price' => ['sometimes', 'numeric', 'min:0'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'limits' => ['sometimes', 'array'],
            'features' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'is_recommended' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $plan->update($data);

        return response()->json(['message' => 'Plan updated.', 'data' => $plan->fresh()]);
    }

    /** Manually put a user on a plan (audited via subscription note). */
    public function assign(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'plan_slug' => ['required', 'exists:plans,slug'],
            'months' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $plan = Plan::where('slug', $data['plan_slug'])->firstOrFail();

        // Retire any current subscription.
        Subscription::where('user_id', $user->id)
            ->whereIn('status', ['active', 'trial'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'started_at' => now(),
            'ends_at' => isset($data['months']) && $data['months']
                ? now()->addMonths((int) $data['months'])
                : null,
            'note' => sprintf(
                'Manually assigned by %s (%s)%s',
                $request->user()->name,
                $request->user()->email,
                ! empty($data['note']) ? ': ' . $data['note'] : '',
            ),
        ]);

        return response()->json([
            'message' => "{$user->name} is now on the {$plan->name} plan.",
            'data' => $subscription->load('plan'),
        ], 201);
    }
}
