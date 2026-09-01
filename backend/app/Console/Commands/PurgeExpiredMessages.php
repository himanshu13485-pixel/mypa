<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Disappearing messages, actually disappearing.
 *
 * A conversation with a retention span keeps nothing older than it. The rows
 * are deleted outright rather than soft-deleted: a promise that a message is
 * gone after seven days is not kept by a row that sits in the table with a
 * timestamp on it. Attachments go with their message, files included, or the
 * disk would quietly keep what the chat was told to forget.
 *
 * Conversations with no span set — every one of them by default — are never
 * touched.
 */
class PurgeExpiredMessages extends Command
{
    protected $signature = 'chat:purge-expired {--dry-run : Count what would go, delete nothing}';

    protected $description = 'Delete messages older than each conversation\'s retention span';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $totalMessages = 0;
        $totalFiles = 0;

        Conversation::whereNotNull('auto_delete_hours')
            ->chunkById(100, function ($conversations) use ($dry, &$totalMessages, &$totalFiles) {
                foreach ($conversations as $conversation) {
                    $cutoff = now()->subHours((int) $conversation->auto_delete_hours);

                    Message::withTrashed()
                        ->where('conversation_id', $conversation->id)
                        ->where('created_at', '<', $cutoff)
                        ->with('attachments')
                        ->chunkById(200, function ($messages) use ($dry, &$totalMessages, &$totalFiles) {
                            foreach ($messages as $message) {
                                foreach ($message->attachments as $attachment) {
                                    $totalFiles++;
                                    if (! $dry) {
                                        Storage::disk('local')->delete($attachment->path);
                                    }
                                }
                                $totalMessages++;
                                if (! $dry) {
                                    // The attachment rows and reactions cascade
                                    // from the message's own foreign keys.
                                    $message->forceDelete();
                                }
                            }
                        });
                }
            });

        $this->info(($dry ? 'Would delete ' : 'Deleted ')
            . $totalMessages . ' message(s) and ' . $totalFiles . ' attachment file(s).');

        return self::SUCCESS;
    }
}
