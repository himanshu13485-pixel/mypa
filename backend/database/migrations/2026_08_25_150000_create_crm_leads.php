<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead Generation. The Lead Log needs no table of its own — every change is
 * recorded in crm_activity_logs (the shared trail), and the Lead Log screen
 * is a filtered view over it, exactly as planned for all "…Log" modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            // The human-facing lead number the old CRM lived by ("Lead ID
            // 50558") — sequential per organization.
            $table->unsignedInteger('lead_no');
            $table->foreignId('assigned_member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->string('company_name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('mobile', 32)->nullable();
            $table->string('email')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            // follow_up | not_interested | unattended | closed | transferred
            $table->string('lead_status', 32)->default('unattended');
            $table->dateTime('follow_up_at')->nullable();
            $table->string('subject', 128)->nullable();
            $table->text('requirement')->nullable();
            $table->string('lead_type', 16)->default('new'); // new | existing
            $table->string('source', 64)->nullable();
            // Set when the lead becomes (or matches) a billable client.
            $table->foreignId('client_id')->nullable()->constrained('crm_clients')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'lead_no']);
            $table->index(['organization_id', 'lead_status']);
            $table->index(['organization_id', 'follow_up_at']);
            $table->index(['organization_id', 'assigned_member_id']);
            $table->index(['organization_id', 'company_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_leads');
    }
};
