<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The issuing company grows up: a logo that prints on its paperwork, a
 * billing currency (INR unless chosen otherwise), and the tick that names
 * which registered company pays the salaries — the payslip then carries
 * that company's details and logo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_issuing_companies', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('email');
            $table->char('currency', 3)->default('INR')->after('logo_path');
            $table->boolean('pays_salary')->default(false)->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('crm_issuing_companies', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'currency', 'pays_salary']);
        });
    }
};
