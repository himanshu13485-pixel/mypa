<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Holiday;
use App\Models\Crm\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Celebrations: on each festival the Admin picked (from the HR Policy's own
 * holiday calendar) the CRM turns festive for EVERYONE — its own colour,
 * its own song — and the wishes wall opens so people wish each other from
 * the front-end, history kept per occasion. Birthdays ride the same wall.
 */
class CelebrationController extends Controller
{
    /** The festival setup: every holiday on the calendar, with its vibe. */
    public function settings(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        $holidays = Holiday::where('organization_id', $org->id)
            ->orderBy('holiday_date')
            ->get(['id', 'name', 'holiday_date']);
        $config = (array) data_get($org->settings, 'festivals', []);

        return response()->json(['data' => [
            'holidays' => $holidays->map(fn ($h) => [
                'name' => $h->name,
                'date' => $h->holiday_date->toDateString(),
                'config' => ((array) ($config[$h->holiday_date->toDateString()] ?? [])) + [
                    'enabled' => false, 'color' => '#e11d48', 'song_url' => null,
                ],
            ]),
        ]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $request->validate([
            'festivals' => ['present', 'array'],
            'festivals.*.enabled' => ['nullable', 'boolean'],
            'festivals.*.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'festivals.*.song_url' => ['nullable', 'string', 'max:1024'],
        ]);

        $settings = $org->settings ?? [];
        $settings['festivals'] = $data['festivals'];
        $org->update(['settings' => $settings]);

        return response()->json(['message' => 'Festival celebrations saved.']);
    }

    /** A song file for a birthday or festival — served from the public disk. */
    public function uploadSong(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $request->validate(['file' => ['required', 'file', 'mimes:mp3,wav,ogg,m4a', 'max:10240']]);

        $path = $request->file('file')->store('crm-celebrations/' . $org->id, 'public');

        return response()->json(['message' => 'Song uploaded.', 'data' => ['url' => '/storage/' . $path]]);
    }

    /** What today celebrates — the shell asks once per load, for everyone. */
    public function today(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $today = now()->toDateString();

        $holiday = Holiday::where('organization_id', $org->id)
            ->whereDate('holiday_date', $today)
            ->first();
        $config = $holiday
            ? (array) data_get($org->settings, 'festivals.' . $today, [])
            : [];

        return response()->json(['data' => [
            'festival' => $holiday && ($config['enabled'] ?? false) ? [
                'name' => $holiday->name,
                'color' => $config['color'] ?? '#e11d48',
                'song_url' => $config['song_url'] ?? null,
            ] : null,
            // Whose day it is — the wall greets them by name.
            'birthdays' => Member::visible()->with('user:id,name')
                ->where('organization_id', $org->id)->where('status', 'active')
                ->whereNotNull('dob')->get()
                ->filter(fn ($m) => $m->dob->isBirthday())
                ->map(fn ($m) => $m->user?->name)->filter()->values(),
        ]]);
    }

    /** The wishes wall for one occasion — the history stays. */
    public function wishes(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $occasion = (string) $request->query('occasion', '');

        $rows = DB::table('crm_wishes')
            ->join('crm_members', 'crm_members.id', '=', 'crm_wishes.member_id')
            ->join('users', 'users.id', '=', 'crm_members.user_id')
            ->where('crm_wishes.organization_id', $org->id)
            ->when($occasion !== '', fn ($q) => $q->where('occasion', $occasion))
            ->orderByDesc('crm_wishes.id')
            ->limit(100)
            ->get(['crm_wishes.occasion', 'crm_wishes.message', 'crm_wishes.created_at', 'users.name']);

        return response()->json(['data' => $rows]);
    }

    public function sendWish(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $request->validate([
            'occasion' => ['required', 'string', 'max:128'],
            'message' => ['required', 'string', 'max:512'],
        ]);

        DB::table('crm_wishes')->insert($data + [
            'organization_id' => $org->id,
            'member_id' => $me->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Wish sent to the wall. 🎉'], 201);
    }
}
