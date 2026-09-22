<?php

namespace App\Http\Middleware;

use App\Models\Conversation;
use App\Services\ChatLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A locked or hidden chat stays shut until its password has been given.
 *
 * On every route that reads or writes what is inside a conversation - its
 * messages, its files, its members, a call into it - rather than in each
 * controller separately, where the one that was forgotten would be the one
 * that leaked.
 *
 * 423, not 403: the person is allowed in, they have just not unlocked it
 * yet, and the app answers the two differently.
 */
class EnsureChatUnlocked
{
    public function __construct(private ChatLock $locks)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $conversation = $request->route('conversation');
        $me = $request->user();

        if ($conversation instanceof Conversation && $me
            && $this->locks->sealed($me, $conversation)
            && ! $this->locks->isOpen($me, $request->header(ChatLock::HEADER))) {
            return response()->json([
                'message' => 'This chat is locked. Enter your chat password to open it.',
                'locked' => true,
            ], 423);
        }

        return $next($request);
    }
}
