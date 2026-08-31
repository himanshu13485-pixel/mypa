<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the document, made a company's own.
 *
 * The Work Order lines already follow each company's method; this extends the
 * same DCW machinery to the document's own fields and to its money lines:
 *
 *  - `crm_invoices.custom_fields` holds the extra header fields a company
 *    asked for, exactly as clients and Work Order lines already do.
 *  - `crm_custom_fields` gains the three attributes a tax line needs beyond a
 *    plain field, so a company's tax setup travels through the same approval
 *    queue instead of a second one.
 *  - `crm_invoice_taxes` records what each money line actually came to on a
 *    given document — a company may have five taxes or none, so they cannot
 *    live in fixed columns. The standard six keep their columns as well, so
 *    everything already reading `cgst` keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_invoices', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('notes');
        });

        Schema::table('crm_custom_fields', function (Blueprint $table) {
            // Tax lines only: discount | tax | deduction, and what it is
            // charged on (the subtotal, or the subtotal less discounts).
            $table->string('tax_kind', 16)->nullable()->after('type');
            $table->string('tax_basis', 16)->nullable()->after('tax_kind');
            $table->decimal('default_rate', 6, 3)->nullable()->after('tax_basis');
        });

        Schema::create('crm_invoice_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->string('key', 64);
            // Snapshots: a document keeps the wording it was raised with even
            // if the company renames the line later.
            $table->string('label', 128);
            $table->string('kind', 16)->default('tax');
            $table->string('basis', 16)->default('taxable');
            $table->decimal('rate', 6, 3)->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['invoice_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_invoice_taxes');
        Schema::table('crm_custom_fields', function (Blueprint $table) {
            $table->dropColumn(['tax_kind', 'tax_basis', 'default_rate']);
        });
        Schema::table('crm_invoices', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
    }
};
