<?php

namespace App\Console\Commands;

use App\Models\ProjectEntry;
use App\Notifications\SocialNotification;
use Illuminate\Console\Command;

/** Ring due project-entry reminders (in-app + push + email per preferences). */
class ProcessProjectReminders extends Command
{
    protected $signature = 'mypa:project-reminders';

    protected $description = 'Send due project ledger entry reminders';

    public function handle(): int
    {
        $sent = 0;

        ProjectEntry::with('project.user.settings')
            ->whereNotNull('reminder_at')
            ->whereNull('reminder_sent_at')
            ->where('reminder_at', '<=', now())
            ->chunkById(200, function ($entries) use (&$sent) {
                foreach ($entries as $entry) {
                    $verb = $entry->direction === 'credit' ? 'received from' : 'given to';
                    $entry->project->user->notify(new SocialNotification(
                        'project_reminder',
                        "Ledger reminder ({$entry->project->name}): {$entry->description}"
                            . ($entry->counterparty ? " — {$entry->currency} {$entry->amount} {$verb} {$entry->counterparty}" : ''),
                        ['project_uuid' => $entry->project->uuid, 'entry_uuid' => $entry->uuid],
                        '/projects',
                    ));
                    $entry->updateQuietly(['reminder_sent_at' => now()]);
                    $sent++;
                }
            });

        $this->info("Sent {$sent} project reminder(s).");

        return self::SUCCESS;
    }
}
