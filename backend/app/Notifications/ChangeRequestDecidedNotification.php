<?php

namespace App\Notifications;

use App\Models\ChangeRequest;
use App\Support\Alerts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ChangeRequestDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ChangeRequest $changeRequest)
    {
    }

    /**
     * A decision somebody else made about your account.
     *
     * This was the bell and nothing else, which meant the answer to a
     * request you had been waiting on sat unread until you happened to
     * open the app — and an approved email or mobile change is not just
     * news, it is a verification code waiting to expire.
     */
    public function via(object $notifiable): array
    {
        $via = ['database'];

        if (SocialNotification::wantsPush($notifiable)) {
            $via[] = \App\Notifications\Channels\WebPushChannel::class;
            $via[] = \App\Notifications\Channels\FcmChannel::class;
        }

        return $via;
    }

    protected function kind(): string
    {
        return 'change_request_' . $this->changeRequest->status;
    }

    public function toPush(object $notifiable): array
    {
        $approved = $this->changeRequest->status === 'approved';

        return [
            'title' => $approved ? 'Change approved' : 'Change rejected',
            'body' => $this->toDatabase($notifiable)['message'],
            'tag' => 'change-request-' . $this->changeRequest->id,
            'url' => '/settings',
            'kind' => $this->kind(),
            'channel' => Alerts::channelOf($this->kind()),
        ];
    }

    public function pushOptions(): array
    {
        return Alerts::optionsOf($this->kind());
    }

    public function toDatabase(object $notifiable): array
    {
        $type = $this->changeRequest->type;
        $approved = $this->changeRequest->status === 'approved';

        $extra = '';
        if ($approved && $type === 'mobile') {
            $extra = ' Verify the new number with the code in your notifications.';
        } elseif ($approved && $type === 'email') {
            $extra = ' A verification code was emailed to the new address — enter it in Settings → Login identity.';
        }

        return [
            'kind' => 'change_request_' . $this->changeRequest->status,
            'type' => $type,
            'message' => $approved
                ? "Your {$type} change was approved.{$extra}"
                : "Your {$type} change was rejected."
                    . ($this->changeRequest->review_note ? ' Reason: ' . $this->changeRequest->review_note : ''),
        ];
    }
}
