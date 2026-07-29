<?php

namespace App\Notifications;

use App\Models\ChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChangeRequestDecidedNotification extends Notification
{
    use Queueable;

    public function __construct(public ChangeRequest $changeRequest)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $type = $this->changeRequest->type;
        $approved = $this->changeRequest->status === 'approved';

        $extra = '';
        if ($approved && $type === 'mobile') {
            $extra = ' Verify the new number with the code in your notifications.';
        } elseif ($approved && $type === 'email') {
            $extra = ' A verification link was sent to the new address.';
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
