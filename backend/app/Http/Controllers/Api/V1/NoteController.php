<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppId;
use App\Models\Group;
use App\Models\Note;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class NoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Note::visibleTo($request->user())
            ->with(['group:id,uuid,name', 'user:id,uuid,name,username', 'sharedWith:id,uuid,name,username']);

        if ($q = $request->query('q')) {
            $query->where(fn ($w) => $w->where('title', 'like', "%{$q}%")
                ->orWhere(fn ($b) => $b->whereNull('password_hash')->where('body', 'like', "%{$q}%")));
        }
        if ($groupUuid = $request->query('group')) {
            $query->whereHas('group', fn ($g) => $g->where('uuid', $groupUuid));
        }

        $notes = $query->orderByDesc('is_pinned')->latest('updated_at')->paginate(30);

        $notes->getCollection()->transform(fn ($note) => $this->serialize($note, $request, withContent: false));

        return response()->json($notes);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        if (! empty($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
        }
        unset($data['password']);

        $note = $request->user()->notes()->create($data);

        return response()->json([
            'message' => 'Note created.',
            'data' => $this->serialize($this->withPeople($note->fresh()), $request),
        ], 201);
    }

    public function show(Request $request, Note $note): JsonResponse
    {
        $this->authorizeView($request, $note);

        if ($note->isLocked() && ! $this->passwordOk($request, $note)) {
            return response()->json([
                'message' => 'This note is password protected.',
                'data' => $this->serialize($this->withPeople($note), $request, withContent: false),
            ], 423);
        }

        return response()->json(['data' => $this->serialize($this->withPeople($note), $request)]);
    }

    public function update(Request $request, Note $note): JsonResponse
    {
        $this->authorizeView($request, $note);
        abort_unless($note->canEdit($request->user()), 403);

        if ($note->isLocked() && ! $this->passwordOk($request, $note)) {
            abort(423, 'This note is password protected.');
        }

        $data = $this->validated($request, $note);

        // Version snapshot before applying changes.
        $note->versions()->create([
            'user_id' => $request->user()->id,
            'title' => $note->title,
            'body' => $note->body,
            'checklist' => $note->checklist,
        ]);
        // Keep the last 20 versions only.
        $note->versions()->orderByDesc('id')->skip(20)->take(100)->get()
            ->each(fn ($v) => $v->delete());

        if (array_key_exists('password', $data)) {
            // Only the owner can change protection.
            if ($note->user_id === $request->user()->id) {
                $data['password_hash'] = $data['password'] ? Hash::make($data['password']) : null;
            }
            unset($data['password']);
        }

        $note->update($data);

        return response()->json([
            'message' => 'Note updated.',
            'data' => $this->serialize($this->withPeople($note->fresh()), $request),
        ]);
    }

    public function destroy(Request $request, Note $note): JsonResponse
    {
        abort_unless($note->user_id === $request->user()->id, 403);

        $note->delete();

        return response()->json(['message' => 'Note deleted.']);
    }

    public function share(Request $request, Note $note): JsonResponse
    {
        abort_unless($note->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:32'],
            'permission' => ['required', 'in:view,edit'],
        ]);

        $target = app(\App\Services\AppIdService::class)->findVisibleUser($data['app_id'], $request->user());

        if (! $target || $target->id === $request->user()->id) {
            return response()->json(['message' => 'No user found for that username, email, or App ID.'], 404);
        }

        $note->sharedWith()->syncWithoutDetaching([
            $target->id => ['permission' => $data['permission']],
        ]);

        $target->notify(new \App\Notifications\SocialNotification(
            'note_shared',
            "{$request->user()->name} shared a note with you: “{$note->title}”."
                . ($note->isLocked() ? ' Ask them for the password to open it.' : ''),
            ['note_uuid' => $note->uuid],
            '/notes',
        ));

        return response()->json([
            'message' => 'Note shared with ' . $target->name . '.',
            'data' => $this->serialize($this->withPeople($note), $request, withContent: false),
        ]);
    }

    /**
     * Take a note back off somebody's desk. Only the owner may do this, and
     * the note itself is untouched - just that one row in the pivot.
     */
    public function unshare(Request $request, Note $note, string $user): JsonResponse
    {
        abort_unless($note->user_id === $request->user()->id, 403);

        $target = $note->sharedWith()->where('users.uuid', $user)->first();

        if (! $target) {
            return response()->json(['message' => 'This note is not shared with that person.'], 404);
        }

        $note->sharedWith()->detach($target->id);

        return response()->json([
            'message' => 'Sharing stopped for ' . $target->name . '.',
            'data' => $this->serialize($this->withPeople($note), $request, withContent: false),
        ]);
    }

    public function versions(Request $request, Note $note): JsonResponse
    {
        $this->authorizeView($request, $note);

        if ($note->isLocked() && ! $this->passwordOk($request, $note)) {
            abort(423, 'This note is password protected.');
        }

        return response()->json([
            'data' => $note->versions()->with('user:id,uuid,name')->limit(20)->get(),
        ]);
    }

    // --- Helpers ------------------------------------------------------------

    protected function validated(Request $request, ?Note $note = null): array
    {
        $data = $request->validate([
            'title' => [$note ? 'sometimes' : 'required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:200000'],
            'type' => ['sometimes', 'in:text,checklist'],
            'checklist' => ['nullable', 'array'],
            'checklist.*.text' => ['required', 'string', 'max:500'],
            'checklist.*.done' => ['sometimes', 'boolean'],
            'color' => ['nullable', 'string', 'max:16'],
            'is_pinned' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:100'],
            'group_uuid' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if (array_key_exists('group_uuid', $data)) {
            $group = $data['group_uuid']
                ? Group::withMember($request->user())->where('uuid', $data['group_uuid'])->firstOrFail()
                : null;
            $data['group_id'] = $group?->id;
            unset($data['group_uuid']);
        }

        return $data;
    }

    protected function passwordOk(Request $request, Note $note): bool
    {
        $password = $request->header('X-Note-Password') ?? $request->input('note_password');

        return $password !== null && Hash::check($password, $note->password_hash);
    }

    protected function serialize(Note $note, Request $request, bool $withContent = true): array
    {
        $locked = $note->isLocked();

        return [
            'uuid' => $note->uuid,
            'title' => $note->title,
            'type' => $note->type,
            'color' => $note->color,
            'is_pinned' => $note->is_pinned,
            'is_locked' => $locked,
            'is_own' => $note->user_id === $request->user()->id,
            'group' => $note->group ? ['uuid' => $note->group->uuid, 'name' => $note->group->name] : null,
            // Who else is on this note. Everybody who can see the note can see
            // the list - otherwise there is no way to tell where it has gone.
            'owner' => $note->user ? [
                'uuid' => $note->user->uuid,
                'name' => $note->user->name,
                'username' => $note->user->username,
            ] : null,
            'shared_with' => $note->sharedWith->map(fn ($u) => [
                'uuid' => $u->uuid,
                'name' => $u->name,
                'username' => $u->username,
                'permission' => $u->pivot->permission,
            ])->values()->all(),
            // Locked notes never leak content in list/blocked responses.
            'body' => $withContent && ! ($locked && ! $this->passwordOk($request, $note)) ? $note->body : null,
            'checklist' => $withContent && ! ($locked && ! $this->passwordOk($request, $note)) ? $note->checklist : null,
            'preview' => $locked ? null : str($note->body ?? '')->stripTags()->limit(120)->toString(),
            'updated_at' => $note->updated_at,
            'created_at' => $note->created_at,
        ];
    }

    /** Reload the people on a note so serialize() never guesses. */
    protected function withPeople(Note $note): Note
    {
        return $note->load(['user:id,uuid,name,username', 'sharedWith:id,uuid,name,username']);
    }

    protected function authorizeView(Request $request, Note $note): void
    {
        $user = $request->user();

        $visible = $note->user_id === $user->id
            || $note->sharedWith()->where('users.id', $user->id)->exists()
            || ($note->group_id && $note->group->members()->where('users.id', $user->id)->exists());

        abort_unless($visible, 403);
    }
}
