<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A P&L line added from the month's own figures - CGST, SGST, IGST, TDS,
 * commission, the expense book - follows them instead of freezing a typed
 * amount: auto_key names the figure, and the statement reads it afresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_pl_lines', 'auto_key')) {
            Schema::table('crm_pl_lines', function (Blueprint $table) {
                $table->string('auto_key', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_pl_lines', 'auto_key')) {
            Schema::table('crm_pl_lines', fn (Blueprint $table) => $table->dropColumn('auto_key'));
        }
    }
};
