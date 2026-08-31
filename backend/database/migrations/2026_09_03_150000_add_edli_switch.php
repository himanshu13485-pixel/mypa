<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDLI becomes a facility of its own. It rode inside the PF switch, but the
 * schemes are individually optional now — an employee who wants only the
 * discussed in-hand salary takes none of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_salary_structures', function (Blueprint $table) {
            $table->boolean('has_edli')->default(true)->after('has_pf');
        });
    }

    public function down(): void
    {
        Schema::table('crm_salary_structures', function (Blueprint $table) {
            $table->dropColumn('has_edli');
        });
    }
};
