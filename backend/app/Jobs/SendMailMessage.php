<?php

namespace App\Jobs;

use App\Models\Crm\MailMessage;
use App\Services\Mail\MailConnector;
use App\Services\Mail\MailSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One mail, sent - once its undo window or its scheduled time has passed.
 *
 * Claimed with a single conditional update before anything goes out, so the
 * job and the every-minute sweep that backs it up can never both send it:
 * whichever flips "queued" to "sending" first sends, and the other finds
 * nothing to do. Undo is the same move the other way - back to a draft
 * while it is still queued.
 */
class SendMailMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $messageId)
    {
    }

    public function handle(MailSender $sender): void
    {
        $message = MailMessage::find($this->messageId);
        if (! $message || $message->status !== 'queued') {
            return;
        }

        $due = $message->send_after ?? $message->scheduled_for;
        if ($due && $due->isFuture()) {
            // Early - the queue ran it before its time. It will be swept up when due.
            return;
        }

        $claimed = MailMessage::where('id', $message->id)->where('status', 'queued')->update(['status' => 'sending']);
        if ($claimed !== 1) {
            return;
        }

        $message->refresh();

        /*
         * The mailbox's daily limit, if it has one.
         *
         * Over it, the mail waits for tomorrow rather than failing: the
         * limit exists to stay under what the mail provider allows, and a
         * provider that is pushed past its limit suspends the account.
         */
        $account = $message->account;
        if ($account && ! $account->claimSend()) {
            $message->update([
                'status' => 'queued',
                'folder' => 'outbox',
                'send_after' => now()->addDay()->startOfDay()->addMinutes(5),
                'error' => "This mailbox has used its {$account->daily_cap} sends for today. It will go out tomorrow morning.",
            ]);

            return;
        }

        try {
            $sender->send($message);
            $message->update(['folder' => 'sent', 'status' => 'sent', 'date' => now(), 'error' => null, 'is_read' => true]);
        } catch (Throwable $e) {
            $message->update(['folder' => 'outbox', 'status' => 'failed', 'error' => MailConnector::plain($e)]);
        }
    }
}
