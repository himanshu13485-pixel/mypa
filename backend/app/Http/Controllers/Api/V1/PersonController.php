<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Somebody else's profile, as you are allowed to see it.
 *
 * A name in a chat header or a connections row was the end of the line: there
 * was nowhere to tap. Everything on this page already existed somewhere in
 * the app; what was missing was one screen that answers "who is this".
 *
 * What it shows narrows with the relationship. A stranger gets what a search
 * result already gives away - name, handle, picture. Somebody you are
 * connected to also gets the way to reach them, because you have both agreed
 * to that. The account's own state and anybody's private settings stay here.
 */
class PersonController extends Controller
{
    public function show(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();

        $person = User::with(['profile', 'settings', 'appId'])
            ->where('uuid', $uuid)->first();

        abort_unless($person && $person->status === 'active', 404, 'No such person.');

        /*
         * Blocking is mutual silence.
         *
         * Either direction hides the profile: somebody you blocked should not
         * be lookupable, and somebody who blocked you should not be told
         * anything by a screen you can still open.
         */
        abort_if($person->hasBlocked($me) || $me->hasBlocked($person), 404, 'No such person.');

        $connected = $person->id !== $me->id && Connection::where('status', 'accepted')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('requester_id', $me->id)->where('addressee_id', $person->id))
                ->orWhere(fn ($w) => $w->where('requester_id', $person->id)->where('addressee_id', $me->id)))
            ->exists();

        $itsMe = $person->id === $me->id;

        // A private account is not a 404 to somebody it is not private from.
        abort_unless(
            $itsMe || $connected
                || ($person->settings?->privacyValue('who_can_find_me') ?? 'everyone') === 'everyone',
            404,
            'No such person.',
        );

        $photoHidden = $person->settings?->privacyValue('profile_photo_visibility') === 'nobody'
            || (($person->settings?->privacyValue('profile_photo_visibility') ?? 'connections') === 'connections'
                && ! $connected && ! $itsMe);

        $pending = Connection::whereIn('status', ['pending'])
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('requester_id', $me->id)->where('addressee_id', $person->id))
                ->orWhere(fn ($w) => $w->where('requester_id', $person->id)->where('addressee_id', $me->id)))
            ->first();

        return response()->json(['data' => [
            'uuid' => $person->uuid,
            'name' => $person->name,
            'username' => $person->username,
            'app_id' => $person->appId?->app_id,

            // The line about what they are up to, which is the thing most
            // people open a profile for.
            'status' => $person->profile?->status_text,
            'bio' => $person->profile?->bio,
            'country' => $person->profile?->country,

            'avatar' => $photoHidden ? null : $person->profile?->avatar,
            'photo_path' => $photoHidden ? null : $person->profile?->photo_path,

            'presence' => $person->presenceFor($me),

            /*
             * Contact details are the part of a profile that is only ever
             * shared on purpose, so they follow the connection rather than
             * the lookup.
             */
            'email' => $connected || $itsMe ? $person->email : null,
            'mobile' => $connected || $itsMe ? $person->mobile : null,

            'is_me' => $itsMe,
            'is_connected' => $connected,
            'request_status' => $pending
                ? ($pending->requester_id === $me->id ? 'sent' : 'received')
                : null,
            'member_since' => $person->created_at?->toIso8601String(),

            /*
             * What the two of you already have between you.
             *
             * A profile that answers only "who is this" leaves the reader to
             * go and look up the rest one screen at a time. The groups you are
             * both in, the things one of you shared with the other, and the
             * messages either of you thought worth keeping are the answers
             * people actually open a profile for.
             */
            'shared' => $itsMe ? null : $this->shared($me, $person),
        ]]);
    }

    /** Everything the two of them have in common. */
    private function shared(User $me, User $them): array
    {
        $groups = \App\Models\Group::whereHas('members', fn ($q) => $q->where('users.id', $me->id))
            ->whereHas('members', fn ($q) => $q->where('users.id', $them->id))
            ->orderBy('name')
            ->limit(20)
            ->get(['uuid', 'name', 'type']);

        /*
         * Shared either way round.
         *
         * "Things we share" is not "things I gave you" — a note they shared
         * with me belongs on this screen exactly as much as one I shared with
         * them, and showing only my own half would make the relationship look
         * one-sided.
         */
        $notes = \App\Models\Note::where(fn ($q) => $q
            ->where(fn ($w) => $w->where('user_id', $me->id)
                ->whereHas('sharedWith', fn ($s) => $s->where('users.id', $them->id)))
            ->orWhere(fn ($w) => $w->where('user_id', $them->id)
                ->whereHas('sharedWith', fn ($s) => $s->where('users.id', $me->id))))
            ->latest('updated_at')->limit(10)->get(['uuid', 'title', 'user_id']);

        $files = \App\Models\File::where(fn ($q) => $q
            ->where(fn ($w) => $w->where('user_id', $me->id)
                ->whereHas('sharedWith', fn ($s) => $s->where('users.id', $them->id)))
            ->orWhere(fn ($w) => $w->where('user_id', $them->id)
                ->whereHas('sharedWith', fn ($s) => $s->where('users.id', $me->id))))
            ->latest('updated_at')->limit(10)->get(['uuid', 'name', 'user_id']);

        $projects = \App\Models\Project::where(fn ($q) => $q
            ->where(fn ($w) => $w->where('user_id', $me->id)
                ->whereHas('sharedWith', fn ($s) => $s->where('users.id', $them->id)))
            ->orWhere(fn ($w) => $w->where('user_id', $them->id)
                ->whereHas('sharedWith', fn ($s) => $s->where('users.id', $me->id))))
            ->latest('updated_at')->limit(10)->get(['uuid', 'name', 'user_id']);

        // The one-to-one thread, if the two of them have one.
        $conversation = \App\Models\Conversation::where('type', 'direct')
            ->whereHas('members', fn ($q) => $q->where('users.id', $me->id))
            ->whereHas('members', fn ($q) => $q->where('users.id', $them->id))
            ->first();

        $starred = [];
        $pinned = [];

        if ($conversation) {
            $with = ['user:id,uuid,name', 'attachments', 'reactions', 'stars'];

            $pinned = $conversation->messages()->whereNotNull('pinned_at')
                ->with($with)->orderByDesc('pinned_at')->limit(5)->get()
                ->map(fn ($m) => $m->serializeFor($me))->values()->all();

            // Mine alone: a star is private, and this screen is no place to
            // start leaking which of their messages the other side kept.
            $starred = $conversation->messages()
                ->whereHas('stars', fn ($q) => $q->where('user_id', $me->id))
                ->with($with)->latest('id')->limit(10)->get()
                ->map(fn ($m) => $m->serializeFor($me))->values()->all();
        }

        return [
            'conversation_uuid' => $conversation?->uuid,
            'groups' => $groups->map(fn ($g) => [
                'uuid' => $g->uuid, 'name' => $g->name, 'type' => $g->type,
            ])->values(),
            'notes' => $notes->map(fn ($n) => [
                'uuid' => $n->uuid, 'title' => $n->title, 'mine' => $n->user_id === $me->id,
            ])->values(),
            'files' => $files->map(fn ($f) => [
                'uuid' => $f->uuid, 'name' => $f->name, 'mine' => $f->user_id === $me->id,
            ])->values(),
            'projects' => $projects->map(fn ($p) => [
                'uuid' => $p->uuid, 'name' => $p->name, 'mine' => $p->user_id === $me->id,
            ])->values(),
            'pinned_messages' => $pinned,
            'starred_messages' => $starred,
        ];
    }
}
