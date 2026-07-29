<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Personal channel: incoming calls, notifications.
Broadcast::channel('user.{uuid}', function (User $user, string $uuid) {
    return $user->uuid === $uuid;
});

// Conversation channel: messages, typing, call signalling.
Broadcast::channel('conversation.{uuid}', function (User $user, string $uuid) {
    $conversation = Conversation::where('uuid', $uuid)->first();

    return $conversation?->hasMember($user)
        ? ['uuid' => $user->uuid, 'name' => $user->name]
        : false;
});
