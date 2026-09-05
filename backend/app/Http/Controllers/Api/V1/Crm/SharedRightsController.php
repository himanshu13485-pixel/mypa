<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Crm\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * One set of rights, given to everybody.
 *
 * Most companies do not want twenty different answers to "what may an
 * employee see". They want one, worked out once on somebody's screen, and
 * then held to. Ticking it back in person by person is how it stops being
 * one answer.
 *
 * So this does the two things that keeps true: hands the set on the screen to
 * whoever should have it — everybody, or the two people who have just changed
 * desks — and remembers it as where a new hire starts.
 *
 * Who it reaches is not a new rule. It is the same question the employee form
 * asks one person at a time — Member::maySetRightsOn — asked of everybody at
 * once. An Admin reaches every employee and subadmin; a Subadmin named for
 * rights reaches employees and no one else, which is exactly as far as they
 * could already have reached one screen at a time. Admins are never written
 * to: they hold everything by the job, and a rights array on an Admin would
 * be a lie about what they can do.
 */
class SharedRightsController extends Controller
{
    /** Who a copy would reach, and where new hires start today. */
    public function show(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $me = $request->attributes->get('crm_member');

        abort_if(! $me->maySetRightsAtAll(), 403, 'Setting rights is not yours to do.');

        $targets = $this->recipients($request);

        return response()->json(['data' => [
            'count' => $targets->count(),
            // Named apart, because "and 3 subadmins" changes how carefully
            // somebody reads the rest of the sentence.
            'employees' => $targets->where('crm_role', 'employee')->count(),
            'subadmins' => $targets->where('crm_role', 'subadmin')->count(),
            /*
             * Named, not just counted, so the screen can offer them one at a
             * time. Copying to everybody is the common case and not the only
             * one — somebody who has moved desks needs one colleague's rights
             * changed, and doing that by hand for one person is fine right up
             * until it is three.
             */
            'members' => $targets->map(fn (Member $m) => [
                'uuid' => $m->uuid,
                'name' => $m->user?->name,
                'crm_role' => $m->crm_role,
                'employee_code' => $m->employee_code,
            ])->values(),
            'may_set_default' => $me->crm_role === 'admin',
            'default_rights' => (object) $org->defaultMemberRights(),
            'default_capabilities' => $org->defaultMemberCapabilities(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $me = $request->attributes->get('crm_member');

        abort_if(! $me->maySetRightsAtAll(), 403, 'Setting rights is not yours to do.');

        $data = $request->validate([
            'rights' => ['present', 'array'],
            'rights.*' => ['array'],
            'rights.*.*' => [Rule::in(Member::ABILITIES)],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => [Rule::in(array_keys(Member::CAPABILITIES))],
            /*
             * Who gets them: everybody this caller may reach, the people
             * named below, or nobody — the last being somebody who only
             * wants to change where new hires start.
             */
            'apply_to' => ['nullable', Rule::in(['all', 'chosen', 'nobody'])],
            'member_uuids' => ['nullable', 'array'],
            'member_uuids.*' => ['string'],
            'set_as_default' => ['boolean'],
        ]);

        $applyTo = $data['apply_to'] ?? 'nobody';

        abort_if($applyTo === 'chosen' && empty($data['member_uuids']), 422,
            'Pick at least one person, or choose everybody.');

        /*
         * Module names are checked, which the one-person form does not do —
         * it takes any key and ignores what it does not recognise. A write
         * that lands on everybody should not be the one that quietly stores
         * a typo nobody sees until somebody cannot open a screen.
         */
        foreach (array_keys($data['rights']) as $module) {
            abort_if(! in_array($module, Member::moduleSlugs(), true), 422,
                'There is no module called ' . $module . '.');
        }

        // Drop the modules nothing was ticked on, so a stored set is a list of
        // what somebody has rather than a list of everything that exists.
        $rights = collect($data['rights'])->map(fn ($abilities) => array_values(array_unique($abilities)))
            ->filter(fn ($abilities) => $abilities !== [])
            ->all();
        $capabilities = array_values(array_unique($data['capabilities'] ?? []));

        $applied = 0;

        if ($applyTo !== 'nobody') {
            $targets = $this->recipients($request);

            if ($applyTo === 'chosen') {
                /*
                 * Filtered from the same list rather than looked up by uuid.
                 * A uuid that is not in it is somebody this caller may not
                 * set rights on, and quietly dropping it is the right answer
                 * — the list is what the screen offered, so anything else
                 * arrived by hand.
                 */
                $wanted = array_flip($data['member_uuids']);
                $targets = $targets->filter(fn (Member $m) => isset($wanted[$m->uuid]))->values();

                abort_if($targets->isEmpty(), 422,
                    'None of those people are yours to set rights on.');
            }

            DB::transaction(function () use ($targets, $rights, $capabilities, &$applied) {
                foreach ($targets as $member) {
                    $member->update(['rights' => $rights, 'capabilities' => $capabilities]);
                    $applied++;
                }
            });

            /*
             * Written down. This is one click that changes what a whole
             * company's staff may do, and "who widened everybody, and when"
             * should not be a thing anybody has to reconstruct from what the
             * rows happen to say now.
             */
            AuditLog::record($request->user(), 'crm.rights.applied_to_all', $org, [
                'members' => $applied,
                'to' => $applyTo,
                'modules' => array_keys($rights),
                'capabilities' => $capabilities,
            ]);
        }

        if ($data['set_as_default'] ?? false) {
            // A standing rule about everybody hired from now on, which is
            // company authority — a named Subadmin sets rights, not policy.
            abort_if($me->crm_role !== 'admin', 403,
                'Where new employees start is the Company Admin\'s to set.');

            $settings = $org->settings ?? [];
            $settings['employees']['default_rights'] = $rights;
            $settings['employees']['default_capabilities'] = $capabilities;
            $org->update(['settings' => $settings]);

            AuditLog::record($request->user(), 'crm.rights.default_set', $org, [
                'modules' => array_keys($rights),
            ]);
        }

        return response()->json([
            'message' => $this->say($applied, $data['set_as_default'] ?? false),
            'data' => ['applied' => $applied],
        ]);
    }

    /**
     * Everybody this caller may hand a set of rights to.
     *
     * Admins are out because rights do not describe them, and the caller is
     * out because nobody edits their own — the two exclusions the one-person
     * screen makes by simply not being open on those people.
     */
    private function recipients(Request $request)
    {
        $org = $request->attributes->get('crm_org');
        $me = $request->attributes->get('crm_member');

        return Member::visible()
            // Named on the picker, so the names come with them.
            ->with('user:id,name')
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->where('crm_role', '!=', 'admin')
            ->where('id', '!=', $me->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (Member $m) => $me->maySetRightsOn($m))
            ->values();
    }

    private function say(int $applied, bool $default): string
    {
        $parts = [];

        if ($applied) {
            $parts[] = $applied === 1 ? '1 person now has these rights' : $applied . ' people now have these rights';
        }
        if ($default) {
            $parts[] = 'new employees will start with them';
        }

        return $parts === [] ? 'Nothing to change.' : ucfirst(implode(', and ', $parts)) . '.';
    }
}
