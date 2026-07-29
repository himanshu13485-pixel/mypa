<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'username' => $this->username,
            'country_code' => $this->when($request->user()?->id === $this->id, $this->country_code),
            'mobile_verified' => $this->when(
                $request->user()?->id === $this->id,
                $this->mobile_verified_at !== null
            ),
            'email' => $this->when(
                $request->user()?->id === $this->id || $request->user()?->isAdmin(),
                $this->email
            ),
            'mobile' => $this->when($request->user()?->id === $this->id, $this->mobile),
            'status' => $this->when($request->user()?->isAdmin(), $this->status),
            'email_verified' => $this->email_verified_at !== null,
            'app_id' => $this->whenLoaded('appId', fn () => $this->appId?->app_id),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')),
            'profile' => $this->whenLoaded('profile', fn () => [
                'photo_path' => $this->profile?->photo_path,
                'date_of_birth' => $this->profile?->date_of_birth?->toDateString(),
                'gender' => $this->profile?->gender,
                'country' => $this->profile?->country,
                'timezone' => $this->profile?->timezone,
                'language' => $this->profile?->language,
                'account_type' => $this->profile?->account_type,
                'bio' => $this->profile?->bio,
            ]),
            'settings' => $this->when(
                $request->user()?->id === $this->id && $this->relationLoaded('settings'),
                fn () => [
                    'theme' => $this->settings?->theme,
                    'compact_mode' => $this->settings?->compact_mode,
                    'default_task_view' => $this->settings?->default_task_view,
                    'dashboard_layout' => $this->settings?->dashboard_layout,
                    'notification_preferences' => $this->settings?->notification_preferences,
                    'privacy' => array_merge(
                        \App\Models\UserSetting::DEFAULT_PRIVACY,
                        $this->settings?->privacy ?? []
                    ),
                ]
            ),
            'has_password' => $this->when(
                $request->user()?->id === $this->id,
                $this->password !== null
            ),
            'must_change_password' => $this->when(
                $request->user()?->id === $this->id,
                (bool) $this->force_password_change
            ),
            'last_login_at' => $this->when($request->user()?->id === $this->id, $this->last_login_at),
            'created_at' => $this->created_at,
        ];
    }
}
