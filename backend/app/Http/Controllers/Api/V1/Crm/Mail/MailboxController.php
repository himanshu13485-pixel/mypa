<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Http\Controllers\Controller;
use App\Jobs\MailRemoteChange;
use App\Models\Crm\MailAccount;
use App\Models\Crm\MailAttachment;
use App\Models\Crm\MailLabel;
use App\Models\Crm\MailMessage;
use App\Models\Crm\Member;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailConnector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Reading mail: the folders, the lists, the mail itself, and everything done
 * to it - read, starred, labelled, moved, thrown away.
 *
 * Only ever the person's own mailboxes. Every query starts from the
 * accounts they added, so a message from somebody else's mailbox is simply
 * not found rather than refused.
 */
class MailboxController extends Controller
{
    /** Where each move sends a mail, and the matching folder on the server. */
    private const MOVES = ['trash', 'spam', 'archive', 'inbox'];

    public function dashboard(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $accounts = MailAccount::for($me)->orderByDesc('is_default')->get();
        $ids = $accounts->pluck('id');
        $mine = fn () => MailMessage::whereIn('mail_account_id', $ids);

        return response()->json(['data' => [
            'accounts' => $accounts->map(fn (MailAccount $a) => [
                'uuid' => $a->uuid,
                'email' => $a->email,
                'label' => $a->label,
                'unread' => MailMessage::where('mail_account_id', $a->id)->where('folder', 'inbox')->where('is_read', false)->count(),
                'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                'last_error' => $a->last_error,
                'can_send' => $a->canSend(),
                'can_receive' => $a->canReceive(),
            ])->values(),
            'unread' => $mine()->where('folder', 'inbox')->where('is_read', false)->count(),
            'received_today' => $mine()->where('folder', 'inbox')->where('date', '>=', now()->startOfDay())->count(),
            'sent_today' => $mine()->where('folder', 'sent')->where('date', '>=', now()->startOfDay())->count(),
            'scheduled' => $mine()->where('folder', 'scheduled')->where('status', 'queued')->count(),
            'failed' => $mine()->where('status', 'failed')->count(),
            'drafts' => $mine()->where('folder', 'drafts')->count(),
            'recent_unread' => $mine()->where('folder', 'inbox')->where('is_read', false)
                ->orderByDesc('date')->limit(6)->with('account')->get()->map(fn (MailMessage $m) => $m->summary())->values(),
            'upcoming' => $mine()->where('folder', 'scheduled')->where('status', 'queued')
                ->orderBy('scheduled_for')->limit(5)->with('account')->get()->map(fn (MailMessage $m) => $m->summary())->values(),
            'limit' => MailAccess::limitFor($me),
        ]]);
    }

    /** The numbers beside each folder and label. */
    public function counts(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $base = fn () => $this->scope($request, $me);

        $counts = [];
        foreach (MailMessage::FOLDERS as $folder) {
            $query = $base()->where('folder', $folder);
            // Inbox and spam count what is unread; the rest count what is there.
            $counts[$folder] = in_array($folder, ['inbox', 'spam'], true)
                ? $query->where('is_read', false)->count()
                : $query->count();
        }
        $counts['starred'] = $base()->where('is_starred', true)->where('folder', '!=', 'trash')->count();

        $labels = MailLabel::where('member_id', $me->id)->orderBy('name')->get()->map(fn (MailLabel $l) => [
            'uuid' => $l->uuid,
            'name' => $l->name,
            'color' => $l->color,
            'count' => $base()->whereHas('labels', fn ($q) => $q->where('crm_mail_labels.id', $l->id))->where('is_read', false)->count(),
        ])->values();

        return response()->json(['data' => ['folders' => $counts, 'labels' => $labels]]);
    }

    public function index(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $data = $request->validate([
            'folder' => ['nullable', Rule::in([...MailMessage::FOLDERS, 'starred'])],
            'label' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:200'],
            'unread' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->scope($request, $me);
        $folder = $data['folder'] ?? 'inbox';

        if (! empty($data['label'])) {
            $query->whereHas('labels', fn ($q) => $q->where('crm_mail_labels.uuid', $data['label']));
        } elseif ($folder === 'starred') {
            $query->where('is_starred', true)->where('folder', '!=', 'trash');
        } else {
            $query->where('folder', $folder);
        }

        if (! empty($data['q'])) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['q']) . '%';
            $query->where(fn ($q) => $q->whereRaw("subject LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("from_email LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("from_name LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("snippet LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("body_text LIKE ? ESCAPE '!'", [$like]));
        }
        if ($request->boolean('unread')) {
            $query->where('is_read', false);
        }

        /*
         * Conversations, when the person reads that way.
         *
         * One row per thread - its newest mail - with how many it holds and
         * how many are unread. Not for drafts, the outbox or the scheduled
         * folder, where each mail is its own piece of work.
         */
        // How many rows a page holds is the reader's own choice.
        $prefs = MailAccess::prefs($me);
        $per = (int) ($prefs['page_size'] ?? 50);
        $per = in_array($per, [25, 50, 100], true) ? $per : 50;

        $threaded = $prefs['conversation'] && ! in_array($folder, ['drafts', 'outbox', 'scheduled'], true);
        if ($threaded) {
            $latest = (clone $query)->selectRaw('max(id) as id')->groupBy('mail_account_id', 'thread_key');
            $page = MailMessage::whereIn('id', $latest)->with(['account', 'labels'])
                ->orderByDesc('date')->orderByDesc('id')->paginate($per);

            $threads = (clone $query)->whereIn('thread_key', $page->getCollection()->pluck('thread_key'))
                ->selectRaw('mail_account_id, thread_key, count(*) as total, sum(case when is_read = 0 then 1 else 0 end) as unread')
                ->groupBy('mail_account_id', 'thread_key')->get()
                ->keyBy(fn ($t) => $t->mail_account_id . '|' . $t->thread_key);

            $page->getCollection()->transform(function (MailMessage $m) use ($threads) {
                $t = $threads[$m->mail_account_id . '|' . $m->thread_key] ?? null;

                return $m->summary() + ['thread_count' => (int) ($t->total ?? 1), 'thread_unread' => (int) ($t->unread ?? 0)];
            });
        } else {
            $order = $folder === 'scheduled' ? 'scheduled_for' : 'date';
            $page = $query->with(['account', 'labels'])->orderBy($order, $folder === 'scheduled' ? 'asc' : 'desc')
                ->orderByDesc('id')->paginate($per);
            $page->getCollection()->transform(fn (MailMessage $m) => $m->summary() + ['thread_count' => 1, 'thread_unread' => $m->is_read ? 0 : 1]);
        }

        return response()->json($page->toArray() + ['threaded' => $threaded]);
    }

    /** One mail, opened - and, in conversation mode, the rest of its thread. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $me = $this->member($request);
        $message = $this->find($request, $me, $uuid)->load(['account', 'attachments', 'labels']);

        $thread = collect([$message]);
        if (MailAccess::prefs($me)['conversation'] && ! in_array($message->folder, ['drafts', 'outbox', 'scheduled'], true)) {
            $thread = MailMessage::where('mail_account_id', $message->mail_account_id)
                ->where('thread_key', $message->thread_key)
                ->whereNotIn('folder', ['drafts', 'outbox', 'scheduled'])
                ->when($message->folder !== 'trash', fn ($q) => $q->where('folder', '!=', 'trash'))
                ->with(['account', 'attachments', 'labels'])->orderBy('date')->orderBy('id')->get();
        }

        // Opening is reading - for every mail in the thread that was waiting.
        foreach ($thread as $mail) {
            if (! $mail->is_read) {
                $mail->update(['is_read' => true]);
                $this->remote($mail, 'seen');
            }
        }

        return response()->json(['data' => [
            'message' => $message->full(),
            'thread' => $thread->map(fn (MailMessage $m) => $m->full())->values(),
        ]]);
    }

    /** Read, starred, labelled - the small changes. */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $me = $this->member($request);
        $message = $this->find($request, $me, $uuid);
        $data = $request->validate([
            'is_read' => ['sometimes', 'boolean'],
            'is_starred' => ['sometimes', 'boolean'],
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['string'],
        ]);

        $this->apply($me, collect([$message]), $data);

        return response()->json(['data' => $message->fresh(['account', 'labels'])->summary()]);
    }

    /** Several at once: the list's tick boxes. */
    public function bulk(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $data = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:500'],
            'uuids.*' => ['uuid'],
            'action' => ['required', Rule::in(['read', 'unread', 'star', 'unstar', 'trash', 'spam', 'archive', 'inbox', 'restore', 'delete', 'label', 'unlabel'])],
            'label' => ['nullable', 'string'],
        ]);

        $messages = $this->scope($request, $me)->whereIn('uuid', $data['uuids'])->get();

        // A conversation row stands for its whole thread in that folder, so
        // trashing it must not leave the older mails behind to resurface.
        if ($request->boolean('thread') && $messages->isNotEmpty()) {
            $query = $this->scope($request, $me);
            $query->where(function ($q) use ($messages) {
                foreach ($messages as $m) {
                    $q->orWhere(fn ($w) => $w->where('mail_account_id', $m->mail_account_id)
                        ->where('thread_key', $m->thread_key)->where('folder', $m->folder));
                }
            });
            $messages = $query->get();
        }

        match ($data['action']) {
            'read' => $this->apply($me, $messages, ['is_read' => true]),
            'unread' => $this->apply($me, $messages, ['is_read' => false]),
            'star' => $this->apply($me, $messages, ['is_starred' => true]),
            'unstar' => $this->apply($me, $messages, ['is_starred' => false]),
            'trash', 'spam', 'archive', 'inbox' => $messages->each(fn ($m) => $this->move($m, $data['action'])),
            'restore' => $messages->each(fn ($m) => $this->move($m, $m->trashed_from ?: 'inbox')),
            'delete' => $messages->each(fn ($m) => $this->purge($m)),
            'label', 'unlabel' => $this->labelMany($me, $messages, (string) $data['label'], $data['action'] === 'label'),
        };

        return response()->json(['message' => $messages->count() . ' updated.']);
    }

    public function move(MailMessage $message, string $to): void
    {
        if (! in_array($to, [...self::MOVES, 'sent', 'drafts'], true) || $message->folder === $to) {
            return;
        }
        $from = $message->folder;
        $message->update([
            'folder' => $to,
            'trashed_from' => $to === 'trash' ? $from : null,
        ]);
        if (in_array($to, self::MOVES, true)) {
            $this->remote($message, 'move', $to);
        }
    }

    /** Gone for good - here, and on the server. Only from Trash or Spam. */
    public function purge(MailMessage $message): void
    {
        if (! in_array($message->folder, ['trash', 'spam', 'drafts'], true)) {
            $this->move($message, 'trash');

            return;
        }
        $this->remote($message, 'delete');
        $message->delete();
    }

    public function emptyTrash(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $messages = $this->scope($request, $me)->where('folder', $request->input('folder') === 'spam' ? 'spam' : 'trash')->get();
        $messages->each(fn (MailMessage $m) => $this->purge($m));

        return response()->json(['message' => $messages->count() . ' deleted for good.']);
    }

    /** An attachment - a local file for mail going out, fetched from the server for mail that arrived. */
    public function attachment(Request $request, string $uuid, int $id, MailConnector $connector): Response
    {
        $me = $this->member($request);
        $message = $this->find($request, $me, $uuid);
        $attachment = MailAttachment::where('mail_message_id', $message->id)->findOrFail($id);
        $disposition = $request->boolean('inline') ? 'inline' : 'attachment';
        $headers = [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
            'Content-Disposition' => $disposition . '; filename="' . addcslashes($attachment->filename, '"\\') . '"',
            // Opened in the browser, never allowed to run.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($attachment->path) {
            $path = storage_path('app/' . ltrim($attachment->path, '/'));
            abort_unless(is_file($path), 404);

            return response()->file($path, $headers);
        }

        abort_unless($message->remote_folder && $message->uid, 404, 'This attachment is not on the server any more.');

        try {
            $client = $connector->imap($message->account);
            $remote = $client->getFolderByPath($message->remote_folder)
                ?->query()->leaveUnread()->setFetchBody(true)->getMessageByUid($message->uid);
            $match = collect($remote?->getAttachments() ?? [])
                ->first(fn ($a) => (string) $a->part_number === (string) $attachment->part)
                ?? collect($remote?->getAttachments() ?? [])->first(fn ($a) => ($a->filename ?: $a->name) === $attachment->filename);
            $content = $match?->getContent();
            $client->disconnect();
        } catch (Throwable $e) {
            abort(502, 'Could not fetch the attachment from the mail server: ' . MailConnector::plain($e));
        }

        abort_if(! isset($content) || $content === null, 404, 'The attachment was not found on the server.');

        return response($content, 200, $headers);
    }

    // ---- The rules every query shares ----------------------------------------

    /** The person's own mail - optionally one mailbox of theirs. */
    private function scope(Request $request, Member $me): Builder
    {
        $accounts = MailAccount::for($me);
        if ($request->filled('account') && $request->input('account') !== 'all') {
            $accounts->where('uuid', $request->input('account'));
        }

        return MailMessage::whereIn('mail_account_id', $accounts->pluck('id'));
    }

    private function find(Request $request, Member $me, string $uuid): MailMessage
    {
        return MailMessage::where('uuid', $uuid)
            ->whereIn('mail_account_id', MailAccount::for($me)->pluck('id'))
            ->firstOrFail();
    }

    private function apply(Member $me, $messages, array $data): void
    {
        foreach ($messages as $message) {
            if (array_key_exists('is_read', $data) && $message->is_read !== (bool) $data['is_read']) {
                $message->update(['is_read' => (bool) $data['is_read']]);
                $this->remote($message, $data['is_read'] ? 'seen' : 'unseen');
            }
            if (array_key_exists('is_starred', $data) && $message->is_starred !== (bool) $data['is_starred']) {
                $message->update(['is_starred' => (bool) $data['is_starred']]);
                $this->remote($message, $data['is_starred'] ? 'flag' : 'unflag');
            }
            if (array_key_exists('labels', $data)) {
                $ids = MailLabel::where('member_id', $me->id)->whereIn('uuid', (array) $data['labels'])->pluck('id');
                $message->labels()->sync($ids);
            }
        }
    }

    private function labelMany(Member $me, $messages, string $labelUuid, bool $add): void
    {
        $label = MailLabel::where('member_id', $me->id)->where('uuid', $labelUuid)->firstOrFail();
        foreach ($messages as $message) {
            $add ? $message->labels()->syncWithoutDetaching([$label->id]) : $message->labels()->detach($label->id);
        }
    }

    /** The same change on the server's copy, when there is one. */
    private function remote(MailMessage $message, string $action, ?string $to = null): void
    {
        if ($message->remote_folder && $message->uid) {
            MailRemoteChange::dispatch($message->mail_account_id, $message->remote_folder, (int) $message->uid, $action, $to);
        }
    }

    /**
     * A picture to put inside a message being written.
     *
     * Kept on the public disk and referenced by its address: a picture
     * embedded as data is refused by most mail programs, so it would arrive
     * as a blank square for the person receiving it.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $me = $this->member($request);
        $request->validate([
            'image' => ['required', 'image', 'mimes:png,jpg,jpeg,gif,webp', 'max:5120'],
        ], [], ['image' => 'picture']);

        $path = $request->file('image')->store('mail-images/' . $me->uuid, 'public');

        return response()->json(['data' => [
            'path' => $path,
            'url' => rtrim((string) config('app.url'), '/') . Storage::disk('public')->url($path),
        ]]);
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('crm_member');
    }
}
