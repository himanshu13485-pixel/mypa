<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Withdrawing a Team Workspace grant is a dated event, not a deletion: the
 * months the leader's team incentive already released stay on their ledger
 * (marked "access withdrawn"), only the upcoming months stop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_team_access', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('member_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_team_access', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
    }
};
