<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People the punch clock does not apply to.
 *
 * A director, a founder, a consultant on a retainer — they hold accounts
 * for everything else the CRM does, and asking them to clock in produces a
 * register full of absences that mean nothing and a payroll that quietly
 * docks them for it. The waiver says the clock is not their measure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_members', function (Blueprint $table) {
            $table->boolean('punch_waived')->default(false)->after('late_waived');
        });
    }

    public function down(): void
    {
        Schema::table('crm_members', function (Blueprint $table) {
            $table->dropColumn('punch_waived');
        });
    }
};
