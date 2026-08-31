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
            /*
             * Placed wherever the table happens to end, not after
             * probation_days.
             *
             * That column is created by the HR policy layer, which is dated
             * two days after this file and therefore runs two days later — so
             * on any database seeing the CRM for the first time this asked to
             * sit beside a column that did not exist yet, and the whole
             * migration run stopped here. It only worked where the column had
             * already arrived by some other route.
             *
             * after() decides nothing but the physical order of columns in
             * MySQL, so dropping it costs nothing and cannot fail. Renaming
             * this file to sort later would have been the other fix, and a
             * worse one: databases that already ran it under the old name
             * would run it again.
             */
            $table->boolean('late_waived')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('crm_members', function (Blueprint $table) {
            $table->dropColumn('late_waived');
        });
    }
};
