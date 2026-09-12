<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a report lands.
 *
 * Every report went to Netvork's queue, including one employee reporting
 * another for spamming the office group - which Netvork can do nothing
 * sensible about, and the company that employs both of them was never told.
 *
 * A report between two people in the same company now carries that company,
 * so its Admin sees it and deals with it first. Netvork still sees every
 * report, company or not: the platform is responsible for the platform, and
 * a company that never looks at its queue must not be a place abuse can hide.
 *
 * Escalation is how a company hands one up: suspending an account is not a
 * company's power, because the account is not the company's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('message_id')
                ->constrained('crm_organizations')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable()->after('reviewed_at');
            $table->foreignId('escalated_by')->nullable()->after('escalated_at')
                ->constrained('users')->nullOnDelete();
            $table->string('escalation_note', 500)->nullable()->after('escalated_by');
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropConstrainedForeignId('escalated_by');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['escalated_at', 'escalation_note']);
        });
    }
};
