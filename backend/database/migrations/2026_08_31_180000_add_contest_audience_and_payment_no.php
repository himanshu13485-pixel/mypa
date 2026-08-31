<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions:
 *  - A contest can be aimed: at everyone (default), at one department, or
 *    at one named employee.
 *  - Every payment receipt gets a unique payment id (PAY-000123) — the
 *    handle that ties a bank-statement line to its invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contests', function (Blueprint $table) {
            $table->string('audience_department', 64)->nullable()->after('status');
            $table->foreignId('audience_member_id')->nullable()->after('audience_department')
                ->constrained('crm_members')->nullOnDelete();
        });

        Schema::table('crm_invoice_payments', function (Blueprint $table) {
            $table->string('payment_no', 24)->nullable()->unique()->after('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_contests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('audience_member_id');
            $table->dropColumn('audience_department');
        });
        Schema::table('crm_invoice_payments', function (Blueprint $table) {
            $table->dropColumn('payment_no');
        });
    }
};
