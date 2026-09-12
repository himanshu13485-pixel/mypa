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
 * CRM Theme: a person's own look and birthday words, and the company's
 * defaults for them.
 *
 * Anybody may dress their own screens - it is where they spend the day, and
 * picking a background needs no billing rights. The company Admin sets what
 * everybody starts with. Both kinds of change go to the activity log with
 * what it was and what it became.
 */
class AppearanceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $request->validate([
            'scope' => ['required', Rule::in(['me', 'company'])],
            'background' => ['sometimes', 'nullable', Rule::in(Appearance::backgroundChoices())],
            'sidebar' => ['sometimes', 'nullable', Rule::in(Appearance::sidebarChoices())],
            'default_wish' => ['sometimes', 'nullable', 'string', 'max:500'],
            'default_reply' => ['sometimes', 'nullable', 'string', 'max:500'],
            'default_belated' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $scope = $data['scope'];
        unset($data['scope']);

        if ($scope === 'company') {
            abort_unless(
                $me->crm_role === 'admin',
                403,
                "Only the company Admin sets the company's default theme. Your own theme is yours to change.",
            );
            $changed = Appearance::saveCompany($org, $data);
        } else {
            $changed = Appearance::saveMine($request->user(), $data);
        }

        if ($changed !== []) {
            ActivityLog::record($me, $org->id, 'settings.appearance', $org, [
                'scope' => $scope,
                'fields' => $changed,
            ]);
        }

        return response()->json([
            'message' => $scope === 'company'
                ? 'Company default saved. Everybody who has not picked their own sees it now.'
                : 'Saved. Your theme follows you across Netvork.',
            'data' => $this->payload($request),
        ]);
    }

    private function payload(Request $request): array
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $user = $request->user()->loadMissing('settings');
        $org->refresh();

        return Appearance::layers($user, $org) + [
            'birthday' => Appearance::birthday($user, $org),
            'can_edit_company' => $me->crm_role === 'admin',
        ];
    }
}
