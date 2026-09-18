<?php

namespace App\Console\Commands;

use App\Models\Crm\Organization;
use App\Services\Crm\RenewalWarner;
use Illuminate\Console\Command;

/**
 * The daily look ahead: work orders about to run out.
 *
 * Once a day is the right cadence, because validity is measured in days and
 * the warnings are set in days. Early morning, so the office finds them
 * waiting rather than watching them arrive through the afternoon.
 */
class CrmWarnRenewals extends Command
{
    protected $signature = 'crm:warn-renewals
        {--org= : Only this organization code}
        {--dry-run : Say what would go out, send nothing}';

    protected $description = 'Warn the executive, and the client where asked, that a work order is expiring';

    public function handle(RenewalWarner $warner): int
    {
        $dry = (bool) $this->option('dry-run');
        $totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        Organization::where('status', 'active')
            ->when($this->option('org'), fn ($q, $code) => $q->where('code', $code))
            ->get()
            ->each(function (Organization $organization) use ($warner, $dry, &$totals) {
                $result = $warner->runFor($organization, $dry);
                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $result[$key];
                }

                if ($result['sent'] > 0 || $result['failed'] > 0) {
                    $this->line(sprintf(
                        '%s: %d sent, %d failed.',
                        $organization->name, $result['sent'], $result['failed'],
                    ));
                }
            });

        $this->info(sprintf(
            '%s%d warning(s) sent; %d failed, %d already said.',
            $dry ? '[dry run] ' : '', $totals['sent'], $totals['failed'], $totals['skipped'],
        ));

        return self::SUCCESS;
    }
}
