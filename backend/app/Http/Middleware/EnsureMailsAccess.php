<?php

namespace App\Http\Middleware;

use App\Models\Crm\Member;
use App\Services\Mail\MailAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mails is open only where both doors above have opened: the platform has
 * switched it on for this company, and the company has given this person
 * the Mails right (its Admin always has it). Runs after crm.member, which
 * has already found who they are.
 */
class EnsureMailsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $member = $request->attributes->get('crm_member');

        if (! $member instanceof Member || ! MailAccess::orgEnabled($member->organization)) {
            return response()->json(['message' => 'Mails is not switched on for this company.'], 403);
        }
        // The right itself, asked here where the rights audit can see it.
        if (! $member->can('mails')) {
            return response()->json(['message' => 'Your Admin has not given you access to Mails.'], 403);
        }

        return $next($request);
    }
}
