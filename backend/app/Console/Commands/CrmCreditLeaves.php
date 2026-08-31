<?php

namespace App\Console\Commands;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Services\Crm\LeaveAccount;
use Illuminate\Console\Command;

/**
 * The monthly paid-leave accrual, and the year-end buy-back.
 *
 * Runs on the 1st. Everyone past probation earns the month's day; on 1
 * April the year just ended is also closed out, so anything unused is paid
 * at a day of basic salary and the new year opens at nothing.
 *
 * Both halves are idempotent — the ledger keys a credit to its month and an
 * encashment to its year — so a job that runs twice pays once.
 */
class CrmCreditLeaves extends Command
{
    protected $signature = 'crm:credit-leaves {--year-end : also close out the financial year that just ended}';

    protected $description = 'Credit the month’s paid leave to every employee past probation';

    public function handle(): int
    {
        $month = now()->startOfMonth();
        $credited = 0.0;
        $paidOut = 0;

        foreach (Organization::all() as $org) {
            $account = new LeaveAccount($org);
            $members = Member::where('organization_id', $org->id)->where('status', 'active')->get();

            foreach ($members as $member) {
                $credited += $account->creditMonth($member, $month);
            }

            // 1 April: the year that ended yesterday gets settled.
            $startMonth = (int) $org->hrPolicy()['financial_year_start_month'];
            if ($this->option('year-end') || ($month->month === $startMonth && $month->day === 1)) {
                $paidOut += count($account->encashYear($account->financialYear($month->copy()->subDay())));
            }
        }

        $this->info($credited . ' leave day(s) credited for ' . $month->format('F Y') . '.');
        if ($paidOut > 0) {
            $this->info($paidOut . ' account(s) closed and paid out for the year just ended.');
        }

        return self::SUCCESS;
    }
}
