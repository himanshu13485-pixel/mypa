<?php

namespace App\Http\Middleware;

use App\Models\Crm\Member;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Company-level authority, not a grantable right.
 *
 * Some actions belong to whoever runs the company rather than to whoever
 * was handed a module right: registering staff, editing their profile,
 * salary records, employment documents and KPI assignment. A Team Head with
 * `employees.edit` can read their subtree, but must never be able to change
 * someone's pay or delete their documents — so these routes ask for the
 * role, which only a CRM admin or subadmin (and the Super Admin's oversight
 * membership, which is an admin) can hold.
 *
 * Runs after `crm.member`, which has already resolved the membership.
 */
class EnsureCrmManager
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Member|null $member */
        $member = $request->attributes->get('crm_member');

        if (! $member || ! in_array($member->crm_role, ['admin', 'subadmin'], true)) {
            abort(403, 'Only a CRM admin or subadmin can do this.');
        }

        return $next($request);
    }
}
