<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A dispatch nobody chases is a client waiting.
 *
 * The dispatch status sat on the document and nothing ever asked about it, so
 * "Due" and "In process" stayed that way until somebody happened to look. Now
 * a document that has not gone out asks the office about itself, from
 * dispatch_remind_at onwards, and anybody can defer it to a date of their own.
 *
 * Everything already raised and still waiting is given its first ask now, so
 * the backlog surfaces once rather than staying invisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'dispatch_remind_at' => fn (Blueprint $t) => $t->dateTime('dispatch_remind_at')->nullable(),
            'dispatch_snoozed_until' => fn (Blueprint $t) => $t->dateTime('dispatch_snoozed_until')->nullable(),
        ] as $column => $add) {
            if (! Schema::hasColumn('crm_invoices', $column)) {
                Schema::table('crm_invoices', $add);
            }
        }

        DB::table('crm_invoices')
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereIn('dispatch_status', ['pending', 'partial', 'in_process'])
            ->whereNull('dispatch_remind_at')
            ->update(['dispatch_remind_at' => now()]);
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['dispatch_remind_at', 'dispatch_snoozed_until'],
            fn ($c) => Schema::hasColumn('crm_invoices', $c),
        ));
        if ($columns !== []) {
            Schema::table('crm_invoices', fn (Blueprint $t) => $t->dropColumn($columns));
        }
    }
};
