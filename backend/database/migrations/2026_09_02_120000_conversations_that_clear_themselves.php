<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long a conversation keeps what was said in it.
 *
 * Null is the default and means forever, which is what every existing
 * conversation has been doing and must go on doing — a retention setting
 * that arrived switched on would delete people's history behind them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedInteger('auto_delete_hours')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('auto_delete_hours');
        });
    }
};
