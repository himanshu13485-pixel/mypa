<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two small columns with one idea each.
 *
 * A commission paid to a client is an EXPENSE tied to a sale — never a line
 * on the invoice, which is a tax document. The tie is this foreign key: the
 * Commissions screen is a lens over expenses that carry it.
 *
 * And a document raised by a schedule should say so to the office even when
 * the client-facing note was switched off — the schedule link is the fact,
 * the note is the choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_expenses', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('issuing_company_id')
                ->constrained('crm_invoices')->nullOnDelete();
        });

        Schema::table('crm_invoices', function (Blueprint $table) {
            $table->foreignId('recurring_invoice_id')->nullable()->after('recurring_note')
                ->constrained('crm_recurring_invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_invoice_id');
        });
        Schema::table('crm_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
