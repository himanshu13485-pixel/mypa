<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Services\GroupLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The group's own password: set by whoever runs the group, given by
 * everybody in it.
 *
 * Setting, changing and removing it belong to the owner and the admins.
 * Opening the chat with it belongs to every member, admins included - an
 * admin's phone is as easy to pick up as anybody's.
 */
class GroupLockController extends Controller
{
    public function __construct(private GroupLock $locks)
    {
    }

    /** What this person may know about the lock: that it exists, and who set it. */
    public function show(Request $request, Group $group): JsonResponse
    {
        $me = $request->user();
        abort_unless($group->members()->where('users.id', $me->id)->exists(), 403, 'You are not in this group.');

        return response()->json(['data' => [
            'has_password' => $this->locks->has($group),
            'set_at' => $group->chat_password_set_at?->toIso8601String(),
            'set_by' => $group->chatPasswordSetBy?->name,
            'i_manage' => $group->canManage($me),
            'window_minutes' => GroupLock::WINDOW_MINUTES,
        ]]);
    }

    /** The first password, or a new one in place of the old. Admins only. */
    public function store(Request $request, Group $group): JsonResponse
    {
        $me = $request->user();
        abort_unless($group->canManage($me), 403, 'Only the group\'s owner or an admin can set its password.');

        $data = $request->validate([
            // Changing it needs the old one, the same as any password worth
            // the name - otherwise an admin's open session is the password.
            'current_password' => [$this->locks->has($group) ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string', 'min:4', 'max:32', 'confirmed'],
        ], [
            'password.min' => 'At least four characters - a PIN like 123456 is fine.',
        ]);

        if ($this->locks->has($group) && ! $this->locks->matches($group, $data['current_password'] ?? null)) {
            throw ValidationException::withMessages(['current_password' => ['That is not the group\'s current password.']]);
        }

        $this->locks->set($group, $data['password'], $me);

        return response()->json([
            'message' => 'The group is locked. Everyone in it, you included, will be asked for this password.',
            'data' => ['unlock_token' => $this->locks->open($me, $group)],
        ]);
    }

    /** Take the password off the group. Admins only, and the old one is asked for. */
    public function destroy(Request $request, Group $group): JsonResponse
    {
        $me = $request->user();
        abort_unless($group->canManage($me), 403, 'Only the group\'s owner or an admin can remove its password.');
        abort_unless($this->locks->has($group), 422, 'This group has no password.');

        $data = $request->validate(['password' => ['required', 'string']]);
        if (! $this->locks->matches($group, $data['password'])) {
            throw ValidationException::withMessages(['password' => ['That is not the group\'s password.']]);
        }

        $this->locks->remove($group);

        return response()->json(['message' => 'The password is off. The group opens for everyone in it again.']);
    }

    /** Give the password and get in - for a quarter of an hour. */
    public function open(Request $request, Group $group): JsonResponse
    {
        $me = $request->user();
        abort_unless($group->members()->where('users.id', $me->id)->exists(), 403, 'You are not in this group.');
        abort_unless($this->locks->has($group), 422, 'This group has no password.');

        $data = $request->validate(['password' => ['required', 'string']]);
        if (! $this->locks->matches($group, $data['password'])) {
            throw ValidationException::withMessages(['password' => ['That is not the group\'s password.']]);
        }

        return response()->json(['data' => [
            'unlock_token' => $this->locks->open($me, $group),
            'window_minutes' => GroupLock::WINDOW_MINUTES,
        ]]);
    }
}
