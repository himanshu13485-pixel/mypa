<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Team Workspace: the Admin ticks, person by person, who a team leader
 * handles. Everyone is under the company (Admin) by default — a row here is
 * an explicit grant, and it widens the leader's window (employees, sales,
 * team incentive) to the people named.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_team_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leader_id')->constrained('crm_members')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['leader_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_team_access');
    }
};
