<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settling money that has landed, and chasing money that has not.
 *
 * A claim no longer goes straight through: a company decides whether a
 * payment is settled on the spot or waits for an Admin to check it first, so
 * an inbox entry can now sit `pending` between the two. The document it is
 * claimed against may be a proforma — most money arrives against one — and
 * settling converts it to a tax invoice, which is worth remembering on the
 * entry itself rather than only in the trail.
 *
 * Reminders gain a flag for the ones nobody typed: the schedule sent them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_payment_inbox', function (Blueprint $table) {
            // auto = settled as it was claimed; manual = an Admin confirms.
            $table->string('settlement_mode', 16)->nullable()->after('status');
            $table->foreignId('settled_by')->nullable()->after('claimed_at')->constrained('users')->nullOnDelete();
            $table->dateTime('settled_at')->nullable()->after('settled_by');
            // The proforma this money came in against, once it has become an
            // invoice — so the paper trail survives the conversion.
            $table->foreignId('source_proforma_id')->nullable()->after('settled_at')
                ->constrained('crm_invoices')->nullOnDelete();
        });

        Schema::table('crm_payment_reminders', function (Blueprint $table) {
            $table->boolean('is_auto')->default(false)->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('crm_payment_reminders', function (Blueprint $table) {
            $table->dropColumn('is_auto');
        });
        Schema::table('crm_payment_inbox', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settled_by');
            $table->dropConstrainedForeignId('source_proforma_id');
            $table->dropColumn(['settlement_mode', 'settled_at']);
        });
    }
};
