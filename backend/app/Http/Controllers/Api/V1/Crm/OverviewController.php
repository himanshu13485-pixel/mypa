<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Member;
use App\Models\Crm\Punch;
use App\Models\Meeting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The company as it is right now.
 *
 * The dashboard answers how the month is going - invoices raised, money in,
 * leads by stage. None of it answers the question an admin actually opens the
 * app asking on a Tuesday morning: who is here, and what is happening.
 *
 * Three things, in the order they are wanted. Who is working. What is running.
 * Then the standing numbers, which is the only part that would still be true
 * tomorrow.
 */
class OverviewController extends Controller
{
    /** How long after their last request somebody stops counting as here. */
    private const PRESENT_MINUTES = 5;

    public function show(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $me = $request->attributes->get('crm_member');

        // Company-wide numbers are company authority, not a grantable right.
        abort_unless(in_array($me->crm_role, ['admin', 'subadmin'], true), 403);

        return response()->json(['data' => [
            'members' => $this->members($org),
            'meetings' => $this->liveMeetings($org),
            'overview' => $this->numbers($org),
        ]]);
    }

    /**
     * Who is working, and who is merely signed in.
     *
     * Two different facts, and an admin needs both: punched in says they
     * started their day, online says they are at the screen now. Somebody can
     * be one without the other - punched in and gone to a site visit, or
     * online at nine on a day they never punched.
     *
     * Presence here ignores the personal privacy setting on purpose: this is
     * an employer looking at their own staff inside a company workspace, which
     * is a different relationship from one person looking up another. It is
     * also why this endpoint is admins only.
     */
    private function members($org): array
    {
        $members = Member::visible()->with(['user'])
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->get();

        $punchedIn = Punch::where('organization_id', $org->id)
            // whereDate, not where: the column is a DATE and the cast hands
            // back a Carbon, so a plain string comparison misses every row.
            ->whereDate('work_date', now()->toDateString())
            ->whereNotNull('punch_in')
            ->whereNull('punch_out')
            ->pluck('member_id')
            ->all();

        $cutoff = now()->subMinutes(self::PRESENT_MINUTES);

        return $members->map(fn (Member $m) => [
            'uuid' => $m->uuid,
            'name' => $m->user?->name ?? $m->name,
            'employee_code' => $m->employee_code,
            'designation' => $m->designation,
            'avatar' => $m->user?->profile?->avatar,
            'photo_path' => $m->user?->profile?->photo_path,
            'online' => (bool) $m->user?->last_active_at?->gt($cutoff),
            'punched_in' => in_array($m->id, $punchedIn, true),
            'last_active_at' => $m->user?->last_active_at?->toIso8601String(),
        ])
            /*
             * The people who are here, first. An admin opening this is
             * looking for somebody, and the ones who can answer are the ones
             * worth putting at the top.
             */
            ->sortBy(fn (array $row) => [$row['online'] ? 0 : 1, $row['punched_in'] ? 0 : 1, $row['name']])
            ->values()
            ->all();
    }

    /**
     * Meetings happening now, by the company's own people.
     *
     * Started and not ended - not "scheduled for this hour", which is a
     * diary entry and may be a room nobody walked into.
     */
    private function liveMeetings($org): array
    {
        $userIds = Member::visible()->where('organization_id', $org->id)
            ->where('status', 'active')
            ->whereNotNull('user_id')->pluck('user_id');

        return Meeting::with('host:id,uuid,name')
            ->whereIn('host_id', $userIds)
            ->where('status', 'active')
            ->orderBy('started_at')
            ->get()
            ->map(fn (Meeting $m) => [
                'uuid' => $m->uuid,
                'code' => $m->code,
                'title' => $m->title,
                'host' => $m->host?->name,
                'started_at' => $m->started_at?->toIso8601String(),
                'participants' => $m->participants()->wherePivot('status', 'joined')->count(),
            ])->values()->all();
    }

    /** The standing numbers - the part that would still be true tomorrow. */
    private function numbers($org): array
    {
        $today = now()->toDateString();

        $active = Member::visible()->where('organization_id', $org->id)
            ->where('status', 'active')->count();

        return [
            'members_total' => Member::visible()->where('organization_id', $org->id)->count(),
            'members_active' => $active,
            'punched_in_today' => Punch::where('organization_id', $org->id)
                ->whereDate('work_date', $today)->whereNotNull('punch_in')->distinct('member_id')->count('member_id'),
            'on_leave_today' => \App\Models\Crm\Leave::where('organization_id', $org->id)
                ->where('status', 'approved')
                ->where('date_from', '<=', $today)->where('date_to', '>=', $today)
                ->distinct('member_id')->count('member_id'),
            'clients_active' => \App\Models\Crm\Client::where('organization_id', $org->id)
                ->where('status', 'active')->count(),
            'leads_open' => \App\Models\Crm\Lead::where('organization_id', $org->id)
                ->whereNotIn('status', ['won', 'lost'])->count(),
            'approvals_pending' => \App\Models\Crm\Approval::where('organization_id', $org->id)
                ->where('status', 'pending')->count(),
        ];
    }
}
