<?php

namespace App\Http\Middleware;

use App\Models\Crm\Member;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door into the CRM addon. A route behind this middleware is only
 * reachable by an active member of an active organization; the resolved
 * member (and their org) ride along on the request so controllers never
 * re-derive them.
 *
 * Usage: ->middleware('crm.member')          — any active member
 *        ->middleware('crm.member:employees,edit') — needs a module right
 */
class EnsureCrmMember
{
    public function handle(Request $request, Closure $next, ?string $module = null, string $ability = 'view'): Response
    {
        $query = Member::with('organization')
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->whereHas('organization', fn ($q) => $q->where('status', 'active'))
            // Hat off → the real membership, never a leftover oversight one.
            ->orderBy('is_oversight');

        /*
         * A user with memberships in several organizations (the Super Admin
         * who entered a company, typically) says which hat they are wearing
         * via this header; without it the first membership wins.
         *
         * The header carries the slug the browser is showing — /crm/bhavya-steel
         * asks for bhavya-steel — so the address bar and the answer cannot
         * disagree. A uuid is still accepted, because a session that was open
         * when slugs shipped is still sending one.
         */
        if ($org = $request->header('X-Crm-Org')) {
            $query->whereHas('organization', fn ($q) => $q->keyed($org));
        }

        $member = $query->first();

        if (! $member) {
            abort(403, $org
                ? 'You are not a member of that organization.'
                : 'CRM access has not been enabled for your account.');
        }

        if ($module && ! $member->can($module, $ability)) {
            abort(403, 'You do not have ' . $ability . ' rights for ' . $module . '.');
        }

        $request->attributes->set('crm_member', $member);
        $request->attributes->set('crm_org', $member->organization);

        return $next($request);
    }
}
