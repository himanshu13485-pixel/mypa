<?php

namespace App\Notifications;

use App\Models\TaskReminder;
use App\Notifications\Concerns\BroadcastsTheStoredRow;
use App\Support\Alerts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskReminderNotification extends Notification implements ShouldQueue
{
    use BroadcastsTheStoredRow;
    use Queueable;

    public function __construct(public TaskReminder $reminder)
    {
    }

    public function via(object $notifiable): array
    {
        $channels = $this->reminder->channels ?? ['in_app'];
        $via = SocialNotification::BELL;

        if (in_array('email', $channels) && SocialNotification::wantsMail($notifiable)) {
            $via[] = 'mail';
        }

        if (SocialNotification::wantsPush($notifiable)) {
            $via[] = \App\Notifications\Channels\WebPushChannel::class;
            $via[] = \App\Notifications\Channels\FcmChannel::class;
        }

        return $via;
    }

    /** System notification shown by the device even when the app is closed. */
    public function toPush(object $notifiable): array
    {
        $task = $this->reminder->task;

        return [
            'title' => 'Task reminder',
            'body' => $task->due_at
                ? "{$task->title} - due " . $task->due_at->diffForHumans()
                : $task->title,
            'tag' => 'task-' . $task->uuid,
            'url' => '/tasks?open=' . $task->uuid,
            'kind' => 'task_reminder',
            'channel' => Alerts::channelOf('task_reminder'),
        ];
    }

    public function pushOptions(): array
    {
        return Alerts::optionsOf('task_reminder');
    }

    public function toDatabase(object $notifiable): array
    {
        $task = $this->reminder->task;

        return [
            'kind' => 'task_reminder',
            'reminder_id' => $this->reminder->id,
            'task_uuid' => $task->uuid,
            'task_title' => $task->title,
            'due_at' => $task->due_at?->toIso8601String(),
            'priority' => $task->priority,
            'message' => $task->due_at
                ? "Reminder: “{$task->title}” is due " . $task->due_at->diffForHumans() . '.'
                : "Reminder: “{$task->title}”.",
            'actions' => ['open', 'complete', 'snooze', 'dismiss'],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $task = $this->reminder->task;
        $url = config('mypa.frontend_url') . '/tasks?open=' . $task->uuid;

        $mail = (new MailMessage)
            ->subject('Reminder: ' . $task->title)
            ->greeting('Hello ' . $notifiable->name . ',')
            ->line($task->due_at
                ? "Your task “{$task->title}” is due " . $task->due_at->diffForHumans() . '.'
                : "You asked to be reminded about “{$task->title}”.")
            ->action('Open task', $url);

        if ($task->description) {
            $mail->line(str($task->description)->limit(200));
        }

        return $mail;
    }
}
