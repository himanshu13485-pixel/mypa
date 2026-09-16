<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quotation is not a sale, and somebody may overrule the books.
 *
 * Counting proformas made a client's first invoice read as repeat business
 * whenever a quote came first, which is most of the time. Only a tax invoice
 * that still stands makes the next one a repeat.
 *
 * And some cases the ledger cannot know: two firms under one name, a client
 * record that should have been two. So a status set by hand is kept, and the
 * restating leaves it alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_invoices', 'client_category_manual')) {
            Schema::table('crm_invoices', function (Blueprint $table) {
                $table->boolean('client_category_manual')->default(false);
            });
        }

        // Read every client's ledger again, tax invoices only this time.
        $seen = [];
        DB::table('crm_invoices')
            ->select('id', 'organization_id', 'client_id', 'kind', 'invoice_date', 'status', 'client_category')
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

                    if ($row->kind === 'invoice' && $row->status !== 'cancelled') {
                        $seen[$key] = true;
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_invoices', 'client_category_manual')) {
            Schema::table('crm_invoices', fn (Blueprint $table) => $table->dropColumn('client_category_manual'));
        }
    }
};
