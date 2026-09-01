<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Member;
use App\Models\Crm\Punch;
use App\Services\Crm\AttendanceCalendar;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Punch Report. Everyone punches themselves in and out — the timestamp and
 * IP are taken by the server, never sent by the client. Status is computed
 * against the org's office hours on the way in (late / present / sunday)
 * and on the way out (half day), and an admin can override it afterwards,
 * exactly like the old dropdown.
 */
class PunchController extends Controller
{
    /**
     * Office hours are not this screen's to invent — they are the HR
     * Policy's, so late means the same thing wherever it is read.
     */
    private function config($org): array
    {
        $hr = $org->hrPolicy();

        return [
            'start' => $hr['work_start'],
            'grace_minutes' => (int) $hr['grace_minutes'],
            'half_day_after_minutes' => (int) $hr['half_day_after_minutes'],
            'half_day_hours' => (float) $hr['half_day_hours'],
            // Whether the punch button should ask the browser where it is.
            // A company that never registered an office asks for nothing.
            'location' => ($hr['office_lat'] ?? null) === null ? null : [
                'required' => (bool) ($hr['punch_needs_location'] ?? false),
                'radius_m' => (int) ($hr['office_radius_m'] ?? 200),
            ],
        ];
    }

    public function today(Request $request): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $org = $request->attributes->get('crm_org');

        $punch = Punch::where('organization_id', $org->id)
            ->where('member_id', $me->id)
            ->whereDate('work_date', now()->toDateString())
            ->first();

        return response()->json(['data' => [
            'today' => now()->toDateString(),
            'config' => $this->config($org),
            'punch' => $punch ? $this->serialize($punch) : null,
            // Some people are not measured by the clock. They keep the
            // buttons — a director who wants to punch may — but the screen
            // stops implying they have forgotten something.
            'punch_waived' => (bool) $me->punch_waived,
        ]]);
    }

    public function punchIn(Request $request): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $org = $request->attributes->get('crm_org');
        $cfg = $this->config($org);

        $existing = Punch::where('organization_id', $org->id)
            ->where('member_id', $me->id)
            ->whereDate('work_date', now()->toDateString())
            ->first();
        if ($existing?->punch_in) {
            abort(422, 'Already punched in at ' . $existing->punch_in->format('H:i:s') . '.');
        }

        $now = now();
        // The day's OWN office hours (Saturday runs shorter than Monday),
        // and the week-off list rather than a hardcoded Sunday.
        $schedule = $org->scheduleFor($now->dayOfWeek);
        $weekOff = array_map('intval', $org->hrPolicy()['week_off_days'] ?? [0]);
        $start = $now->copy()->setTimeFromTimeString($schedule['start']);
        $lateAfter = $start->copy()->addMinutes($cfg['grace_minutes']);
        // Arrive late enough and it stops being lateness and becomes half a day.
        $halfAfter = $start->copy()->addMinutes($cfg['half_day_after_minutes']);
        $status = in_array($now->dayOfWeek, $weekOff, true)
            ? 'week_off'
            : ($now->gt($halfAfter) ? 'half_day' : ($now->gt($lateAfter) ? 'late' : 'present'));
        // The Admin's waiver: this person's late arrival is simply Present.
        if ($status === 'late' && $me->late_waived) {
            $status = 'present';
        }

        // What this punch can honestly say about where it came from. The
        // device kind is read from the request rather than taken from the
        // client's word for it; the place is asked for only when the company
        // registered an office, and insisted on only when it said so.
        $origin = app(\App\Services\Crm\PunchOrigin::class);
        $office = $origin->office($org);
        $where = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $distance = null;
        if ($office && isset($where['latitude'], $where['longitude'])) {
            $distance = $origin->metresBetween(
                (float) $where['latitude'], (float) $where['longitude'],
                $office['lat'], $office['lng'],
            );
        }
        if ($office && $office['required'] && $distance === null) {
            abort(422, 'This company records where punches are made from — allow location and try again.');
        }

        $values = [
            'punch_in' => $now,
            'in_ip' => $request->ip(),
            'in_device' => $origin->deviceKind($request),
            'in_lat' => $where['latitude'] ?? null,
            'in_lng' => $where['longitude'] ?? null,
            'in_distance_m' => $distance,
            'status' => $status,
            'status_source' => 'auto',
        ];
        if ($existing) {
            $existing->update($values);
            $punch = $existing;
        } else {
            $punch = Punch::create($values + [
                'organization_id' => $org->id,
                'member_id' => $me->id,
                'work_date' => $now->toDateString(),
            ]);
        }

        return response()->json(['message' => 'Punched in at ' . $now->format('H:i:s') . '.', 'data' => $this->serialize($punch)], 201);
    }

    public function punchOut(Request $request): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $org = $request->attributes->get('crm_org');
        $cfg = $this->config($org);

        $punch = Punch::where('organization_id', $org->id)
            ->where('member_id', $me->id)
            ->whereDate('work_date', now()->toDateString())
            ->first();

        if (! $punch || ! $punch->punch_in) {
            abort(422, 'Punch in first.');
        }
        if ($punch->punch_out) {
            abort(422, 'Already punched out at ' . $punch->punch_out->format('H:i:s') . '.');
        }

        $now = now();
        $updates = [
            'punch_out' => $now,
            'out_ip' => $request->ip(),
            'out_device' => app(\App\Services\Crm\PunchOrigin::class)->deviceKind($request),
        ];

        // A short day becomes a half day — unless an admin already ruled.
        $hours = $punch->punch_in->diffInMinutes($now) / 60;
        if ($punch->status_source === 'auto'
            && ! in_array($punch->status, ['sunday', 'holiday'], true)
            && $hours < $cfg['half_day_hours']) {
            $updates['status'] = 'half_day';
        }

        $punch->update($updates);

        return response()->json(['message' => 'Punched out at ' . $now->format('H:i:s') . '.', 'data' => $this->serialize($punch->fresh())]);
    }

    /**
     * The report. Built as a calendar, not as a pile of punches: every
     * person, every day in the window, so a day nobody punched still says
     * what it was — a declared holiday, a weekly off, approved leave, or a
     * genuine absence. The summary at the foot spans the whole filter, and
     * carries the payable-day count a salary is built from.
     */
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $from = Carbon::parse($request->query('date_from', now()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('date_to', now()->toDateString()))->startOfDay();
        if ($to->lt($from)) {
            abort(422, 'The last day cannot come before the first.');
        }
        // A calendar is one row per person per day, so the window is bounded.
        if ($from->diffInDays($to) > 366) {
            abort(422, 'A range longer than a year is too wide to read day by day.');
        }

        $members = $this->visibleMembers($request);
        if ($picked = $request->query('member')) {
            $members = $members->where('uuid', $picked)->values();
        }

        $calendar = new AttendanceCalendar($org);
        $rows = $calendar->build($members, $from, $to);
        $summary = $calendar->summarise($rows);

        // Days outside anyone's employment are not days at all.
        $rows = $rows->where('counts', true)->values();

        if ($status = $request->query('status')) {
            $rows = $status === 'holiday'
                ? $rows->whereIn('status', ['holiday', 'week_off', 'sunday'])->values()
                : $rows->where('status', $status)->values();
        }

        // Newest first, as a report is read.
        $rows = $rows->sortByDesc('work_date')->values();
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 62;

        return response()->json([
            'summary' => [
                'statuses' => collect(Punch::STATUSES)->push('leave', 'week_off')->unique()
                    ->map(fn ($s) => ['status' => $s, 'count' => $rows->where('status', $s)->count()])
                    ->filter(fn ($s) => $s['count'] > 0)->values(),
                'members' => $summary,
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'policy' => $this->config($org),
            ],
            'data' => $rows->forPage($page, $perPage)->values(),
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
            'per_page' => $perPage,
            'total' => $rows->count(),
        ]);
    }

    /**
     * The people this member may read. A plain employee reads only their
     * own days; a Team Head their subtree; a manager the whole floor.
     *
     * @return \Illuminate\Support\Collection<int, Member>
     */
    private function visibleMembers(Request $request)
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        // Attendance is personal, like salary: one's own rows, or the
        // Admin/Subadmin. A Team Workspace leader does NOT read the team's
        // punches, whatever window they hold elsewhere.
        $seesAll = in_array($me->crm_role, ['admin', 'subadmin'], true);

        return Member::visible()->with('user:id,name,email')
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->when(! $seesAll, fn ($q) => $q->where('id', $me->id))
            ->orderBy('id')
            ->get();
    }

    /**
     * Admin override: the old status dropdown. A day with no punch row can
     * be ruled on too — the calendar shows those days now, so the dropdown
     * has to be able to answer for them.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        // Attendance drives payroll: rewriting a day is the Admin's or a
        // Subadmin's, never a granted right alone.
        /** @var \App\Models\Crm\Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(in_array($me->crm_role, ['admin', 'subadmin'], true), 403,
            'Overriding attendance is for the Admin or a Subadmin.');

        $data = $request->validate([
            'status' => ['required', Rule::in(array_merge(Punch::STATUSES, ['leave', 'week_off']))],
            'note' => ['nullable', 'string', 'max:512'],
            // Sent instead of a real id when the day was never punched.
            'member_uuid' => ['nullable', 'string'],
            'work_date' => ['nullable', 'date'],
        ]);

        if ($id === 0) {
            $member = Member::where('organization_id', $org->id)
                ->where('uuid', $data['member_uuid'] ?? '')->firstOrFail();
            $punch = Punch::firstOrNew([
                'organization_id' => $org->id,
                'member_id' => $member->id,
                'work_date' => Carbon::parse($data['work_date'])->toDateString(),
            ]);
        } else {
            $punch = Punch::where('organization_id', $org->id)->findOrFail($id);
        }

        $before = $punch->exists ? $punch->status : null;
        $punch->fill([
            'status' => $data['status'],
            'note' => $data['note'] ?? $punch->note,
            'status_source' => 'manual',
        ])->save();

        // A hand on the attendance record moves money, so it goes on the
        // trail: whose day, what it said, what it says now, and why.
        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'punch.overridden', $punch, array_filter([
            'employee' => $punch->member?->user?->name,
            'work_date' => $punch->work_date->toDateString(),
            'from' => $before ?? 'as computed',
            'to' => $data['status'],
            'note' => $data['note'] ?? null,
        ]));

        return response()->json(['message' => 'Punch updated.', 'data' => $this->serialize($punch->fresh()->load('member.user:id,name'))]);
    }

    // ---- Helpers -----------------------------------------------------------

    private function serialize(Punch $p): array
    {
        return [
            'id' => $p->id,
            'work_date' => $p->work_date->toDateString(),
            'member' => $p->member ? ['uuid' => $p->member->uuid, 'name' => $p->member->user?->name] : null,
            'punch_in' => $p->punch_in?->format('H:i:s'),
            'punch_out' => $p->punch_out?->format('H:i:s'),
            'hours' => $p->hours(),
            'in_ip' => $p->in_ip,
            'out_ip' => $p->out_ip,
            // How the punch was made, and how far from the office it was
            // made — shown in the report so a manager can ask, rather than
            // acted on here, because neither of these is a verdict.
            'in_device' => $p->in_device,
            'out_device' => $p->out_device,
            'in_distance_m' => $p->in_distance_m,
            'status' => $p->status,
            'status_source' => $p->status_source,
            'note' => $p->note,
        ];
    }
}
