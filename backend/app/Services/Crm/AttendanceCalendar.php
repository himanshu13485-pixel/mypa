<?php

namespace App\Services\Crm;

use App\Models\Crm\Holiday;
use App\Models\Crm\Leave;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\Punch;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The attendance calendar: one row per person per day, whether or not
 * anybody punched.
 *
 * A punch table alone cannot answer the question a salary depends on,
 * because a day with no row is silent — absent, on approved leave and
 * public holiday all look the same. So the calendar is built the other way
 * round: walk every day in the range and ask, in order, what the day was.
 *
 * The order matters and is the policy itself:
 *   1. Somebody punched  → what they punched decides it (late, half, present)
 *   2. A declared holiday → holiday
 *   3. A weekly off       → week off
 *   4. Approved leave     → leave, or half day for a half-day leave
 *   5. Nothing at all     → absent
 *
 * An admin's manual override on a punch row beats all of it, because a
 * human looking at the facts outranks a rule about them.
 */
class AttendanceCalendar
{
    /** What each status is worth towards a day's pay. */
    public const DAY_VALUE = [
        'present' => 1.0,
        'late' => 1.0,
        'half_day' => 0.5,
        'leave' => 1.0,          // paid leave, already deducted from the account
        'leave_unpaid' => 0.0,
        'holiday' => 1.0,
        'week_off' => 1.0,
        'sunday' => 1.0,         // the older name for a weekly off
        'absent' => 0.0,
    ];

    public function __construct(private Organization $org)
    {
    }

    /**
     * Every day for every member in the window.
     *
     * @param  Collection<int, Member>  $members
     * @return Collection<int, array<string, mixed>>
     */
    public function build(Collection $members, Carbon $from, Carbon $to): Collection
    {
        $policy = $this->org->hrPolicy();
        $weekOff = array_map('intval', $policy['week_off_days'] ?? [0]);

        $memberIds = $members->pluck('id')->all();

        $punches = Punch::where('organization_id', $this->org->id)
            ->whereIn('member_id', $memberIds)
            ->whereDate('work_date', '>=', $from->toDateString())
            ->whereDate('work_date', '<=', $to->toDateString())
            ->get()
            ->groupBy(fn (Punch $p) => $p->member_id . '|' . $p->work_date->toDateString());

        $holidays = Holiday::where('organization_id', $this->org->id)
            ->whereDate('holiday_date', '>=', $from->toDateString())
            ->whereDate('holiday_date', '<=', $to->toDateString())
            ->get()
            ->keyBy(fn (Holiday $h) => $h->holiday_date->toDateString());

        /*
         * Approved leave, spread over each day it covers - and over each day
         * the balance could actually pay for.
         *
         * A leave is approved whether or not there is leave left to take: the
         * account pays for what it can and the rest is unpaid, which is what
         * paid_days and unpaid_days on the row mean. Three days taken with
         * one day in hand is one paid day and two unpaid ones.
         *
         * That split has to be made here, per day, because pay is counted a
         * day at a time. Asking only whether paid_days was more than zero -
         * which is what this did - paid all three of those days in full, so
         * leave beyond somebody's balance cost them nothing and the payroll
         * said "without pay: 0" for a month it should not have.
         *
         * The paid days are the first ones. The balance is spent as the leave
         * runs, so the days it ran out on are the later ones.
         */
        $leaveDays = [];
        Leave::where('organization_id', $this->org->id)
            ->whereIn('member_id', $memberIds)
            ->where('status', 'approved')
            ->whereDate('date_to', '>=', $from->toDateString())
            ->whereDate('date_from', '<=', $to->toDateString())
            ->get()
            ->each(function (Leave $leave) use (&$leaveDays) {
                // What the account agreed to cover, in days.
                $budget = (float) $leave->paid_days;

                for ($day = $leave->date_from->copy(); $day->lte($leave->date_to); $day->addDay()) {
                    // A half-day leave is worth half a day, so it spends half.
                    $worth = $leave->duration === 'half' ? 0.5 : 1.0;
                    $paid = max(0.0, min($worth, $budget));
                    $budget = round($budget - $paid, 2);

                    $leaveDays[$leave->member_id . '|' . $day->toDateString()] = [
                        'leave' => $leave,
                        'paid' => $paid,
                    ];
                }
            });

        $rows = collect();

        foreach ($members as $member) {
            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $key = $member->id . '|' . $day->toDateString();
                $punch = ($punches[$key] ?? collect())->first();
                $holiday = $holidays[$day->toDateString()] ?? null;
                $onLeave = $leaveDays[$key] ?? null;

                $rows->push($this->row(
                    $member, $day, $punch, $holiday,
                    $onLeave['leave'] ?? null, $onLeave['paid'] ?? 0.0,
                    $weekOff, $policy,
                ));
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, int>  $weekOff
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    private function row(
        Member $member,
        Carbon $day,
        ?Punch $punch,
        ?Holiday $holiday,
        ?Leave $leave,
        float $leavePaid,
        array $weekOff,
        array $policy,
    ): array {
        $status = null;
        $source = 'auto';

        if ($punch && $punch->status_source === 'manual') {
            // A human looked at this day and ruled on it.
            $status = $punch->status;
            $source = 'manual';
        } elseif ($punch && $punch->punch_in) {
            $status = $this->fromPunch($punch, $policy, $member);
            $source = 'punch';
        } elseif ($holiday && ! $holiday->is_optional) {
            $status = 'holiday';
            $source = 'holiday';
        } elseif (in_array($day->dayOfWeek, $weekOff, true)) {
            $status = 'week_off';
            $source = 'week_off';
        } elseif ($leave) {
            // Approved leave is why nobody is here — never "absent".
            $status = $leave->duration === 'half' ? 'half_day' : 'leave';
            $source = 'leave';
        } elseif ($member->joined_at && $day->lt($member->joined_at)) {
            // Before they joined, the day is simply not theirs.
            $status = null;
            $source = 'not_joined';
        } elseif ($day->isFuture()) {
            $status = null;
            $source = 'future';
        } elseif ($member->punch_waived) {
            /*
             * The clock is not this person's measure.
             *
             * A director does not punch, and a register that called every
             * one of their working days an absence would be wrong twice
             * over: wrong on the screen, and wrong in the payroll, which
             * counts absences and docks for them. A working day is present
             * unless something else — a holiday, a week off, approved leave
             * — has already said otherwise above.
             */
            $status = 'present';
            $source = 'punch_waived';
        } else {
            $status = 'absent';
        }

        /*
         * A leave day is worth what the balance paid for it, and no more.
         *
         * Not what the status is called: "leave" covers both the days an
         * account could pay for and the days it could not, and the second
         * kind is unpaid however it is labelled. The split was worked out
         * day by day where the leave was read - see build().
         */
        $value = $status === null ? 0.0 : (self::DAY_VALUE[$status] ?? 0.0);

        /*
         * Capped at what was paid for, rather than zeroed.
         *
         * A half-day leave this office already costs half a day, paid or
         * not - that is the house rule and CrmHrPolicyTest states it. So the
         * cap, not a flat zero: a paid half-day stays at the half day it has
         * always been, and an unpaid one loses the other half as well. The
         * difference between a paid day and an unpaid one is then the same
         * half day it is for a full day's leave, which is the only part of
         * this that was ever wrong.
         *
         * A half day that came off the clock rather than off a leave form -
         * somebody who went home at noon without asking - has no leave row
         * and is untouched.
         */
        if ($leave && in_array($status, ['leave', 'half_day'], true)) {
            $value = min($value, $leavePaid);
        }

        return [
            'id' => $punch?->id,
            'member_id' => $member->id,
            'member' => [
                'uuid' => $member->uuid,
                'name' => $member->user?->name,
                // Who the punch was made as: the account, not just the person.
                'login' => $member->user?->email,
                'employee_code' => $member->employee_code,
            ],
            'work_date' => $day->toDateString(),
            'punch_in' => $punch?->punch_in?->format('H:i:s'),
            'punch_out' => $punch?->punch_out?->format('H:i:s'),
            'hours' => $punch?->hours(),
            'in_ip' => $punch?->in_ip,
            'out_ip' => $punch?->out_ip,
            'status' => $status,
            'status_source' => $source,
            'holiday_name' => $holiday?->name,
            'leave_category' => $leave?->category,
            'note' => $punch?->note,
            'day_value' => $value,
            'counts' => $status !== null,
        ];
    }

    /** @param array<string, mixed> $policy */
    private function fromPunch(Punch $punch, array $policy, Member $member): string
    {
        $in = $punch->punch_in;
        // Each weekday has its own office hours — Saturday's shorter day is
        // measured from Saturday's own start, not Monday's.
        $daySchedule = ($policy['day_schedule'] ?? [])[(string) ($punch->work_date ?? $in)->dayOfWeek] ?? null;
        $start = $in->copy()->setTimeFromTimeString($daySchedule['start'] ?? $policy['work_start']);
        $lateAfter = $start->copy()->addMinutes((int) $policy['grace_minutes']);
        $halfAfter = $start->copy()->addMinutes((int) $policy['half_day_after_minutes']);

        // Too short a day is a half day whenever they arrived.
        if ($punch->punch_out) {
            $hours = $in->diffInMinutes($punch->punch_out) / 60;
            if ($hours < (float) $policy['half_day_hours']) {
                return 'half_day';
            }
        }

        if ($in->gt($halfAfter)) {
            return 'half_day';
        }

        // The Admin's waiver: for this person a late arrival is Present.
        if ($member->late_waived) {
            return 'present';
        }

        return $in->gt($lateAfter) ? 'late' : 'present';
    }

    /**
     * The per-person totals the report ends with, and the day count a salary
     * is built from.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function summarise(Collection $rows): Collection
    {
        return $rows->groupBy('member_id')->map(function (Collection $days) {
            $counted = $days->where('counts', true);
            $ins = $days->filter(fn ($d) => $d['punch_in']);
            $payable = round($counted->sum('day_value'), 2);
            $working = $counted->whereNotIn('status', ['holiday', 'week_off', 'sunday'])->count();

            // The late rule: every N lates cost half a day's pay. A waived
            // member's lates were already classed Present, so nothing here.
            $latesPerHalf = (int) ($this->org->hrPolicy()['lates_per_half_day'] ?? 0);
            $lateCount = $counted->where('status', 'late')->count();
            $latePenalty = $latesPerHalf > 0 ? floor($lateCount / $latesPerHalf) * 0.5 : 0.0;
            $payable = round(max(0, $payable - $latePenalty), 2);

            return [
                'member_uuid' => $days->first()['member']['uuid'],
                'login' => $days->first()['member']['login'],
                'employee_code' => $days->first()['member']['employee_code'],
                // Whether this month has anything to go on at all. A company
                // that has not started punching must not have its payroll
                // quietly zeroed by the absence of evidence.
                'has_attendance' => $days->whereIn('status_source', ['punch', 'manual', 'leave'])->isNotEmpty(),
                'name' => $days->first()['member']['name'],
                'days' => $counted->count(),
                'working_days' => $working,
                'present' => $counted->where('status', 'present')->count(),
                'late' => $lateCount,
                'late_penalty_days' => $latePenalty,
                'half_day' => $counted->where('status', 'half_day')->count(),
                'leave' => $counted->where('status', 'leave')->count(),
                'holiday' => $counted->whereIn('status', ['holiday', 'week_off', 'sunday'])->count(),
                'absent' => $counted->where('status', 'absent')->count(),
                // Leave the account could not pay for when it was approved.
                'unpaid_leave' => $counted->filter(fn ($d) => $d['status'] === 'leave' && (float) $d['day_value'] <= 0)->count(),
                // What the month is worth: the number a salary multiplies.
                'payable_days' => $payable,
                'lop_days' => round(max(0, $working - $counted
                    ->whereNotIn('status', ['holiday', 'week_off', 'sunday'])
                    ->sum('day_value')) + $latePenalty, 2),
                'avg_in' => $ins->isEmpty() ? null : substr(
                    gmdate('H:i:s', (int) $ins->avg(fn ($d) => $this->seconds($d['punch_in']))), 0, 5
                ),
            ];
        })->sortByDesc('late')->values();
    }

    private function seconds(string $time): int
    {
        [$h, $m, $s] = array_pad(array_map('intval', explode(':', $time)), 3, 0);

        return $h * 3600 + $m * 60 + $s;
    }
}
