<?php

namespace App\Console\Commands;

use App\Models\Crm\Organization;
use App\Services\Crm\RecurringInvoiceGenerator;
use Illuminate\Console\Command;

/** The morning billing run: every company's due subscriptions, generated. */
class CrmGenerateRecurring extends Command
{
    protected $signature = 'crm:generate-recurring
        {--org= : Only this organization code}
        {--dry-run : Count what would be raised, raise nothing}';

    protected $description = 'Raise the CRM recurring proformas and invoices that are due';

    public function handle(RecurringInvoiceGenerator $generator): int
    {
        $organizations = Organization::where('status', 'active')
            ->when($this->option('org'), fn ($q, $code) => $q->where('code', $code))
            ->get();

        $totals = ['generated' => 0, 'completed' => 0, 'failed' => 0];

        foreach ($organizations as $organization) {
            $result = $generator->runFor($organization, (bool) $this->option('dry-run'));
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }

            if (array_sum($result) > 0) {
                $this->line(sprintf(
                    '%s: %d raised, %d completed, %d failed',
                    $organization->name, $result['generated'], $result['completed'], $result['failed'],
                ));
            }
        }

        $this->info(sprintf(
            '%sRaised %d document(s); %d schedule(s) completed, %d failed.',
            $this->option('dry-run') ? '[dry run] ' : '',
            $totals['generated'], $totals['completed'], $totals['failed'],
        ));

        return self::SUCCESS;
    }
}
