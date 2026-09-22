<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMailAccount;
use App\Models\Crm\MailAccount;
use App\Models\Crm\Member;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailConnector;
use App\Services\Mail\MailSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The mailboxes a person has added to Mails.
 *
 * Each belongs to the member who added it, and nobody else - not an Admin -
 * can open it through here. How many a person may add is the company's
 * number for them, within the platform's cap for the company.
 */
class MailAccountController extends Controller
{
    public function __construct(private MailConnector $connector)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $me = $this->member($request);

        return response()->json([
            'data' => MailAccount::where('member_id', $me->id)->orderByDesc('is_default')->orderBy('id')->get()
                ->map(fn (MailAccount $a) => $a->serialize())->values(),
            'limit' => MailAccess::limitFor($me),
            'providers' => collect(MailAccount::PROVIDERS)->map(fn ($p, $key) => ['key' => $key] + $p)->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $limit = MailAccess::limitFor($me);

        abort_if(
            MailAccount::where('member_id', $me->id)->count() >= $limit,
            422,
            "You can add up to {$limit} mailbox" . ($limit === 1 ? '' : 'es') . '. Ask your Admin if you need more.',
        );

        $data = $this->validated($request, true);
        $first = ! MailAccount::where('member_id', $me->id)->exists();

        $account = MailAccount::create($data + [
            'organization_id' => $me->organization_id,
            'member_id' => $me->id,
            'is_default' => $first,
        ]);

        // Straight into the queue, so the inbox is filling by the time the dialog closes.
        if ($account->canReceive()) {
            SyncMailAccount::dispatch($account->id);
        }

        return response()->json(['message' => 'Mailbox added.', 'data' => $account->serialize()], 201);
    }

    public function update(Request $request, MailAccount $account): JsonResponse
    {
        $this->own($request, $account);
        $data = $this->validated($request, false);

        // A password left blank on an edit keeps the one on file.
        foreach (['imap_password', 'smtp_password'] as $secret) {
            if (($data[$secret] ?? null) === null || $data[$secret] === '') {
                unset($data[$secret]);
            }
        }

        $moved = isset($data['imap_host']) && $data['imap_host'] !== $account->imap_host;
        $account->fill($data);
        // A different server is a different mailbox as far as its numbering goes.
        if ($moved) {
            $account->sync_state = null;
        }
        $account->save();

        if (! empty($data['is_default'])) {
            MailAccount::where('member_id', $account->member_id)->where('id', '!=', $account->id)->update(['is_default' => false]);
        }

        return response()->json(['message' => 'Mailbox saved.', 'data' => $account->fresh()->serialize()]);
    }

    public function destroy(Request $request, MailAccount $account): JsonResponse
    {
        $this->own($request, $account);
        $wasDefault = $account->is_default;
        $memberId = $account->member_id;
        $account->delete();

        if ($wasDefault) {
            MailAccount::where('member_id', $memberId)->orderBy('id')->first()?->update(['is_default' => true]);
        }

        // Only the copy here goes: the mail itself stays on the mail server.
        return response()->json(['message' => 'Mailbox removed from Mails. The mail on your server is untouched.']);
    }

    /** Sign in to both sides, and say which half works. */
    public function test(Request $request, MailAccount $account): JsonResponse
    {
        $this->own($request, $account);

        return response()->json(['data' => [
            'imap' => $this->connector->testImap($account),
            'smtp' => $this->connector->testSmtp($account),
        ]]);
    }

    /** Bring it up to date now, rather than at the next five minutes. */
    public function sync(Request $request, MailAccount $account, MailSync $sync): JsonResponse
    {
        $this->own($request, $account);

        if ($request->boolean('now')) {
            $result = $sync->sync($account->fresh());

            return response()->json([
                'message' => $result['error'] ? 'Sync failed: ' . $result['error'] : "{$result['fetched']} new message(s).",
                'data' => $result,
            ], $result['error'] ? 422 : 200);
        }

        SyncMailAccount::dispatch($account->id);

        return response()->json(['message' => 'Checking for new mail.']);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'email' => [$creating ? 'required' : 'sometimes', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'provider' => ['nullable', Rule::in(array_keys(MailAccount::PROVIDERS))],
            'imap_host' => ['nullable', 'string', 'max:255'],
            'imap_port' => ['nullable', 'integer', 'between:1,65535'],
            'imap_encryption' => ['nullable', Rule::in(['ssl', 'tls', 'none'])],
            'imap_username' => ['nullable', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:512'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['nullable', Rule::in(['ssl', 'tls', 'none'])],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:512'],
            'signature_html' => ['nullable', 'string', 'max:20000'],
            'auto_reply' => ['nullable', 'array'],
            'auto_reply.enabled' => ['boolean'],
            'auto_reply.subject' => ['nullable', 'string', 'max:255'],
            'auto_reply.body' => ['nullable', 'string', 'max:5000'],
            'auto_reply.from' => ['nullable', 'date_format:Y-m-d'],
            'auto_reply.until' => ['nullable', 'date_format:Y-m-d'],
            'forward_to' => ['nullable', 'email', 'max:255'],
            'is_default' => ['boolean'],
        ]);
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('crm_member');
    }

    private function own(Request $request, MailAccount $account): void
    {
        abort_unless($account->member_id === $this->member($request)->id, 404);
    }
}
