<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the deal was actually worth.
 *
 * `amount` is what somebody hoped for when the lead came in - a guess, made
 * before any of the work. This is the figure it closed at, and it is a
 * different number often enough that writing the second over the first would
 * lose the only record of either. Null until a lead closes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->decimal('closing_amount', 14, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn('closing_amount');
        });
    }
};
