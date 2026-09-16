<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some desks are judged on money, others on clients brought in.
 *
 * A target row carries the kind it was set as, so a month already gone is
 * read the way it was judged at the time; the member carries the kind the
 * company gives them now, so next month's row starts right.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_targets', 'kind')) {
            Schema::table('crm_targets', fn (Blueprint $t) => $t->string('kind', 16)->default('sales'));
        }
        if (! Schema::hasColumn('crm_targets', 'client_target')) {
            Schema::table('crm_targets', fn (Blueprint $t) => $t->unsignedSmallInteger('client_target')->default(0));
        }
        if (! Schema::hasColumn('crm_members', 'target_kind')) {
            Schema::table('crm_members', fn (Blueprint $t) => $t->string('target_kind', 16)->default('sales'));
        }
    }

    public function down(): void
    {
        foreach (['kind', 'client_target'] as $column) {
            if (Schema::hasColumn('crm_targets', $column)) {
                Schema::table('crm_targets', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
        if (Schema::hasColumn('crm_members', 'target_kind')) {
            Schema::table('crm_members', fn (Blueprint $t) => $t->dropColumn('target_kind'));
        }
    }
};
