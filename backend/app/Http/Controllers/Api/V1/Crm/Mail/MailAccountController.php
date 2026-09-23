<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMailAccount;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\MailAccount;
use App\Models\Crm\MailMessage;
use App\Models\Crm\Member;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailConnector;
use App\Services\Mail\MailDns;
use App\Services\Mail\MailSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The mailboxes somebody can open in Mails.
 *
 * Two kinds, and the difference is who may do what to them:
 *
 *   a person's own mailbox, which they added themselves within the
 *   allowance their Admin set - theirs to edit, never theirs to delete or
 *   to hand to anybody else;
 *
 *   a company mailbox, which the Company Admin created and allocated to one
 *   person or several - sales@, support@, accounts@ - shared by everybody
 *   named on it, and the Admin's to share, cap, disconnect or remove.
 *
 * Disconnecting is not deleting. The mail that has already arrived stays
 * readable afterwards, and a new account can be connected in its place.
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
            'data' => MailAccount::for($me)->with(['sharedMembers.user:id,name,email', 'member.user:id,name,email'])
                ->orderByDesc('is_default')->orderBy('id')->get()
                ->map(fn (MailAccount $a) => $a->serialize() + [
                    'is_mine' => $a->member_id === $me->id,
                    'can_manage' => $this->mayManage($me, $a),
                    'owner' => $a->member_id === $me->id ? null : ($a->member?->user?->name ?: $a->member?->user?->email),
                ])->values(),
            'limit' => MailAccess::limitFor($me),
            'used' => MailAccount::for($me)->count(),
            'shared_count' => MailAccount::for($me)->where('member_id', '!=', $me->id)->count(),
            'is_admin' => $me->crm_role === 'admin',
            'providers' => collect(MailAccount::PROVIDERS)->map(fn ($p, $key) => ['key' => $key] + $p)->values(),
            'people' => $me->crm_role === 'admin' ? $this->people($me) : [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $admin = $me->crm_role === 'admin';
        $data = $this->validated($request, true);

        // An Admin may set the mailbox up for somebody else; anybody else is
        // only ever adding one of their own.
        $owner = $admin && $request->filled('member') ? $this->personIn($me, (string) $request->input('member')) : $me;

        $limit = MailAccess::limitFor($owner);
        abort_if(
            MailAccount::for($owner)->count() >= $limit,
            422,
            $owner->id === $me->id
                ? "You can add up to {$limit} mailbox" . ($limit === 1 ? '' : 'es') . '. Ask your Admin if you need more.'
                : ($owner->user?->name ?: 'That person') . " already holds {$limit} mailbox" . ($limit === 1 ? '' : 'es') . ' - raise their allowance under Team access first.',
        );

        $first = ! MailAccount::ownedBy($owner)->exists();

        $account = MailAccount::create($this->adminOnly($data, $admin) + [
            'organization_id' => $me->organization_id,
            'member_id' => $owner->id,
            'created_by_member_id' => $owner->id === $me->id ? null : $me->id,
            'is_default' => $first,
        ]);

        if ($admin) {
            $this->share($me, $account, (array) $request->input('shared_with', []));
        }

        // Straight into the queue, so the inbox is filling by the time the dialog closes.
        if ($account->canReceive()) {
            SyncMailAccount::dispatch($account->id);
        }

        ActivityLog::record($me, $me->organization_id, 'mail_account.added', $account, ['email' => $account->email, 'for' => $owner->user?->email]);

        return response()->json(['message' => 'Mailbox added.', 'data' => $account->fresh(['sharedMembers.user'])->serialize()], 201);
    }

    public function update(Request $request, MailAccount $account): JsonResponse
    {
        $me = $this->reachable($request, $account);
        abort_unless($this->mayManage($me, $account), 403, 'This mailbox is shared with you. Ask your Admin to change its settings.');

        $admin = $me->crm_role === 'admin';
        $data = $this->adminOnly($this->validated($request, false), $admin);

        // A password left blank on an edit keeps the one on file.
        foreach (['imap_password', 'smtp_password'] as $secret) {
            if (($data[$secret] ?? null) === null || $data[$secret] === '') {
                unset($data[$secret]);
            }
        }

        $moved = isset($data['imap_host']) && $data['imap_host'] !== $account->imap_host;

        // Changing how it connects retires the old complaint: what failed was
        // the settings that are being replaced.
        $wires = ['imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password',
            'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'verify_cert'];
        $rewired = collect($wires)->contains(fn ($f) => array_key_exists($f, $data) && $data[$f] != $account->{$f});

        $account->fill($data);
        if ($rewired) {
            $account->last_error = null;
        }
        // A different server is a different mailbox as far as its numbering goes.
        if ($moved) {
            $account->sync_state = null;
        }

        // Credentials again on a disconnected mailbox: it is back, with every
        // mail it had before still in place.
        $reconnected = false;
        if ($account->detached_at && (filled($account->imap_password) || filled($account->smtp_password))) {
            $account->detached_at = null;
            $account->last_error = null;
            $account->status = 'active';
            $reconnected = true;
        }
        $account->save();

        if (! empty($data['is_default'])) {
            MailAccount::where('member_id', $account->member_id)->where('id', '!=', $account->id)->update(['is_default' => false]);
        }
        if ($admin && $request->has('shared_with')) {
            $this->share($me, $account, (array) $request->input('shared_with', []));
        }
        if ($reconnected && $account->canReceive()) {
            SyncMailAccount::dispatch($account->id);
        }

        return response()->json([
            'message' => $reconnected ? 'Mailbox reconnected. Its mail is where you left it.' : 'Mailbox saved.',
            'data' => $account->fresh(['sharedMembers.user'])->serialize(),
        ]);
    }

    /**
     * Disconnect, or - said twice, and by an Admin - remove for good.
     *
     * Disconnecting is the ordinary case and the safe one: the account goes,
     * the mail stays. Nobody loses a year of correspondence because a
     * password changed or somebody left.
     */
    public function destroy(Request $request, MailAccount $account): JsonResponse
    {
        $me = $this->reachable($request, $account);
        abort_unless($me->crm_role === 'admin', 403, 'Only your Company Admin can remove a mailbox. You can edit yours at any time.');

        $held = MailMessage::where('mail_account_id', $account->id)->count();

        if ($request->boolean('purge')) {
            abort_unless($request->input('confirm') === $account->email, 422, 'Type the mailbox address to confirm removing it and its mail.');
            $account->delete();
            ActivityLog::record($me, $me->organization_id, 'mail_account.purged', $account, ['email' => $account->email, 'messages' => $held]);

            return response()->json(['message' => "Mailbox and its {$held} stored message(s) removed."]);
        }

        $account->forceFill([
            'detached_at' => now(),
            'imap_password' => null,
            'smtp_password' => null,
            'status' => 'detached',
            'last_error' => null,
        ])->save();

        ActivityLog::record($me, $me->organization_id, 'mail_account.disconnected', $account, ['email' => $account->email, 'messages' => $held]);

        return response()->json(['message' => "Mailbox disconnected. Its {$held} stored message(s) stay readable, and a new account can take its place."]);
    }

    /** Sign in to both sides, and say which half works. */
    public function test(Request $request, MailAccount $account): JsonResponse
    {
        $this->reachable($request, $account);

        $imap = $this->connector->testImap($account);
        $smtp = $this->connector->testSmtp($account);

        // Signing in settles the old complaint. Without this the card kept
        // showing the error from the last failed sync - and the reason people
        // press Test connection is to find out whether they have fixed it.
        // Settled when every half this mailbox actually has signed in - a
        // send-only mailbox is not in trouble for having no inbox.
        $working = ($account->canReceive() ? $imap['ok'] : true) && ($account->canSend() ? $smtp['ok'] : true);
        $this->settled($account, $working && ($account->canReceive() || $account->canSend()));

        return response()->json(['data' => ['imap' => $imap, 'smtp' => $smtp]]);
    }

    /** A closer look at the incoming half: the folders, and what is in the inbox. */
    public function testInbox(Request $request, MailAccount $account): JsonResponse
    {
        $this->reachable($request, $account);
        abort_unless($account->canReceive(), 422, 'This mailbox has no incoming (IMAP) server set.');

        try {
            $client = $this->connector->imap($account);
            $folders = collect($client->getFolders(false))->map(fn ($f) => $f->path)->values()->all();
            $inbox = $client->getFolderByPath(MailSync::mapFolders($folders)['inbox'] ?? 'INBOX');
            $newest = $inbox?->query()->leaveUnread()->setFetchBody(false)->softFail()
                ->all()->setFetchOrderDesc()->limit(1)->get()->first();
            $client->disconnect();
            $this->settled($account, true);

            return response()->json(['data' => [
                'ok' => true,
                'folders' => $folders,
                'message' => $newest
                    ? 'Signed in. ' . count($folders) . ' folder(s); newest message: "' . mb_substr((string) $newest->subject, 0, 80) . '".'
                    : 'Signed in. ' . count($folders) . ' folder(s), and nothing in the inbox.',
            ]]);
        } catch (Throwable $e) {
            // A diagnosis that comes back bad is still a request that worked:
            // 200 with ok=false, so the screen shows the reason in place
            // rather than an HTTP status nobody can act on.
            return response()->json(['data' => [
                'ok' => false,
                'folders' => [],
                'message' => MailConnector::explain($e, $account->imap_host, (int) $account->imap_port, 'incoming'),
            ]]);
        }
    }

    /** Put a real message through it, to an address of your choosing. */
    public function testEmail(Request $request, MailAccount $account): JsonResponse
    {
        $me = $this->reachable($request, $account);
        abort_unless($account->canSend(), 422, 'This mailbox has no outgoing (SMTP) server set.');

        $to = (string) ($request->validate(['to' => ['nullable', 'email']])['to'] ?? '') ?: ($me->user?->email ?? $account->email);

        if (! $account->claimSend()) {
            return response()->json(['data' => ['ok' => false, 'message' => "This mailbox has used its {$account->daily_cap} sends for today."]]);
        }

        try {
            $body = '<p>This is a test from Netvork Mails.</p>'
                . '<p>Mailbox: <b>' . e($account->email) . '</b><br>Sent by: ' . e($me->user?->name ?: $me->user?->email ?: 'a colleague')
                . '<br>Server: ' . e($account->smtp_host . ':' . $account->smtp_port . ' (' . $account->smtp_encryption . ')') . '</p>'
                . '<p>If this arrived, the outgoing half of this mailbox works.</p>';

            $this->connector->smtp($account)->send(
                ['html' => new HtmlString($body), 'text' => new HtmlString(strip_tags($body))],
                [],
                function ($message) use ($account, $to) {
                    $sender = $account->sender();
                    $message->from($sender['address'], $sender['name'])->to($to)->subject('Netvork Mails test - ' . $account->email);
                }
            );

            return response()->json(['data' => ['ok' => true, 'message' => "Test message sent to {$to}."]]);
        } catch (Throwable $e) {
            return response()->json(['data' => [
                'ok' => false,
                'message' => MailConnector::explain($e, $account->smtp_host, (int) $account->smtp_port, 'outgoing'),
            ]]);
        }
    }

    /** What the receiving world can check: SPF, DKIM, DMARC, and a score. */
    public function dns(Request $request, MailAccount $account, MailDns $dns): JsonResponse
    {
        $me = $this->reachable($request, $account);
        abort_unless($this->mayManage($me, $account), 403, 'Ask your Admin to run this for a shared mailbox.');

        $selector = (string) ($request->validate(['selector' => ['nullable', 'string', 'max:120']])['selector'] ?? '') ?: (string) $account->dkim_selector;
        $result = $dns->check($account->email, $selector ?: null);

        $account->forceFill([
            'dns' => $result,
            'dkim_selector' => $result['dkim']['selector'] ?? ($selector ?: null),
        ])->save();

        return response()->json(['data' => $result]);
    }

    /**
     * The same servers again under another address.
     *
     * Companies buy one mail service and run a dozen addresses on it. Typing
     * the same four servers twelve times is how one of them ends up wrong.
     */
    public function replicate(Request $request, MailAccount $account): JsonResponse
    {
        $me = $this->reachable($request, $account);
        abort_unless($me->crm_role === 'admin', 403, 'Only your Company Admin can copy a mailbox.');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'label' => ['nullable', 'string', 'max:120'],
            'member' => ['nullable', 'uuid'],
            'imap_password' => ['nullable', 'string', 'max:512'],
            'smtp_password' => ['nullable', 'string', 'max:512'],
            'same_credentials' => ['boolean'],
        ]);

        $owner = $request->filled('member') ? $this->personIn($me, (string) $data['member']) : $me;
        $limit = MailAccess::limitFor($owner);
        abort_if(MailAccount::for($owner)->count() >= $limit, 422, ($owner->user?->name ?: 'That person') . " already holds {$limit} mailbox(es).");

        $same = $request->boolean('same_credentials', true);
        $copy = $account->replicate(['uuid', 'sync_state', 'last_synced_at', 'last_error', 'dns', 'sent_today', 'cap_date', 'detached_at']);
        $copy->fill([
            'email' => $data['email'],
            'label' => $data['label'] ?? $account->label,
            'member_id' => $owner->id,
            'created_by_member_id' => $owner->id === $me->id ? null : $me->id,
            'is_default' => false,
            'is_shared' => false,
            'status' => 'active',
            // The same servers, but this address's own sign-in unless told otherwise.
            'imap_username' => $same ? $account->imap_username : $data['email'],
            'smtp_username' => $same ? $account->smtp_username : $data['email'],
            'imap_password' => $same ? $account->imap_password : ($data['imap_password'] ?? null),
            'smtp_password' => $same ? $account->smtp_password : ($data['smtp_password'] ?? $data['imap_password'] ?? null),
        ]);
        $copy->save();

        if ($copy->canReceive()) {
            SyncMailAccount::dispatch($copy->id);
        }

        return response()->json(['message' => 'Mailbox copied.', 'data' => $copy->fresh(['sharedMembers.user'])->serialize()], 201);
    }

    /**
     * A picture for a signature - a logo, usually.
     *
     * Kept on the public disk and referenced by its address, because a
     * signature image has to be fetchable by whoever receives the mail. Mail
     * programs refuse embedded data: images, so a URL is the only kind that
     * actually shows.
     */
    public function signatureImage(Request $request, MailAccount $account): JsonResponse
    {
        $me = $this->reachable($request, $account);
        abort_unless($this->mayManage($me, $account), 403, 'Ask your Admin to change a shared mailbox.');

        $request->validate([
            'image' => ['required', 'image', 'mimes:png,jpg,jpeg,gif,webp', 'max:1024'],
        ], [], ['image' => 'picture']);

        $file = $request->file('image');
        $path = $file->store('mail-signatures/' . $account->uuid, 'public');

        return response()->json(['data' => [
            'path' => $path,
            'url' => rtrim((string) config('app.url'), '/') . Storage::disk('public')->url($path),
        ]]);
    }

    /** Bring it up to date now, rather than at the next five minutes. */
    public function sync(Request $request, MailAccount $account, MailSync $sync): JsonResponse
    {
        $this->reachable($request, $account);

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

    // ---- the rules ------------------------------------------------------------------

    private function validated(Request $request, bool $creating): array
    {
        return collect($request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:60'],
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
            'signature_reply_html' => ['nullable', 'string', 'max:20000'],
            'signature_on' => ['nullable', Rule::in(['new', 'all', 'none'])],
            'signature_before_quote' => ['boolean'],
            'auto_reply' => ['nullable', 'array'],
            'auto_reply.enabled' => ['boolean'],
            'auto_reply.subject' => ['nullable', 'string', 'max:255'],
            'auto_reply.body' => ['nullable', 'string', 'max:5000'],
            'auto_reply.from' => ['nullable', 'date_format:Y-m-d'],
            'auto_reply.until' => ['nullable', 'date_format:Y-m-d'],
            'forward_to' => ['nullable', 'email', 'max:255'],
            'is_default' => ['boolean'],
            'daily_cap' => ['nullable', 'integer', 'between:1,100000'],
            'dkim_selector' => ['nullable', 'string', 'max:120'],
            'verify_cert' => ['boolean'],
            'shared_with' => ['array'],
            'shared_with.*' => ['uuid'],
            'member' => ['nullable', 'uuid'],
        ]))->except(['shared_with', 'member'])->all();
    }

    /** The fields only a Company Admin may set, dropped for everybody else. */
    private function adminOnly(array $data, bool $admin): array
    {
        if (! $admin) {
            unset($data['daily_cap'], $data['tag']);
        }

        return $data;
    }

    /** Who else may open this mailbox. Named people only, this company only. */
    private function share(Member $admin, MailAccount $account, array $uuids): void
    {
        $ids = Member::where('organization_id', $admin->organization_id)
            ->whereIn('uuid', $uuids)->where('id', '!=', $account->member_id)
            ->pluck('id')->all();

        $account->sharedMembers()->sync($ids);
        $account->forceFill(['is_shared' => count($ids) > 0])->save();
    }

    /** Everybody who could be given a mailbox - for the Admin's pickers. */
    private function people(Member $me): array
    {
        return Member::visible()->with('user:id,name,email')
            ->where('organization_id', $me->organization_id)->where('status', 'active')
            ->get()
            ->map(fn (Member $m) => [
                'uuid' => $m->uuid,
                'name' => $m->user?->name ?: $m->user?->email,
                'email' => $m->user?->email,
                'has_mails' => $m->crm_role === 'admin' || $m->can('mails'),
            ])->values()->all();
    }

    /** A mailbox that just signed in has nothing left to complain about. */
    private function settled(MailAccount $account, bool $ok): void
    {
        if ($ok && ($account->last_error || $account->status !== 'active')) {
            $account->forceFill(['last_error' => null, 'status' => 'active'])->save();
        }
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('crm_member');
    }

    private function personIn(Member $me, string $uuid): Member
    {
        return Member::where('organization_id', $me->organization_id)->where('uuid', $uuid)->firstOrFail();
    }

    /** Reachable at all: mine, shared with me, or - for an Admin - my company's. */
    private function reachable(Request $request, MailAccount $account): Member
    {
        $me = $this->member($request);
        $mine = $account->member_id === $me->id
            || $account->sharedMembers()->where('crm_members.id', $me->id)->exists()
            || ($me->crm_role === 'admin' && $account->organization_id === $me->organization_id);

        abort_unless($mine, 404);

        return $me;
    }

    /** Whose settings are these to change: the owner's, and the Admin's. */
    private function mayManage(Member $me, MailAccount $account): bool
    {
        return $account->member_id === $me->id || $me->crm_role === 'admin';
    }
}
