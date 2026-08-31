<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax on a bill, entered as a rate.
 *
 * A supplier's bill quotes "9% CGST", not "450" — so the rate is what gets
 * typed and the amount is arithmetic, the same way an invoice already works.
 * The amount is still stored, because a bill occasionally rounds its own way
 * and the register must agree with the paper, not with the calculator.
 *
 * The fourth line is whatever else a bill carries — a cess, a levy, a
 * municipal charge — so it brings its own name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_expenses', function (Blueprint $table) {
            $table->decimal('cgst_rate', 6, 3)->nullable()->after('cgst_amount');
            $table->decimal('sgst_rate', 6, 3)->nullable()->after('sgst_amount');
            $table->decimal('igst_rate', 6, 3)->nullable()->after('igst_amount');
            $table->string('other_tax_label', 64)->nullable()->after('igst_rate');
            $table->decimal('other_tax_rate', 6, 3)->nullable()->after('other_tax_label');
            $table->decimal('other_tax_amount', 14, 2)->default(0)->after('other_tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('crm_expenses', function (Blueprint $table) {
            $table->dropColumn([
                'cgst_rate', 'sgst_rate', 'igst_rate',
                'other_tax_label', 'other_tax_rate', 'other_tax_amount',
            ]);
        });
    }
};
