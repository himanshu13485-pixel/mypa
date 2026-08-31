<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale returned in full can take its already-paid incentive back with it.
 * The cancellation ruling carries the choice: `recover` claws the paid
 * installments back as a negative line on the next slip, computed live —
 * never typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_incentive_holds', function (Blueprint $table) {
            $table->boolean('recover')->default(false)->after('only_month');
        });
    }

    public function down(): void
    {
        Schema::table('crm_incentive_holds', function (Blueprint $table) {
            $table->dropColumn('recover');
        });
    }
};
