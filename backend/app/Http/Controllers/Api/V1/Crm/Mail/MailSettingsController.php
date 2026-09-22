<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\MailAccount;
use App\Models\Crm\Member;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

/**
 * Mails settings: a person's own preferences, and - for the company's
 * Admin - who gets Mails, how many mailboxes each may hold, and which AI
 * writes drafts.
 */
class MailSettingsController extends Controller
{
    public function show(Request $request, MailAssistant $assistant): JsonResponse
    {
        $me = $this->member($request);

        return response()->json(['data' => [
            'prefs' => MailAccess::prefs($me),
            'limit' => MailAccess::limitFor($me),
            'cap' => MailAccess::cap($me->organization),
            'is_admin' => $me->crm_role === 'admin',
            'ai_available' => $assistant->available($me->organization),
        ]]);
    }

    public function savePrefs(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $data = $request->validate([
            'undo_seconds' => ['sometimes', 'integer', Rule::in([0, 5, 10, 20, 30])],
            'conversation' => ['sometimes', 'boolean'],
            'reading_pane' => ['sometimes', Rule::in(['right', 'bottom', 'off'])],
            'density' => ['sometimes', Rule::in(['comfortable', 'compact'])],
            'accent' => ['sometimes', Rule::in(['brand', 'emerald', 'violet', 'rose', 'amber', 'slate'])],
            'load_images' => ['sometimes', Rule::in(['ask', 'always'])],
            'default_account' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $me->update(['mail_prefs' => array_merge(MailAccess::prefs($me), $data)]);

        return response()->json(['message' => 'Saved.', 'data' => MailAccess::prefs($me->fresh())]);
    }

    // ---- The Company Admin's half --------------------------------------------

    /** Everybody in the company, whether they have Mails, and their allowance. */
    public function team(Request $request): JsonResponse
    {
        $me = $this->admin($request);
        $cap = MailAccess::cap($me->organization);

        $members = Member::visible()->with('user:id,name,email')
            ->where('organization_id', $me->organization_id)->where('status', 'active')
            ->orderBy('crm_role')->get()
            ->map(fn (Member $m) => [
                'uuid' => $m->uuid,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'role' => $m->crm_role,
                'has_mails' => $m->crm_role === 'admin' || $m->can('mails'),
                'locked' => $m->crm_role === 'admin',
                'limit' => MailAccess::limitFor($m),
                'mailboxes' => MailAccount::where('member_id', $m->id)->count(),
            ])->values();

        return response()->json(['data' => $members, 'cap' => $cap]);
    }

    /** Give or take Mails from one person, and set how many mailboxes they may add. */
    public function saveTeam(Request $request, string $uuid): JsonResponse
    {
        $me = $this->admin($request);
        $cap = MailAccess::cap($me->organization);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', "max:{$cap}"],
        ], ['limit.max' => "Your company may allow at most {$cap} mailboxes per person."]);

        $member = Member::where('organization_id', $me->organization_id)->where('uuid', $uuid)->firstOrFail();
        abort_if($member->crm_role === 'admin', 422, 'The Company Admin always has Mails.');

        $rights = (array) ($member->rights ?? []);
        if ($data['enabled']) {
            $rights['mails'] = ['view', 'create', 'edit', 'delete'];
        } else {
            unset($rights['mails']);
        }
        $member->update([
            'rights' => $rights,
            'mail_mailbox_limit' => $data['limit'] ?? $member->mail_mailbox_limit,
        ]);

        ActivityLog::record($me, $me->organization_id, 'mails.access', $member, [
            'mails' => $data['enabled'] ? 'granted' : 'removed',
            'mailboxes' => $data['limit'] ?? MailAccess::limitFor($member),
        ]);

        return response()->json(['message' => $data['enabled'] ? 'Mails given.' : 'Mails taken away.']);
    }

    /** Which AI writes drafts for the company. The key is write-only. */
    public function ai(Request $request, MailAssistant $assistant): JsonResponse
    {
        $me = $this->admin($request);
        $own = (array) data_get($me->organization->settings, 'mails_ai', []);

        return response()->json(['data' => [
            'enabled' => (bool) ($own['enabled'] ?? false),
            'provider' => $own['provider'] ?? 'anthropic',
            'model' => $own['model'] ?? '',
            'has_key' => ! empty($own['api_key']),
            'default_claude_model' => MailAssistant::DEFAULT_CLAUDE_MODEL,
            'available' => $assistant->available($me->organization),
        ]]);
    }

    public function saveAi(Request $request): JsonResponse
    {
        $me = $this->admin($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'provider' => ['required', Rule::in(['anthropic', 'openai'])],
            // ChatGPT has no default here: its model names are the company's to choose.
            'model' => [$request->input('provider') === 'openai' && $request->boolean('enabled') ? 'required' : 'nullable', 'string', 'max:100'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ], ['model.required' => 'Enter the OpenAI model to use, exactly as OpenAI names it.']);

        $org = $me->organization;
        $settings = (array) $org->settings;
        $current = (array) ($settings['mails_ai'] ?? []);
        $settings['mails_ai'] = [
            'enabled' => $data['enabled'],
            'provider' => $data['provider'],
            'model' => trim((string) ($data['model'] ?? '')),
            'api_key' => filled($data['api_key'] ?? null) ? Crypt::encryptString(trim($data['api_key'])) : ($current['api_key'] ?? null),
        ];
        $org->update(['settings' => $settings]);

        ActivityLog::record($me, $org->id, 'mails.ai', $org, ['provider' => $data['provider'], 'enabled' => $data['enabled'] ? 'on' : 'off']);

        return response()->json(['message' => 'AI assistant saved.']);
    }

    private function admin(Request $request): Member
    {
        $me = $this->member($request);
        abort_unless($me->crm_role === 'admin', 403, 'Only the Company Admin can change this.');

        return $me;
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('crm_member');
    }
}
