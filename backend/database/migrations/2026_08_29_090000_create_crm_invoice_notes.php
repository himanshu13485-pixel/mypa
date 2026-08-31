<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation behind a document.
 *
 * "Client asked to hold dispatch till the 5th", "part payment promised after
 * Diwali" — things the office must remember about an invoice that the client
 * must never read on it. These notes live beside the document, not on it:
 * they are absent from the print, the PDF and every client-facing surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_invoice_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['invoice_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_invoice_notes');
    }
};
