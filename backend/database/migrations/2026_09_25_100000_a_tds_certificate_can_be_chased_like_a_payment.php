<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chasing the certificate for tax somebody else deducted.
 *
 * It is the same act as chasing a payment - an e-mail or a phone call about
 * one invoice, recorded so that two people do not chase the same client on
 * the same morning - so it is the same table, told apart by `kind`. Every
 * row that exists today is a payment chase, which is what the default says.
 *
 * The invoice remembers when the certificate arrived, because a chase list
 * that never shrinks is a chase list nobody reads twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_payment_reminders', function (Blueprint $table) {
            $table->string('kind', 16)->default('payment')->after('member_id'); // payment | tds
        });

        Schema::table('crm_invoices', function (Blueprint $table) {
            $table->dateTime('tds_certificate_at')->nullable()->after('tds');
            $table->foreignId('tds_certificate_by')->nullable()->after('tds_certificate_at')
                ->constrained('crm_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tds_certificate_by');
            $table->dropColumn('tds_certificate_at');
        });

        Schema::table('crm_payment_reminders', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
