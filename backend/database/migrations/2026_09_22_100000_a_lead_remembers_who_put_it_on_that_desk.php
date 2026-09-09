<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who allocated a lead, beside who it is allocated to.
 *
 * The list showed whose desk a lead sits on and nothing about how it got
 * there — and "who gave me this" is the first question asked about a lead
 * that should never have been handed over, or that arrived without a note.
 * The trail was in the activity log, which is not where anybody scanning a
 * list of twenty-five is going to look.
 *
 * It is one name, not two: the person who created the lead, until somebody
 * transfers it, and then whoever did that. Leads already here are given the
 * same answer out of the log they already wrote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->foreignId('assigned_by')->nullable()->after('assigned_member_id')
                ->constrained('users')->nullOnDelete();
        });

        // Whoever entered it, unless a transfer says otherwise.
        DB::table('crm_leads')->update(['assigned_by' => DB::raw('created_by')]);

        // Ordered oldest first, so the last write for a lead is its latest
        // transfer — the hand it is in now.
        $transfers = DB::table('crm_activity_logs')
            ->join('crm_members', 'crm_members.id', '=', 'crm_activity_logs.member_id')
            ->where('crm_activity_logs.action', 'lead.transferred')
            ->where('crm_activity_logs.subject_type', \App\Models\Crm\Lead::class)
            ->orderBy('crm_activity_logs.id')
            ->pluck('crm_members.user_id', 'crm_activity_logs.subject_id');

        foreach ($transfers as $leadId => $userId) {
            DB::table('crm_leads')->where('id', $leadId)->update(['assigned_by' => $userId]);
        }
    }

    public function down(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by');
        });
    }
};
