<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chasing an unpaid invoice.
 *
 * Every reminder is kept — what was said, to whom, by whom, and what was
 * outstanding at the time — so a client cannot be chased twice on the same
 * day by two people, and so "we did write to you on the 3rd" is a fact rather
 * than a memory. A row with no e-mail is a note: someone rang, and set a date
 * to look again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_payment_reminders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->string('channel', 16)->default('email');   // email | note
            $table->string('to_email')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('status', 16)->default('sent');     // sent | failed | logged
            $table->string('error', 512)->nullable();
            // What was still owed when this went out, so the trail reads true
            // even after the money arrives.
            $table->decimal('balance', 14, 2)->default(0);
            $table->date('next_follow_up')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'invoice_id']);
            $table->index('next_follow_up');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_payment_reminders');
    }
};
