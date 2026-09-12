<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Support\NotificationTopics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which CRM menus send mail and alerts, for the whole company.
 *
 * The Admin's decision and nobody else's. Whether the payments screen writes
 * to people is a question about the company's own mail, and an employee
 * switching it off for themselves - or a Subadmin switching it off for
 * everyone - is not how a company decides what its staff are told.
 */
class NotificationPolicyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        return response()->json([
            'data' => [
                'topics' => NotificationTopics::crm(),
                'values' => $org->topicPolicy(),
                'can_edit' => $me->crm_role === 'admin',
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        abort_unless($me->crm_role === 'admin', 403, 'Only the company Admin decides which menus send alerts.');

        $data = $request->validate([
            'topics' => ['required', 'array'],
            'topics.*.email' => ['sometimes', 'boolean'],
            'topics.*.app' => ['sometimes', 'boolean'],
        ]);

        $settings = $org->settings ?? [];
        $saved = (array) ($settings['notification_topics'] ?? []);
        $changed = [];

        foreach ($data['topics'] as $key => $wanted) {
            if (! NotificationTopics::isCrm((string) $key)) {
                continue;
            }

            $saved[$key] = array_merge(
                ['email' => true, 'app' => true],
                (array) ($saved[$key] ?? []),
                array_intersect_key($wanted, array_flip(['email', 'app'])),
            );
            $changed[$key] = $saved[$key];
        }

        $settings['notification_topics'] = $saved;
        $org->update(['settings' => $settings]);

        // Who silenced the payments e-mails, and when, is exactly the question
        // somebody asks after a payment nobody heard about.
        ActivityLog::record($me, $org->id, 'settings.alerts', $org, ['changed' => $changed]);

        return response()->json([
            'message' => 'Saved for everyone in the company.',
            'data' => ['values' => $org->fresh()->topicPolicy()],
        ]);
    }
}
