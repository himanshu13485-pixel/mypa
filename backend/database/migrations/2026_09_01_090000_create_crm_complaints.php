<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Complaint Management System.
 *
 * A complaint is a promise to a client with a clock on it, so the record
 * holds four things the old sheet could not: who it belongs to right now,
 * when it must be answered by, what was actually said — to the client and
 * inside the office, kept apart — and, at the end, whose mistake it was.
 *
 * The two conversations live in one table separated by audience. Anything
 * marked `client` is what the client is told; `internal` is the office
 * talking among itself and never leaves the building.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_complaints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            // The number the office quotes on the phone.
            $table->string('cms_no', 32);
            $table->date('complained_on');

            // Who complained. The client register is the source of truth, but
            // a complaint can arrive before anyone is registered, so the name
            // is snapshotted either way.
            $table->foreignId('client_id')->nullable()->constrained('crm_clients')->nullOnDelete();
            $table->string('company_name');
            $table->string('contact_person')->nullable();
            $table->string('mobile', 64)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('alt_contact_person')->nullable();
            $table->string('alt_mobile', 64)->nullable();
            $table->string('alt_phone', 64)->nullable();
            $table->string('alt_email')->nullable();

            // What it is about.
            $table->foreignId('invoice_id')->nullable()->constrained('crm_invoices')->nullOnDelete();
            $table->string('source', 96)->nullable();
            $table->string('subject', 191)->nullable();
            $table->string('complaint_type', 96)->nullable();
            $table->string('mode', 64)->nullable();
            $table->text('details')->nullable();

            // Whose desk it is on.
            $table->foreignId('raised_by_member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->foreignId('allocated_by_member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->foreignId('allocated_to_member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->foreignId('key_responsible_member_id')->nullable()->constrained('crm_members')->nullOnDelete();

            // The clock.
            $table->string('status', 32)->default('unattended');
            $table->string('priority', 16)->default('normal');
            $table->dateTime('due_at')->nullable();
            $table->dateTime('in_progress_at')->nullable();
            $table->dateTime('first_response_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();

            // Whose mistake it was — the type, and the person behind it.
            $table->string('final_error_type', 32)->nullable();
            $table->foreignId('final_error_member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->string('final_error_note', 512)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'cms_no']);
            $table->index(['organization_id', 'status', 'complained_on']);
            $table->index(['organization_id', 'allocated_to_member_id']);
        });

        Schema::create('crm_complaint_replies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('complaint_id')->constrained('crm_complaints')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            // 'client' is what the client is told; 'internal' never leaves.
            $table->string('audience', 16)->default('internal');
            $table->text('body');
            $table->timestamps();
            $table->index(['complaint_id', 'audience', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_complaint_replies');
        Schema::dropIfExists('crm_complaints');
    }
};
