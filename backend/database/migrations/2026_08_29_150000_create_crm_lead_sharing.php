<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead Duplication.
 *
 * A lead is a person being courted, and that person has one mobile, one
 * phone, one e-mail: any of the three matching an existing lead means it IS
 * that lead, and courting them twice from two desks embarrasses everyone.
 * So a duplicate is never a second row — it is a request on the original:
 * the Admin shares it, transfers it, or rejects the ask.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->foreignId('shared_by')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->timestamps();
            $table->unique(['lead_id', 'member_id']);
        });

        Schema::create('crm_lead_access_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->string('note', 512)->nullable();
            // pending | shared | transferred | rejected — the decision IS the
            // outcome, so one column tells the whole story.
            $table->string('status', 16)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 512)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_access_requests');
        Schema::dropIfExists('crm_lead_shares');
    }
};
