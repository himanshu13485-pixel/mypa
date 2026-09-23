<?php

namespace App\Console\Commands;

use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Notifications\CrmNotification;
use Illuminate\Console\Command;

/**
 * A sale that has been served all the way to the end of its term.
 *
 * Dispatch says where the work has got to: Due is waiting to go out, In
 * process is being worked on. Neither is true once every work order on the
 * document has run its validity out — the thing was delivered, month by
 * month, and the last month has passed. Until now somebody had to remember
 * to go and say so, on every invoice, for ever.
 *
 * Every work order, not the first one to finish. A document carrying a
 * twelve-month listing and a three-month one is still being delivered in
 * month four, and calling it dispatched then would be saying the work is
 * done while nine months of it remain.
 *
 * Only Due and In process are touched. Partial dispatched is somebody's own
 * account of where a physical delivery has got to, and is not this
 * command's to overrule; Dispatched is already there.
 *
 * Whether the money has come in has nothing to do with it. Dispatch says
 * where the work has got to, payment says where the money has got to, and a
 * job delivered to the end of its term is delivered whether or not the
 * invoice has been settled - the two are chased separately, by different
 * people, on purpose.
 */
class CloseServedDispatches extends Command
{
    protected $signature = 'crm:close-served-dispatches {--dry-run : Say what would change, change nothing}';

    protected $description = 'Mark invoices dispatched once every work order has served its validity';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $closed = 0;

        Invoice::with(['items:id,invoice_id,validity_to', 'member.user:id,name', 'creator:id,name'])
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereIn('dispatch_status', ['pending', 'in_process'])
            ->chunkById(200, function ($invoices) use ($dry, &$closed) {
                foreach ($invoices as $invoice) {
                    $items = $invoice->items;

                    /*
                     * A document with no dated work order has nothing to
                     * finish, so nothing here can decide it is over. It stays
                     * where it is until a person says otherwise.
                     */
                    $dated = $items->filter(fn ($i) => $i->validity_to !== null);
                    if ($dated->isEmpty() || $dated->count() !== $items->count()) {
                        continue;
                    }

                    // The last term to end decides the document.
                    if ($dated->max('validity_to')->endOfDay()->isFuture()) {
                        continue;
                    }

                    $closed++;
                    if ($dry) {
                        continue;
                    }

                    $was = $invoice->dispatch_status;
                    $invoice->forceFill([
                        'dispatch_status' => 'dispatched',
                        // Whatever was chasing it has nothing left to chase.
                        'dispatch_remind_at' => null,
                        'dispatch_snoozed_until' => null,
                    ])->save();

                    ActivityLog::record(null, $invoice->organization_id, 'invoice.dispatch_served', $invoice, [
                        'number' => $invoice->number,
                        'was' => $was,
                        'served_to' => $dated->max('validity_to')->toDateString(),
                    ]);

                    /*
                     * Somebody is told.
                     *
                     * A status that changes itself in the night, with only a
                     * line in the activity log to show for it, is how people
                     * come to distrust their own screens - they remember
                     * leaving it Due and find it Dispatched, and nothing says
                     * who did it. The person whose sale it is hears about it,
                     * and money still owed is said plainly rather than left
                     * for them to notice.
                     */
                    $owed = max(0, (float) $invoice->total - (float) $invoice->payments()->sum('amount'));
                    $person = $invoice->member?->user ?? $invoice->creator;

                    $person?->notify(new CrmNotification(
                        'crm_invoice_update',
                        "{$invoice->number} is marked dispatched - every work order on it has now run its term."
                            . ($owed > 0.009 ? ' Payment of ' . number_format($owed, 2) . ' is still due on it.' : ''),
                        '/crm/invoices?kind=invoice&q=' . $invoice->number,
                    ));
                }
            });

        $this->info(($dry ? 'Would mark ' : 'Marked ') . $closed . ' invoice(s) dispatched.');

        return self::SUCCESS;
    }
}
