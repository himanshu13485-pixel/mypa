<?php

namespace App\Services\Crm;

use App\Models\Crm\Invoice;

/**
 * New business, or a client coming back.
 *
 * One rule, in one place, because the answer belongs to the ledger rather
 * than to any single document: the earliest document a client was ever given
 * is new business, and every one after it is repeat business. A cancelled
 * document does not make the next one a repeat - a sale that was called off
 * never happened.
 *
 * Restated for the whole client whenever their paperwork changes, so a
 * backdated document takes its place at the front of the queue and the ones
 * that followed it read as repeats, rather than a second document sitting
 * there saying "New" beside the first.
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
            ->get(['id', 'invoice_date', 'status', 'client_category']);

        $seen = false;
        $changed = 0;

        foreach ($documents as $document) {
            $should = $seen ? 'existing' : 'new';

            if ($document->client_category !== $should) {
                Invoice::whereKey($document->id)->update(['client_category' => $should]);
                $changed++;
            }

            $seen = $seen || $document->status !== 'cancelled';
        }

        return $changed;
    }
}
