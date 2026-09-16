<?php

namespace App\Services\Crm;

use App\Models\Crm\Invoice;

/**
 * New business, or a client coming back.
 *
 * One rule, in one place, because the answer belongs to the ledger rather
 * than to any single document: the earliest TAX INVOICE a client was ever
 * given is new business, and every one after it is repeat business.
 *
 * Proformas do not count. A proforma is a quotation - the client has been
 * offered something, not billed for it - so a first invoice that follows a
 * quote is still the first sale, and reading it as repeat business made a
 * brand new client look like an old one.
 *
 * A cancelled invoice does not count either: a sale that was called off
 * never happened, so the next document is the first.
 *
 * Restated for the whole client whenever their paperwork changes, so a
 * backdated invoice takes its place at the front of the queue and the ones
 * that followed it read as repeats. A word somebody set by hand is never
 * overwritten - see $manual below.
 */
class ClientBusinessStatus
{
    /** @return int how many documents the restating actually changed */
    public static function restate(int $organizationId, ?int $clientId): int
    {
        if (! $clientId) {
            return 0;
        }

        $documents = Invoice::where('organization_id', $organizationId)
            ->where('client_id', $clientId)
            ->orderBy('invoice_date')->orderBy('id')
            ->get(['id', 'kind', 'invoice_date', 'status', 'client_category', 'client_category_manual']);

        $billed = false;
        $changed = 0;

        foreach ($documents as $document) {
            $should = $billed ? 'existing' : 'new';

            // Somebody decided this one themselves, and the books are in no
            // position to argue: two firms of the same name, a client record
            // that should have been two, a sale nobody could have known about.
            if (! $document->client_category_manual && $document->client_category !== $should) {
                Invoice::whereKey($document->id)->update(['client_category' => $should]);
                $changed++;
            }

            // Only a tax invoice that still stands makes the next one a repeat.
            $billed = $billed || ($document->kind === 'invoice' && $document->status !== 'cancelled');
        }

        return $changed;
    }
}
