<?php

namespace App\Jobs;

use App\Models\Crm\MailAccount;
use App\Services\Mail\MailConnector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What somebody did to a mail here, done to the server's copy too.
 *
 * Read, starred, moved, deleted: in the background, so opening a mail never
 * waits on a round trip to somebody's mail server - and best-effort, because
 * the change has already happened here and a server that is briefly down
 * should not undo it.
 */
class MailRemoteChange implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @param  string  $action  seen | unseen | flag | unflag | move | delete
     */
    public function __construct(
        public int $accountId,
        public string $remoteFolder,
        public int $uid,
        public string $action,
        public ?string $toFolder = null,
    ) {
        // Another round trip to somebody's IMAP server, so it queues with
        // the rest of the mail work rather than in front of a send.
        $this->onQueue('mail');
    }

    public function handle(MailConnector $connector): void
    {
        $account = MailAccount::find($this->accountId);
        if (! $account || ! $account->canReceive()) {
            return;
        }

        try {
            $client = $connector->imap($account);
            $folder = $client->getFolderByPath($this->remoteFolder);
            $message = $folder?->query()->leaveUnread()->setFetchBody(false)->getMessageByUid($this->uid);
            if (! $message) {
                $client->disconnect();

                return;
            }

            match ($this->action) {
                'seen' => $message->setFlag('Seen'),
                'unseen' => $message->unsetFlag('Seen'),
                'flag' => $message->setFlag('Flagged'),
                'unflag' => $message->unsetFlag('Flagged'),
                'move' => ($target = $account->sync_state['_folders'][$this->toFolder] ?? null) ? $message->move($target, true) : null,
                'delete' => $message->delete(true),
                default => null,
            };

            $client->disconnect();
        } catch (Throwable $e) {
            Log::info('[mails] could not apply a change on the server', [
                'account' => $this->accountId, 'action' => $this->action, 'error' => $e->getMessage(),
            ]);
        }
    }
}
