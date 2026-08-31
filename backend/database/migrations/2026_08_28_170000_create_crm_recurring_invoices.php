<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing that repeats.
 *
 * A subscription is an existing document told to happen again: the schedule
 * points at the document it copies, and each cycle raises a fresh one in the
 * same series — items, taxes and Work Order fields carried over, dated the
 * day it runs. The copy is the template, so editing next month's wording
 * means editing the source document, not a second form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            // The document each cycle copies. Deleting it ends the schedule.
            $table->foreignId('source_invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('crm_clients')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            // weekly | monthly | quarterly | half_yearly | yearly
            $table->string('frequency', 16)->default('monthly');
            $table->date('starts_on');
            $table->date('next_run_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('max_occurrences')->nullable();
            $table->unsignedSmallInteger('occurrences')->default(0);
            // What each run also does, beyond raising the document.
            $table->boolean('auto_email')->default(false);
            $table->boolean('auto_payment_link')->default(false);
            // active | paused | completed | cancelled
            $table->string('status', 16)->default('active');
            $table->foreignId('last_invoice_id')->nullable()->constrained('crm_invoices')->nullOnDelete();
            $table->dateTime('last_run_at')->nullable();
            $table->string('last_error', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'next_run_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_recurring_invoices');
    }
};
