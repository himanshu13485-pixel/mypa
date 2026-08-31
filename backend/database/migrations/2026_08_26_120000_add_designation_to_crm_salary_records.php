<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A salary revision can carry a position change: the record notes the new
 * designation (promotion letters are generated from this trail) and the
 * employee's current designation is moved with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_salary_records', function (Blueprint $table) {
            $table->string('designation', 64)->nullable()->after('effective_from');
        });
    }

    public function down(): void
    {
        Schema::table('crm_salary_records', function (Blueprint $table) {
            $table->dropColumn('designation');
        });
    }
};
