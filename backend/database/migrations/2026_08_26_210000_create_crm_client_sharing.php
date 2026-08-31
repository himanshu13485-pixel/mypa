<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client portfolios.
 *
 * A client belongs to the person who brought it in; colleagues see it only
 * when the client is shared with them. When somebody tries to add a client
 * that already exists, the answer is not a duplicate row — it is a request
 * to the company's admin to be let in on the existing one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_client_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('crm_clients')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->foreignId('shared_by')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'member_id']);
        });

        Schema::create('crm_client_access_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('crm_clients')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->string('note', 512)->nullable();
            $table->string('status', 16)->default('pending'); // pending|approved|rejected
            $table->foreignId('decided_by')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 512)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['client_id', 'member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_client_access_requests');
        Schema::dropIfExists('crm_client_shares');
    }
};
