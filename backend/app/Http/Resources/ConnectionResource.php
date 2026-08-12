<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $me = $request->user();
        $other = $this->requester_id === $me?->id ? $this->addressee : $this->requester;

        return [
            'uuid' => $this->uuid,
            'status' => $this->status,
            'message' => $this->message,
            'direction' => $this->requester_id === $me?->id ? 'sent' : 'received',
            'user' => $other ? [
                'uuid' => $other->uuid,
                'name' => $other->name,
                'username' => $other->username,
                'app_id' => $other->appId?->app_id,
                'photo_path' => $other->profile?->photo_path,
                'avatar' => $other->profile?->avatar,
                /*
                 * Online, if they allow it to be seen.
                 *
                 * A privacy setting that nothing consults is not a setting, so
                 * this asks before answering: 'nobody' means the dot never
                 * appears, and the default 'connections' is satisfied here by
                 * definition — this list only ever contains people the viewer
                 * is connected to.
                 *
                 * The flag is computed, never the timestamp: sending
                 * last_active_at would let anyone build a log of exactly when
                 * somebody opens the app, which is a great deal more than
                 * "there is a green dot beside their name".
                 */
                'is_online' => $other->isOnlineFor($me),
            ] : null,
            'responded_at' => $this->responded_at,
            'created_at' => $this->created_at,
        ];
    }
}
