<?php

namespace App\Services\Crm;

use App\Models\Crm\Holiday;
use App\Models\Crm\Leave;
use App\Models\Crm\LeaveLedger;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use Carbon\Carbon;

/**
 * The paid-leave account.
 *
 * The rule the company works to: nothing accrues during probation; from the
 * first of the month after probation ends, one paid day is earned each
 * month; leave taken is spent out of that balance, and anything the balance
 * cannot cover is still leave but is not paid for; whatever is left on 31
 * March is bought back at one day of basic salary and the new year opens at
 * nothing.
 *
 * Every one of those movements is a ledger row. A balance is read, never
 * written, so it cannot quietly disagree with its own history.
 */
class LeaveAccount
{
    public function __construct(private Organization $org)
    {
    }

    private function policy(): array
    {
        return $this->org->hrPolicy();
    }

    public function financialYear(?Carbon $on = null): int
    {
        return Holiday::financialYearOf($on ?? now(), (int) $this->policy()['financial_year_start_month']);
    }

    /** What the account holds right now, for one member, in one year. */
    public function balance(Member $member, ?int $year = null): float
    {
        $year ??= $this->financialYear();
        $rows = LeaveLedger::where('member_id', $member->id)->where('financial_year', $year)->get();

        return round(
            $rows->where('kind', 'credit')->sum('days')
            - $rows->where('kind', 'debit')->sum('days')
            - $rows->where('kind', 'encash')->sum('days'),
            2
        );
    }

    /** @return array<string, mixed> the account as a screen wants to show it */
    public function statement(Member $member, ?int $year = null): array
    {
        $year ??= $this->financialYear();
        $rows = LeaveLedger::where('member_id', $member->id)->where('financial_year', $year)->get();
        $policy = $this->policy();
        $endsOn = $member->probationEndsOn((int) $policy['probation_days']);

        return [
            'financial_year' => $year,
            'label' => $year . '–' . substr((string) ($year + 1), 2),
            'earned' => round($rows->where('kind', 'credit')->sum('days'), 2),
            'taken' => round($rows->where('kind', 'debit')->sum('days'), 2),
            'encashed' => round($rows->where('kind', 'encash')->sum('days'), 2),
            'balance' => $this->balance($member, $year),
            'on_probation' => $member->onProbation((int) $policy['probation_days']),
            'probation_ends_on' => $endsOn?->toDateString(),
            // The month the first day lands, so nobody has to work it out.
            'accrual_starts_on' => $endsOn?->copy()->addMonthNoOverflow()->startOfMonth()->toDateString(),
            'monthly_credit' => (float) $policy['monthly_leave_credit'],
        ];
    }

    /**
     * Earn a month's leave. Called on the 1st; safe to call again, because
     * the period is unique per member — a job that runs twice credits once.
     *
     * Returns the days credited, or 0 when nothing was owed.
     */
    public function creditMonth(Member $member, Carbon $month, ?int $byUserId = null): float
    {
        $policy = $this->policy();
        $first = $month->copy()->startOfMonth();

        if ($member->status !== 'active' || ! $member->joined_at) {
            return 0.0;
        }

        // Probation must be over before the month begins: the first paid day
        // lands on the 1st AFTER it ends, never part-way through.
        $endsOn = $member->probationEndsOn((int) $policy['probation_days']);
        if ($endsOn === null || $endsOn->gte($first)) {
            return 0.0;
        }

        $days = (float) $policy['monthly_leave_credit'];
        if ($days <= 0) {
            return 0.0;
        }

        $created = LeaveLedger::firstOrCreate(
            ['member_id' => $member->id, 'kind' => 'credit', 'period' => $first->format('Y-m')],
            [
                'organization_id' => $this->org->id,
                'financial_year' => $this->financialYear($first),
                'days' => $days,
                'effective_on' => $first->toDateString(),
                'note' => 'Monthly credit for ' . $first->format('F Y'),
                'created_by' => $byUserId,
            ],
        );

        return $created->wasRecentlyCreated ? $days : 0.0;
    }

    /**
     * Spend the balance on an approved leave. What the balance cannot cover
     * is still leave — it simply is not paid for, and says so on the record.
     *
     * @return array{paid: float, unpaid: float}
     */
    public function spend(Leave $leave, ?int $byUserId = null): array
    {
        $member = $leave->member;
        $year = $this->financialYear($leave->date_from);
        $wanted = (float) $leave->days;
        $available = max(0.0, $this->balance($member, $year));
        $paid = min($wanted, $available);
        $unpaid = round($wanted - $paid, 2);

        if ($paid > 0) {
            LeaveLedger::create([
                'organization_id' => $this->org->id,
                'member_id' => $member->id,
                'financial_year' => $year,
                'kind' => 'debit',
                'days' => $paid,
                'effective_on' => $leave->date_from->toDateString(),
                'leave_id' => $leave->id,
                'note' => $leave->category . ' from ' . $leave->date_from->format('d M Y'),
                'created_by' => $byUserId,
            ]);
        }

        $leave->update(['paid_days' => $paid, 'unpaid_days' => $unpaid]);

        return ['paid' => $paid, 'unpaid' => $unpaid];
    }

    /** Give the days back — an approval reversed, or a leave withdrawn. */
    public function refund(Leave $leave): void
    {
        LeaveLedger::where('leave_id', $leave->id)->where('kind', 'debit')->delete();
        $leave->update(['paid_days' => 0, 'unpaid_days' => 0]);
    }

    /**
     * Year end: buy back what is left at one day of basic salary, and open
     * the new year at nothing. Idempotent — the period is the year itself.
     *
     * @return array<int, array<string, mixed>> what each member was paid
     */
    public function encashYear(int $year, ?int $byUserId = null): array
    {
        if (! ($this->policy()['encash_unused_leave'] ?? true)) {
            return [];
        }

        [, $lastDay] = Holiday::financialYearRange($year, (int) $this->policy()['financial_year_start_month']);
        $paid = [];

        $members = Member::visible()->with('user:id,name')
            ->where('organization_id', $this->org->id)
            ->get();

        foreach ($members as $member) {
            $balance = $this->balance($member, $year);
            if ($balance <= 0) {
                continue;
            }

            // One day of basic salary as it stood at the end of the year.
            $monthly = (float) ($member->currentSalary()?->amount ?? 0);
            $dayRate = $monthly > 0 ? round($monthly / $lastDay->daysInMonth, 2) : 0.0;
            $amount = round($balance * $dayRate, 2);

            $row = LeaveLedger::firstOrCreate(
                ['member_id' => $member->id, 'kind' => 'encash', 'period' => (string) $year],
                [
                    'organization_id' => $this->org->id,
                    'financial_year' => $year,
                    'days' => $balance,
                    'effective_on' => $lastDay->toDateString(),
                    'amount' => $amount,
                    'note' => $balance . ' unused leave day(s) paid at ' . number_format($dayRate, 2) . '/day',
                    'created_by' => $byUserId,
                ],
            );

            if ($row->wasRecentlyCreated) {
                $paid[] = [
                    'member_uuid' => $member->uuid,
                    'name' => $member->user?->name,
                    'days' => $balance,
                    'day_rate' => $dayRate,
                    'amount' => $amount,
                ];
            }
        }

        return $paid;
    }
}
