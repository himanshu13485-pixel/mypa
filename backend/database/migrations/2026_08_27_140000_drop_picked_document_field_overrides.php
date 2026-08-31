<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The issuing company, the client and the salesperson are picked from their
 * own sections — Billing setup, Clients and Users — so they left the list of
 * document fields a company can re-word. Any customisation raised for them
 * while they were on the list would sit there doing nothing, so it goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('crm_custom_fields')
            ->where('entity', 'invoice')
            ->where('is_builtin', true)
            ->whereIn('key', ['issuing_company', 'client', 'member'])
            ->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the rows had no effect once the columns left
        // the list, and re-creating them would only put dead rows back.
    }
};
