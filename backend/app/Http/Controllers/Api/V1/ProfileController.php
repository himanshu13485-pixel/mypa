<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function me(Request $request): UserResource
    {
        return new UserResource(
            $request->user()->load(['profile', 'settings', 'appId', 'roles'])
        );
    }

    public function updateProfile(Request $request): UserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:32'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:32'],
            // One of the illustrations the client draws, or null for initials.
            'avatar' => ['sometimes', 'nullable', 'string', 'regex:/^[fmn][1-9]$/'],
            'country' => ['sometimes', 'nullable', 'string', 'max:64'],
            'timezone' => ['sometimes', 'timezone:all_with_bc'],
            'language' => ['sometimes', 'string', 'max:8'],
            'account_type' => ['sometimes', 'in:personal,business'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],
            /*
             * What you are up to, as against who you are.
             *
             * Short on purpose: a status people scroll is a bio, and there is
             * already a bio. Stored as status_text because users.status is
             * the account's own state and two columns of that name a row
             * apart is a bug waiting for a careless join.
             */
            'status' => ['sometimes', 'nullable', 'string', 'max:140'],
        ]);

        if (array_key_exists('status', $data)) {
            $data['status_text'] = $data['status'] === null ? null : trim($data['status']);
            unset($data['status']);
        }

        $user = $request->user();

        $user->update(array_intersect_key($data, array_flip(['name', 'mobile'])));

        $profileFields = array_intersect_key($data, array_flip([
            'date_of_birth', 'gender', 'avatar', 'country', 'timezone', 'language', 'account_type', 'bio',
            'status_text',
        ]));

        if ($profileFields) {
            $user->profile()->updateOrCreate([], $profileFields);
        }

        return new UserResource($user->fresh()->load(['profile', 'settings', 'appId', 'roles']));
    }

    public function updateSettings(Request $request): UserResource
    {
        $data = $request->validate([
            'theme' => ['sometimes', 'in:light,dark,system'],
            'compact_mode' => ['sometimes', 'boolean'],
            'default_task_view' => ['sometimes', 'in:list,table,calendar,kanban,timeline'],
            'dashboard_layout' => ['sometimes', 'nullable', 'array'],
            'notification_preferences' => ['sometimes', 'nullable', 'array'],
            'privacy' => ['sometimes', 'array'],
            'privacy.*' => ['in:everyone,connections,nobody'],
        ]);

        $user = $request->user();
        $settings = $user->settings()->firstOrCreate([]);

        if (isset($data['privacy'])) {
            $allowed = array_keys(\App\Models\UserSetting::DEFAULT_PRIVACY);
            $data['privacy'] = array_merge(
                $settings->privacy ?? [],
                array_intersect_key($data['privacy'], array_flip($allowed))
            );
        }

        $settings->update($data);

        return new UserResource($user->fresh()->load(['profile', 'settings', 'appId', 'roles']));
    }

    public function uploadPhoto(Request $request): UserResource
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $profile = $user->profile()->firstOrCreate([]);

        if ($profile->photo_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($profile->photo_path);
        }

        $path = $request->file('photo')->store('profile-photos', 'public');
        $profile->update(['photo_path' => $path]);

        return new UserResource($user->fresh()->load(['profile', 'settings', 'appId', 'roles']));
    }
}
