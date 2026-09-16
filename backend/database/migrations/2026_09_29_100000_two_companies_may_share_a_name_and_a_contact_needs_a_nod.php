<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two companies may share a name; one person may not quietly follow a client.
 *
 * Same name, different people: two firms called "Sharma Traders" are two
 * clients, and the books were refusing the second. What makes them one record
 * is the same GST number, or the same name AND the same person behind it.
 *
 * The other way round is the case that needs a human: a name nobody has, but
 * an e-mail or a phone the books already know - the contact left ABC and
 * joined XYZ. That is not sharing a client and not a transfer, so the record
 * waits for the Company Admin or a Subadmin to say yes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'approval_status' => fn (Blueprint $t) => $t->string('approval_status', 16)->default('approved'),
            'approval_reason' => fn (Blueprint $t) => $t->string('approval_reason', 255)->nullable(),
            'matched_client_id' => fn (Blueprint $t) => $t->unsignedBigInteger('matched_client_id')->nullable(),
        ] as $column => $add) {
            if (! Schema::hasColumn('crm_clients', $column)) {
                Schema::table('crm_clients', $add);
            }
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['approval_status', 'approval_reason', 'matched_client_id'],
            fn ($c) => Schema::hasColumn('crm_clients', $c),
        ));
        if ($columns !== []) {
            Schema::table('crm_clients', fn (Blueprint $t) => $t->dropColumn($columns));
        }
    }
};
