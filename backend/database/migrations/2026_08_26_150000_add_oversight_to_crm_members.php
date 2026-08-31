<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Super Admin's entered-workspace membership is oversight, not
 * employment: it grants full access but must never appear inside the
 * company as an employee — not in Users, dropdowns, payroll or counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_members', function (Blueprint $table) {
            $table->boolean('is_oversight')->default(false)->after('crm_role');
        });
    }

    public function down(): void
    {
        Schema::table('crm_members', function (Blueprint $table) {
            $table->dropColumn('is_oversight');
        });
    }
};
