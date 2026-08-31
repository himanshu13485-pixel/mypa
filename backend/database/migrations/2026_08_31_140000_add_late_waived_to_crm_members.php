<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Admin's late waiver: for a named person, lateness is simply not
 * counted — a late arrival is marked Present. Only the Admin grants it,
 * and only the person holding it sees it on their profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_members', function (Blueprint $table) {
            $table->boolean('late_waived')->default(false)->after('probation_days');
        });
    }

    public function down(): void
    {
        Schema::table('crm_members', function (Blueprint $table) {
            $table->dropColumn('late_waived');
        });
    }
};
