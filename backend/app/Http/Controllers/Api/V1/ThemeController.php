<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Crm\ActivityLog;
use App\Support\Appearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A person's theme on their personal Netvork screens.
 *
 * The same choice the CRM Theme page saves: somebody in a company sees their
 * own look everywhere, falling back to the company's default and then to
 * Netvork's. Somebody on their own falls straight back to Netvork's, which
 * the Super Admin sets.
 */
class ThemeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('settings');

        return response()->json([
            'data' => Appearance::layers($user, Appearance::membershipOf($user)?->organization),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'background' => ['sometimes', 'nullable', Rule::in(Appearance::backgroundChoices())],
            'sidebar' => ['sometimes', 'nullable', Rule::in(Appearance::sidebarChoices())],
        ]);

        $user = $request->user();
        $changed = Appearance::saveMine($user, $data);
        $member = Appearance::membershipOf($user);

        if ($changed !== []) {
            AuditLog::record($user, 'theme.updated', null, ['fields' => $changed]);

            // A company member's theme is theirs across Netvork, so the
            // company's activity log hears about it wherever it was changed.
            if ($member) {
                ActivityLog::record($member, $member->organization_id, 'settings.appearance', $member->organization, [
                    'scope' => 'me',
                    'fields' => $changed,
                ]);
            }
        }

        return response()->json([
            'message' => 'Theme saved.',
            'data' => Appearance::layers($user, $member?->organization),
        ]);
    }
}
