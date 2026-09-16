<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every document already raised, read the way the books read it now.
 *
 * Splitting the old six-word field translated each document's own word, but a
 * word that was wrong when it was typed stayed wrong: a client billed six
 * times said "New" six times, because that is what the form defaulted to and
 * nobody was in a position to know better. The rule is simple and the books
 * can answer it - the earliest document a client was ever given is new
 * business, and every one after it is repeat business.
 *
 * Cancelled paperwork does not make the next document a repeat: a sale that
 * was called off never happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Which clients have been billed already, carried across the chunks.
        $seen = [];

        DB::table('crm_invoices')
            ->select('id', 'organization_id', 'client_id', 'invoice_date', 'status', 'client_category')
            ->whereNotNull('client_id')
            ->orderBy('organization_id')->orderBy('client_id')
            ->orderBy('invoice_date')->orderBy('id')
            ->chunk(500, function ($rows) use (&$seen) {
                foreach ($rows as $row) {
                    $key = $row->organization_id . ':' . $row->client_id;
                    $should = isset($seen[$key]) ? 'existing' : 'new';

                    if ($row->client_category !== $should) {
                        DB::table('crm_invoices')->where('id', $row->id)->update(['client_category' => $should]);
                    }

                    if ($row->status !== 'cancelled') {
                        $seen[$key] = true;
                    }
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: this only corrects a word to what the ledger says.
    }
};
