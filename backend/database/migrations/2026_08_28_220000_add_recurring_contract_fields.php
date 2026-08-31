<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A 12-month contract usually starts with an invoice already raised by hand:
 * the schedule then owes eleven more, and every copy should be able to say
 * where it stands — "Recurring · 2 of 12" — on the document itself.
 *
 *  - `counts_source`: the source document is cycle 1, so the copies start
 *    counting at 2 and the schedule's runs are total − 1.
 *  - `show_on_document`: whether the copies carry the note at all.
 *  - `crm_invoices.recurring_note`: the note as it was stamped at raising —
 *    a snapshot, so cancelling the schedule never rewrites old paperwork.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_recurring_invoices', function (Blueprint $table) {
            $table->boolean('counts_source')->default(false)->after('max_occurrences');
            $table->boolean('show_on_document')->default(true)->after('auto_payment_link');
        });

        Schema::table('crm_invoices', function (Blueprint $table) {
            $table->string('recurring_note', 64)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('crm_invoices', function (Blueprint $table) {
            $table->dropColumn('recurring_note');
        });
        Schema::table('crm_recurring_invoices', function (Blueprint $table) {
            $table->dropColumn(['counts_source', 'show_on_document']);
        });
    }
};
