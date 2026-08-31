<?php

namespace App\Console\Commands;

use App\Models\Crm\Organization;
use App\Services\Crm\PaymentChaser;
use Illuminate\Console\Command;

/**
 * The daily chase. Each company that has switched the schedule on gets its
 * overdue invoices written to on the days it chose.
 */
class CrmChasePayments extends Command
{
    protected $signature = 'crm:chase-payments
        {--org= : Only this organization code}
        {--dry-run : Say what would go out, send nothing}';

    protected $description = 'Send the scheduled payment reminders for every CRM company';

    public function handle(PaymentChaser $chaser): int
    {
        $organizations = Organization::where('status', 'active')
            ->when($this->option('org'), fn ($q, $code) => $q->where('code', $code))
            ->get();

        $totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($organizations as $organization) {
            $result = $chaser->runFor($organization, (bool) $this->option('dry-run'));
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }

            if (array_sum($result) > 0) {
                $this->line(sprintf(
                    '%s: %d sent, %d failed, %d skipped',
                    $organization->name, $result['sent'], $result['failed'], $result['skipped'],
                ));
            }
        }

        $this->info(sprintf(
            '%sChased %d invoice(s); %d failed, %d skipped.',
            $this->option('dry-run') ? '[dry run] ' : '', $totals['sent'], $totals['failed'], $totals['skipped'],
        ));

        return self::SUCCESS;
    }
}
