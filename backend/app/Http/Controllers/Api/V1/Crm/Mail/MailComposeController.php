<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Http\Controllers\Controller;
use App\Jobs\SendMailMessage;
use App\Models\Crm\MailAccount;
use App\Models\Crm\MailAttachment;
use App\Models\Crm\MailMessage;
use App\Models\Crm\Member;
use App\Services\Mail\MailAccess;
use App\Services\Mail\MailConnector;
use App\Services\Mail\MailHtml;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Writing mail: drafts, sending, scheduling - and taking it back.
 *
 * Nothing goes the moment Send is pressed. It waits in the outbox for the
 * person's undo window - ten seconds unless they chose otherwise - and can
 * be pulled back to a draft until then; a scheduled mail waits for its time
 * the same way. The actual sending is a queued job, so a slow mail server
 * never holds the screen.
 */
class MailComposeController extends Controller
{
    public function compose(Request $request, MailConnector $connector): JsonResponse
    {
        $me = $this->member($request);
        $data = $request->validate([
            'action' => ['required', Rule::in(['draft', 'send', 'schedule'])],
            'draft' => ['nullable', 'uuid'],
            'account' => ['required', 'uuid'],
            'to' => ['nullable', 'array', 'max:100'],
            'to.*' => ['string', 'max:320'],
            'cc' => ['nullable', 'array', 'max:100'],
            'cc.*' => ['string', 'max:320'],
            'bcc' => ['nullable', 'array', 'max:100'],
            'bcc.*' => ['string', 'max:320'],
            'subject' => ['nullable', 'string', 'max:998'],
            'body_html' => ['nullable', 'string', 'max:2000000'],
            'reply_to_uuid' => ['nullable', 'uuid'],
            'forward_uuid' => ['nullable', 'uuid'],
            'include_attachments' => ['nullable', 'boolean'],
            'scheduled_for' => ['nullable', 'date', 'required_if:action,schedule'],
            'remove_attachments' => ['nullable', 'array'],
            'remove_attachments.*' => ['integer'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['file', 'max:25600'],
        ]);

        $account = MailAccount::for($me)->where('uuid', $data['account'])->firstOrFail();

        $to = self::recipients($data['to'] ?? [], 'to');
        $cc = self::recipients($data['cc'] ?? [], 'cc');
        $bcc = self::recipients($data['bcc'] ?? [], 'bcc');

        if ($data['action'] !== 'draft') {
            if ($to === [] && $cc === [] && $bcc === []) {
                throw ValidationException::withMessages(['to' => ['Add at least one recipient.']]);
            }
            abort_unless($account->canSend(), 422, 'This mailbox cannot send yet - add its outgoing (SMTP) server in Mail settings.');
        }

        // Somebody else's half-written mail is not to be taken over, even on
        // a mailbox they share with you.
        $message = ! empty($data['draft'])
            ? MailMessage::where('uuid', $data['draft'])->where('mail_account_id', $account->id)
                ->whereIn('folder', ['drafts', 'outbox'])
                ->where(fn ($q) => $q->whereNull('author_member_id')->orWhere('author_member_id', $me->id))
                ->firstOrFail()
            : new MailMessage([
                'organization_id' => $me->organization_id,
                'mail_account_id' => $account->id,
                'author_member_id' => $me->id,
                'folder' => 'drafts',
            ]);

        // A draft can move to another of the person's mailboxes.
        $message->mail_account_id = $account->id;
        $message->author_member_id ??= $me->id;

        $html = MailHtml::sanitize($data['body_html'] ?? '') ?? '';
        $text = trim(html_entity_decode(strip_tags((string) preg_replace('/<br\s*\/?>|<\/p>|<\/div>/i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $message->fill([
            'from_name' => $account->from_name ?: $account->email,
            'from_email' => $account->email,
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $data['subject'] ?? '',
            'body_html' => $html,
            'body_text' => $text,
            'snippet' => MailHtml::snippet($text, $html),
            'date' => now(),
            'is_read' => true,
        ]);

        // A reply joins the conversation it answers - here and in the other side's mail program.
        $source = null;
        if (! empty($data['reply_to_uuid']) || ! empty($data['forward_uuid'])) {
            $source = MailMessage::where('uuid', $data['reply_to_uuid'] ?? $data['forward_uuid'])
                ->whereIn('mail_account_id', MailAccount::for($me)->pluck('id'))->first();
        }
        if ($source && ! empty($data['reply_to_uuid'])) {
            $message->in_reply_to = $source->message_id;
            $message->reference_ids = trim(($source->reference_ids ?? '') . ' ' . ($source->message_id ?? '')) ?: null;
            $message->thread_key = $source->thread_key;
        }
        $message->thread_key ??= MailMessage::threadKeyFor($message->reference_ids, $message->in_reply_to, null, $message->subject);

        $message->save();

        $this->attachments($request, $message, $data, $source, $connector);

        $message->has_attachments = $message->attachments()->exists();

        if ($data['action'] === 'draft') {
            $message->fill(['folder' => 'drafts', 'status' => null, 'send_after' => null, 'scheduled_for' => null])->save();

            return response()->json(['message' => 'Draft saved.', 'data' => $message->fresh(['account', 'labels'])->summary()]);
        }

        /*
         * Everybody written to is worth remembering.
         *
         * Names and addresses are typed once and then suggested for ever
         * after - which is the difference between an address book and a
         * pile of mail somebody has to search through to find an address
         * they have used twenty times.
         */
        if ($data['action'] !== 'draft') {
            foreach ([...$to, ...$cc, ...$bcc] as $person) {
                // Filed under the mailbox it was written from, so each
                // mailbox suggests the people that mailbox writes to.
                \App\Models\Crm\MailContact::remember($me, (string) ($person['email'] ?? ''), $person['name'] ?? null, true, $message->mail_account_id);
            }
        }

        if ($data['action'] === 'schedule') {
            /*
             * Into this application's own timezone before it is stored.
             *
             * The browser sends the moment as UTC, and Eloquent writes a
             * Carbon out in whatever zone it is carrying - so a UTC one was
             * stored as UTC digits in a column every comparison reads as
             * local time. A mail scheduled for midnight was written down as
             * half past six the previous evening: already past, so it went
             * out at once, and the screen showed two different times for the
             * same message.
             */
            $when = Carbon::parse($data['scheduled_for'])->setTimezone(config('app.timezone'));
            abort_if($when->lte(now()->addMinute()), 422, 'Pick a time at least a minute from now.');
            $message->fill(['folder' => 'scheduled', 'status' => 'queued', 'scheduled_for' => $when, 'send_after' => $when, 'error' => null])->save();
            SendMailMessage::dispatch($message->id)->delay($when);

            return response()->json([
                'message' => 'Scheduled for ' . $when->timezone(config('app.timezone'))->format('j M, g:i A') . '.',
                'data' => $message->fresh(['account', 'labels'])->summary(),
            ]);
        }

        $undo = (int) MailAccess::prefs($me)['undo_seconds'];
        $after = now()->addSeconds(max(0, min(30, $undo)));
        $message->fill(['folder' => 'outbox', 'status' => 'queued', 'send_after' => $after, 'scheduled_for' => null, 'error' => null])->save();
        SendMailMessage::dispatch($message->id)->delay($after);

        return response()->json([
            'message' => $undo > 0 ? 'Sending…' : 'Sent.',
            'data' => $message->fresh(['account', 'labels'])->summary() + ['undo_seconds' => $undo],
        ]);
    }

    /** Pull it back: Undo on a sending mail, or cancelling a scheduled one. */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $message = $this->own($request, $uuid);

        $pulled = MailMessage::where('id', $message->id)->where('status', 'queued')
            ->update(['status' => null, 'folder' => 'drafts', 'send_after' => null, 'scheduled_for' => null]);

        abort_if($pulled !== 1, 422, 'Too late - it has already gone.');

        return response()->json(['message' => 'Back in your drafts.', 'data' => $message->fresh(['account', 'attachments', 'labels'])->full()]);
    }

    /** A scheduled mail, now; or a failed one, again. */
    public function sendNow(Request $request, string $uuid): JsonResponse
    {
        $message = $this->own($request, $uuid);
        abort_unless(in_array($message->status, ['queued', 'failed'], true), 422, 'There is nothing waiting to send.');

        $message->update(['status' => 'queued', 'folder' => 'outbox', 'send_after' => now(), 'error' => null]);
        SendMailMessage::dispatch($message->id);

        return response()->json(['message' => 'Sending now.']);
    }

    private function attachments(Request $request, MailMessage $message, array $data, ?MailMessage $source, MailConnector $connector): void
    {
        if (! empty($data['remove_attachments'])) {
            $message->attachments()->whereIn('id', $data['remove_attachments'])->get()->each(function (MailAttachment $a) {
                if ($a->path) {
                    Storage::disk('local')->delete($a->path);
                }
                $a->delete();
            });
        }

        foreach ((array) $request->file('attachments', []) as $file) {
            $name = mb_substr($file->getClientOriginalName() ?: 'attachment', 0, 200);
            $path = $file->storeAs('mail-outgoing/' . $message->uuid, Str::random(8) . '-' . preg_replace('/[^\w.\- ]+/u', '_', $name), 'local');
            $message->attachments()->create([
                'filename' => $name, 'mime' => $file->getClientMimeType(), 'size' => $file->getSize(), 'path' => $path,
            ]);
        }

        /*
         * A forward carries the original's files - copied from the server now,
         * once, since the mail going out has to hold them itself.
         */
        if ($source && ! empty($data['forward_uuid']) && ($data['include_attachments'] ?? true)
            && ! $message->attachments()->exists() && $source->remote_folder && $source->uid) {
            try {
                $client = $connector->imap($source->account);
                $remote = $client->getFolderByPath($source->remote_folder)
                    ?->query()->leaveUnread()->setFetchBody(true)->getMessageByUid($source->uid);
                foreach ($remote?->getAttachments() ?? [] as $attachment) {
                    $name = mb_substr((string) ($attachment->filename ?: $attachment->name ?: 'attachment'), 0, 200);
                    $path = 'mail-outgoing/' . $message->uuid . '/' . Str::random(8) . '-' . preg_replace('/[^\w.\- ]+/u', '_', $name);
                    Storage::disk('local')->put($path, (string) $attachment->getContent());
                    $message->attachments()->create([
                        'filename' => $name, 'mime' => $attachment->content_type, 'size' => (int) $attachment->size ?: null, 'path' => $path,
                    ]);
                }
                $client->disconnect();
            } catch (Throwable) {
                // The forward still goes; the person sees it arrive without the files and can attach them.
            }
        }
    }

    /**
     * "Priyanshu <p@x.com>", "p@x.com", or a name with an address - into
     * the one shape the rest of Mails reads, refusing anything that is not
     * an address.
     *
     * @return list<array{email: string, name: ?string}>
     */
    public static function recipients(array $raw, string $field): array
    {
        $out = [];
        foreach ($raw as $entry) {
            foreach (preg_split('/[,;]+/', (string) $entry) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (preg_match('/^(.*)<([^>]+)>\s*$/', $part, $m)) {
                    $name = trim($m[1], " \"'");
                    $email = trim($m[2]);
                } else {
                    $name = null;
                    $email = $part;
                }
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages([$field => ["\"{$part}\" is not an email address."]]);
                }
                $out[strtolower($email)] = ['email' => $email, 'name' => $name ?: null];
            }
        }

        return array_values($out);
    }

    private function own(Request $request, string $uuid): MailMessage
    {
        return MailMessage::where('uuid', $uuid)
            ->whereIn('mail_account_id', MailAccount::for($this->member($request))->pluck('id'))
            ->firstOrFail();
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('crm_member');
    }
}
