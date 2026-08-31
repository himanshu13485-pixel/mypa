<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-employee override of the incentive payment gate. Null follows the
 * HR Policy; true holds this person's incentive until full payment whatever
 * the policy says; false releases it regardless — the escape hatch for the
 * one employee whose incentive must flow anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_members', function (Blueprint $table) {
            $table->boolean('incentive_needs_payment')->nullable()->after('is_salesperson');
        });
    }

    public function down(): void
    {
        Schema::table('crm_members', function (Blueprint $table) {
            $table->dropColumn('incentive_needs_payment');
        });
    }
};
