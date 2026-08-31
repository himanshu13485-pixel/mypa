<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the invoice form was missing.
 *
 * 1. Tax by percentage. Everyone quotes GST as "9% + 9%", not as rupees, so
 *    each money line keeps an optional rate beside its amount. A rate, when
 *    given, is what the amount is computed from; a bare amount still works,
 *    which is what every existing document has.
 *
 * 2. A company's own Work Order columns. The DCW table already carries the
 *    extra fields a company asked for; these two flags let the same approval
 *    flow rename, re-type or hide a BUILT-IN column (Membership, Plan name,
 *    Description, Validity…) so a company can word its work order — and list
 *    its own products as a dropdown — instead of using ours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_invoices', function (Blueprint $table) {
            // decimal(6,3): 18.5%, and 100.000% is the ceiling.
            $table->decimal('discount_rate', 6, 3)->nullable()->after('discount');
            $table->decimal('cgst_rate', 6, 3)->nullable()->after('cgst');
            $table->decimal('sgst_rate', 6, 3)->nullable()->after('sgst');
            $table->decimal('igst_rate', 6, 3)->nullable()->after('igst');
            $table->decimal('other_tax_rate', 6, 3)->nullable()->after('other_tax');
            $table->decimal('tds_rate', 6, 3)->nullable()->after('tds');
        });

        Schema::table('crm_custom_fields', function (Blueprint $table) {
            // This row customises one of our built-in columns rather than
            // adding a new one; `key` then names the built-in.
            $table->boolean('is_builtin')->default(false)->after('entity');
            // Approved, but the company does not want the column at all.
            $table->boolean('is_hidden')->default(false)->after('is_required');
        });
    }

    public function down(): void
    {
        Schema::table('crm_invoices', function (Blueprint $table) {
            $table->dropColumn(['discount_rate', 'cgst_rate', 'sgst_rate', 'igst_rate', 'other_tax_rate', 'tds_rate']);
        });
        Schema::table('crm_custom_fields', function (Blueprint $table) {
            $table->dropColumn(['is_builtin', 'is_hidden']);
        });
    }
};
