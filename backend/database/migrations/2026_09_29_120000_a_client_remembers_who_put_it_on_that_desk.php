<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who put this client on that desk.
 *
 * The books already said whose client it is and when it came on them; they
 * did not say who decided that. For a client somebody added for themselves
 * the answer is their own name, which is still worth recording - a portfolio
 * that grew by transfer reads differently from one that was built.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_clients', 'assigned_by')) {
            Schema::table('crm_clients', function (Blueprint $table) {
                $table->unsignedBigInteger('assigned_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_clients', 'assigned_by')) {
            Schema::table('crm_clients', fn (Blueprint $table) => $table->dropColumn('assigned_by'));
        }
    }
};
