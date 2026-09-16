<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money not yet in, asking the person whose sale it was.
 *
 * The company already writes to clients about unpaid invoices; nothing ever
 * told the salesperson, who is the one who can pick up the phone. Now an
 * invoice still owed money asks them from payment_remind_at onwards, and
 * anybody can defer it to the day the client actually promised.
 *
 * Everything already owed is given its first ask now, so a backlog surfaces
 * once rather than staying invisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'payment_remind_at' => fn (Blueprint $t) => $t->dateTime('payment_remind_at')->nullable(),
            'payment_snoozed_until' => fn (Blueprint $t) => $t->dateTime('payment_snoozed_until')->nullable(),
        ] as $column => $add) {
            if (! Schema::hasColumn('crm_invoices', $column)) {
                Schema::table('crm_invoices', $add);
            }
        }

        DB::table('crm_invoices')
            ->where('kind', 'invoice')
            ->where('status', '!=', 'cancelled')
            ->whereIn('payment_status', ['due', 'partial'])
            ->whereNull('payment_remind_at')
            ->update(['payment_remind_at' => now()]);
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['payment_remind_at', 'payment_snoozed_until'],
            fn ($c) => Schema::hasColumn('crm_invoices', $c),
        ));
        if ($columns !== []) {
            Schema::table('crm_invoices', fn (Blueprint $t) => $t->dropColumn($columns));
        }
    }
};
