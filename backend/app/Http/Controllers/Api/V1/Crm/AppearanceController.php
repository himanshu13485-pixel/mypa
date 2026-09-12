<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Support\Appearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What the company's CRM looks like: the background behind every page, and
 * the sidebar's colour.
 *
 * One look for the whole company, set by its Admin - it is the company's
 * workspace, the way its logo is - and every change is written to the
 * activity log with what it was and what it became, because "who turned the
 * CRM purple" is exactly the kind of question somebody asks on a Monday.
 */
class AppearanceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        return response()->json(['data' => [
            'background' => data_get($org->settings, 'appearance.background'),
            'sidebar' => data_get($org->settings, 'appearance.sidebar'),
            'can_edit' => $me->crm_role === 'admin',
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        abort_unless($me->crm_role === 'admin', 403, "Only the company Admin changes the CRM's look.");

        $data = $request->validate([
            'background' => ['sometimes', 'nullable', Rule::in(Appearance::backgroundKeys())],
            'sidebar' => ['sometimes', 'nullable', Rule::in(Appearance::sidebarKeys())],
        ]);

        $settings = $org->settings ?? [];
        $before = (array) ($settings['appearance'] ?? []);
        $after = array_merge($before, array_intersect_key($data, array_flip(['background', 'sidebar'])));

        $settings['appearance'] = $after;
        $org->update(['settings' => $settings]);

        // Only what changed, old to new, in words a person reads.
        $changed = [];
        foreach (['background' => Appearance::BACKGROUNDS, 'sidebar' => Appearance::SIDEBARS] as $key => $labels) {
            if (array_key_exists($key, $data) && ($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed[$key] = [
                    'from' => $labels[$before[$key] ?? ''] ?? 'Default',
                    'to' => $labels[$after[$key] ?? ''] ?? 'Default',
                ];
            }
        }

        if ($changed !== []) {
            ActivityLog::record($me, $org->id, 'settings.appearance', $org, ['fields' => $changed]);
        }

        return response()->json([
            'message' => 'The CRM looks different for everyone now.',
            'data' => [
                'background' => $after['background'] ?? null,
                'sidebar' => $after['sidebar'] ?? null,
                'can_edit' => true,
            ],
        ]);
    }
}
