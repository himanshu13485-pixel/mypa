<?php

namespace App\Notifications;

use App\Models\Bill;
use App\Models\Task;
use App\Support\Alerts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "This is due now" — on the phone, with the app shut.
 *
 * The screen alarm only rings a page somebody has open, which is the wrong
 * half of the day: a due date passes while the laptop is closed and the
 * person is on a train. This is the same alarm, sent the way a phone can
 * hear it.
 *
 * One notification for both modules because it is one sentence with a
 * different noun in it, and because two would drift apart the first time
 * either was edited.
 */
class DueNowNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Task|Bill $item)
    {
    }

    public function via(object $notifiable): array
    {
        /*
         * Push alone, deliberately.
         *
         * The bell already carries the task and bill reminders this company
         * set up; this exists for the device that is not looking at the app,
         * and a second bell row for the same due date would be noise in the
         * one place somebody goes to catch up.
         */
        if (! SocialNotification::wantsPush($notifiable)) {
            return [];
        }

        return [
            \App\Notifications\Channels\WebPushChannel::class,
            \App\Notifications\Channels\FcmChannel::class,
        ];
    }

    private function isTask(): bool
    {
        return $this->item instanceof Task;
    }

    private function kind(): string
    {
        return $this->isTask() ? 'task_reminder' : 'bill_alarm';
    }

    private function title(): string
    {
        return $this->isTask() ? 'Task due now' : 'Bill due now';
    }

    private function body(): string
    {
        if ($this->item instanceof Task) {
            return '“' . $this->item->title . '” is due.';
        }

        $amount = $this->item->amount
            ? ' (' . ($this->item->currency ?: 'INR') . ' ' . number_format((float) $this->item->amount, 2) . ')'
            : '';

        return '“' . $this->item->name . '” is due' . $amount . '.';
    }

    public function toPush(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->isTask() ? '/tasks' : '/bills',
            // Per item: two things due the same morning are two things to
            // do, and a shared tag would show only the second of them.
            'tag' => ($this->isTask() ? 'task-due-' : 'bill-due-') . $this->item->uuid,
            'kind' => $this->kind(),
            'channel' => Alerts::channelOf($this->kind()),
        ];
    }

    public function pushOptions(): array
    {
        return Alerts::optionsOf($this->kind());
    }

    public function toFcm(object $notifiable): array
    {
        return $this->toPush($notifiable);
    }
}
