<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Http\Controllers\Controller;
use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use App\Models\Crm\Member;
use App\Services\Mail\MailAssistant;
use App\Services\Mail\MailConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Drafting help. It writes into the compose box and nowhere else: the
 * person reads it, changes what they like, and sends it themselves.
 */
class MailAiController extends Controller
{
    public function write(Request $request, MailAssistant $assistant): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $data = $request->validate([
            'mode' => ['required', Rule::in(['compose', 'reply', 'improve'])],
            'instruction' => ['nullable', 'string', 'max:2000'],
            'tone' => ['nullable', Rule::in(['professional', 'friendly', 'formal', 'brief', 'apologetic', 'persuasive'])],
            'to' => ['nullable', 'string', 'max:500'],
            'text' => ['nullable', 'string', 'max:20000', 'required_if:mode,improve'],
            'action' => ['nullable', Rule::in(array_keys(MailAssistant::ACTIONS)), 'required_if:mode,improve'],
            'message_uuid' => ['nullable', 'uuid', 'required_if:mode,reply'],
        ]);

        if ($data['mode'] === 'compose' && blank($data['instruction'] ?? null)) {
            return response()->json(['message' => 'Say in a line what the mail should be about.'], 422);
        }

        $input = $data;
        if ($data['mode'] === 'reply') {
            $original = MailMessage::where('uuid', $data['message_uuid'])
                ->whereIn('mail_account_id', MailAccount::where('member_id', $me->id)->pluck('id'))
                ->firstOrFail();
            $input['from'] = trim(($original->from_name ?? '') . ' <' . $original->from_email . '>');
            $input['subject'] = (string) $original->subject;
            // The words of the mail, not its markup - and not a whole newsletter.
            $input['original'] = mb_substr($original->body_text ?: strip_tags((string) $original->body_html), 0, 8000);
        }

        try {
            $result = $assistant->write($me->organization, $data['mode'], $input);
        } catch (Throwable $e) {
            return response()->json(['message' => 'The AI assistant could not write this: ' . MailConnector::plain($e)], 422);
        }

        return response()->json(['data' => $result]);
    }
}
