<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\SubscriptionEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    /** Public pricing data (limits/features come from the backend, never hardcoded in the UI). */
    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => Plan::where('is_active', true)->where('is_public', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($plan) => $this->serializePlan($plan)),
        ]);
    }

    public function mySubscription(Request $request, SubscriptionEntitlementService $entitlements): JsonResponse
    {
        $user = $request->user();
        $plan = $entitlements->planFor($user);
        $subscription = $entitlements->subscriptionFor($user);

        return response()->json([
            'data' => [
                'plan' => $this->serializePlan($plan),
                'status' => $subscription?->status ?? 'free',
                'started_at' => $subscription?->started_at,
                'trial_ends_at' => $subscription?->trial_ends_at,
                'ends_at' => $subscription?->ends_at,
                'usage' => $entitlements->usage($user),
            ],
        ]);
    }

    protected function serializePlan(Plan $plan): array
    {
        return [
            'slug' => $plan->slug,
            'name' => $plan->name,
            'description' => $plan->description,
            'monthly_price' => $plan->monthly_price,
            'annual_price' => $plan->annual_price,
            'currency' => $plan->currency,
            'trial_days' => $plan->trial_days,
            'limits' => $plan->limits,
            'features' => $plan->features,
            'is_recommended' => $plan->is_recommended,
        ];
    }
}
