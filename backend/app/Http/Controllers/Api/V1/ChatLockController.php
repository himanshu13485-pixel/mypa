<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Conversation;
use App\Models\MobileOtp;
use App\Services\ChatLock;
use App\Services\MobileOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The chat password: setting it, using it, forgetting it, and the chats it
 * keeps locked or out of sight.
 *
 * Every change to a lock asks for the password again - locking a chat,
 * unlocking it, hiding it, bringing it back. A lock that anybody holding
 * the phone could take off would only be decoration.
 */
class ChatLockController extends Controller
{
    public function __construct(private ChatLock $locks)
    {
    }

    /** Whether there is a password, and how much it is keeping. */
    public function show(Request $request): JsonResponse
    {
        $me = $request->user();

        return response()->json(['data' => [
            'has_password' => $this->locks->has($me),
            'set_at' => $me->chat_lock_set_at?->toIso8601String(),
            'locked_count' => DB::table('conversation_members')->where('user_id', $me->id)->whereNotNull('locked_at')->whereNull('hidden_at')->count(),
            // Said only as a number, and only to the owner, so the folder can
            // offer "remove password" honestly about what that will do.
            'hidden_count' => DB::table('conversation_members')->where('user_id', $me->id)->whereNotNull('hidden_at')->count(),
            'window_minutes' => ChatLock::WINDOW_MINUTES,
        ]]);
    }

    /** The first password, or a new one in place of the old. */
    public function store(Request $request): JsonResponse
    {
        $me = $request->user();

        $data = $request->validate([
            'current_password' => [$this->locks->has($me) ? 'required' : 'nullable', 'string'],
            'password' => ['required', 'string', 'min:4', 'max:32', 'confirmed'],
        ], [
            'password.min' => 'At least four characters - a PIN like 123456 is fine.',
        ]);

        if ($this->locks->has($me) && ! $this->locks->matches($me, $data['current_password'] ?? null)) {
            throw ValidationException::withMessages(['current_password' => ['That is not your current chat password.']]);
        }

        $this->locks->set($me, $data['password']);

        return response()->json([
            'message' => 'Chat password saved.',
            // Setting it proves knowing it, so the app is not made to ask twice.
            'data' => ['token' => $this->locks->open($me), 'window_minutes' => ChatLock::WINDOW_MINUTES],
        ]);
    }

    /**
     * No more password - and so no more locks or hiding places, since
     * nothing would be left that could open them. Everything becomes an
     * ordinary chat again.
     */
    public function destroy(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate(['password' => ['required', 'string']]);
        $this->guard($me, $data['password']);

        $this->locks->remove($me);

        return response()->json(['message' => 'Chat password removed. Locked and hidden chats are ordinary chats again.']);
    }

    /** The password, exchanged for a quarter of an hour of open chats. */
    public function unlock(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate(['password' => ['required', 'string']]);
        $this->guard($me, $data['password']);

        return response()->json(['data' => [
            'token' => $this->locks->open($me),
            'window_minutes' => ChatLock::WINDOW_MINUTES,
        ]]);
    }

    /**
     * Forgotten: a code to the account's own e-mail.
     *
     * The inbox is what the phone's finder does not have, so it is what
     * proves the owner.
     */
    public function forgot(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($this->locks->has($me), 422, 'There is no chat password to reset.');
        abort_if(blank($me->email), 422, 'This account has no e-mail address to send a code to.');

        MobileOtp::where('user_id', $me->id)->where('purpose', 'chat_lock_reset')
            ->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $otp = MobileOtp::create([
            'user_id' => $me->id,
            'mobile' => $me->email,
            'code' => (string) random_int(100000, 999999),
            'purpose' => 'chat_lock_reset',
            'expires_at' => now()->addMinutes((int) (AppSetting::get('otp_expiry_minutes') ?: 10)),
        ]);

        $me->notify(new \App\Notifications\ChatLockResetNotification($otp));

        return response()->json([
            'message' => 'A code has been sent to ' . $this->masked($me->email) . '.',
            'data' => ['sent_to' => $this->masked($me->email)],
        ]);
    }

    /** The code from the e-mail, and a new password. */
    public function reset(Request $request, MobileOtpService $otps): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:8'],
            'password' => ['required', 'string', 'min:4', 'max:32', 'confirmed'],
        ]);

        $otps->verify($me, $data['code'], 'chat_lock_reset');

        // The locks stay where they were: the point was to get back in.
        $this->locks->set($me, $data['password']);

        return response()->json([
            'message' => 'Chat password reset.',
            'data' => ['token' => $this->locks->open($me), 'window_minutes' => ChatLock::WINDOW_MINUTES],
        ]);
    }

    // ---- One chat at a time ------------------------------------------------

    public function lock(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->mark($request, $conversation, ['locked_at' => now()], 'Chat locked.');
    }

    public function unlockChat(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->mark($request, $conversation, ['locked_at' => null], 'Chat unlocked.');
    }

    /** Out of the list, into the folder only the password opens. */
    public function hide(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->mark($request, $conversation, ['hidden_at' => now()], 'Chat hidden. Type #your password# in search to find it.');
    }

    /** Back into the list as an ordinary chat - not locked, not hidden. */
    public function unhide(Request $request, Conversation $conversation): JsonResponse
    {
        return $this->mark($request, $conversation, ['hidden_at' => null, 'locked_at' => null], 'Chat moved back to your chats.');
    }

    private function mark(Request $request, Conversation $conversation, array $changes, string $said): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->hasMember($me), 403);
        abort_unless($this->locks->has($me), 422, 'Set a chat password first.');

        $data = $request->validate(['password' => ['required', 'string']]);
        $this->guard($me, $data['password']);

        DB::table('conversation_members')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $me->id)
            ->update($changes);

        return response()->json([
            'message' => $said,
            // The password was just given, so the chat opens without asking again.
            'data' => ['token' => $this->locks->open($me), 'window_minutes' => ChatLock::WINDOW_MINUTES],
        ]);
    }

    /** Wrong is refused with the same words whichever action it was. */
    private function guard($me, string $password): void
    {
        if (! $this->locks->has($me)) {
            throw ValidationException::withMessages(['password' => ['There is no chat password set yet.']]);
        }
        if (! $this->locks->matches($me, $password)) {
            throw ValidationException::withMessages(['password' => ['That is not your chat password.']]);
        }
    }

    private function masked(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 2) . str_repeat('•', max(1, mb_strlen($name) - 2)) . '@' . $domain;
    }
}
