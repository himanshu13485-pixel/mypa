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
            ] : null,
            'responded_at' => $this->responded_at,
            'created_at' => $this->created_at,
        ];
    }
}
