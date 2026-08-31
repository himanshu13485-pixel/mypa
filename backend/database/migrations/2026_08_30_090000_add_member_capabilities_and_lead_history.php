<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two ideas that keep arriving together: who may do the delicate things, and
 * what happens when a closed lead comes back.
 *
 *  - `crm_members.capabilities`: the acts that were "Admin only" because they
 *    move money or ownership — transferring a lead, deleting a client,
 *    settling a payment. A company may want one senior employee to hold one
 *    of them, so the Admin grants them by name, account by account.
 *  - Leads remember being closed and reopened; clients remember coming back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_members', function (Blueprint $table) {
            $table->json('capabilities')->nullable()->after('rights');
        });

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->unsignedSmallInteger('reopen_count')->default(0)->after('lead_status');
            $table->dateTime('closed_at')->nullable()->after('reopen_count');
        });

        Schema::table('crm_clients', function (Blueprint $table) {
            // A client who came back: the count is the story, the flag is the
            // badge every screen can read without arithmetic.
            $table->boolean('is_repeat')->default(false)->after('category');
            $table->unsignedSmallInteger('repeat_count')->default(0)->after('is_repeat');
        });
    }

    public function down(): void
    {
        Schema::table('crm_clients', function (Blueprint $table) {
            $table->dropColumn(['is_repeat', 'repeat_count']);
        });
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropColumn(['reopen_count', 'closed_at']);
        });
        Schema::table('crm_members', function (Blueprint $table) {
            $table->dropColumn('capabilities');
        });
    }
};
