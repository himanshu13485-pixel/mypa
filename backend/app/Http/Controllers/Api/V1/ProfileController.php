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

    /**
     * Every menu, and what each is set to.
     *
     * The list comes from the server rather than the screen so a menu added
     * later appears in Settings without a release of the app, and so the
     * two can never disagree about what a topic is called.
     */
    public function notificationTopics(Request $request): \Illuminate\Http\JsonResponse
    {
        $settings = $request->user()->settings()->firstOrCreate([]);

        return response()->json([
            'data' => [
                // The CRM's menus are the company Admin's to decide, so a
                // person's own list is only the menus that are theirs.
                'topics' => \App\Support\NotificationTopics::personal(),
                'values' => $settings->topicPreferences(),
                // The two app-wide switches still sit above all of them.
                'email' => $settings->notificationValue('email'),
                'push' => $settings->notificationValue('push'),
            ],
        ]);
    }

    /**
     * Switch one menu, or several, on or off.
     *
     * A patch rather than a whole document: two tabs open on the settings
     * screen should not be able to undo each other's answer to a different
     * question.
     */
    public function updateNotificationTopics(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'topics' => ['required', 'array'],
            'topics.*.email' => ['sometimes', 'boolean'],
            'topics.*.app' => ['sometimes', 'boolean'],
        ]);

        $settings = $request->user()->settings()->firstOrCreate([]);
        $preferences = $settings->notification_preferences ?? [];
        $saved = (array) ($preferences['topics'] ?? []);

        foreach ($data['topics'] as $key => $wanted) {
            // A CRM menu is not a person's to switch - the company decides.
            if (! \App\Support\NotificationTopics::exists((string) $key)
                || \App\Support\NotificationTopics::isCrm((string) $key)) {
                continue;
            }

            $saved[$key] = array_merge(
                ['email' => true, 'app' => true],
                (array) ($saved[$key] ?? []),
                array_intersect_key($wanted, array_flip(['email', 'app'])),
            );
        }

        $preferences['topics'] = $saved;
        $settings->update(['notification_preferences' => $preferences]);

        return response()->json([
            'message' => 'Saved.',
            'data' => ['values' => $settings->fresh()->topicPreferences()],
        ]);
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
