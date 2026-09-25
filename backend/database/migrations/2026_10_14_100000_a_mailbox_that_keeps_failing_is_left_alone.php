<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mailbox that cannot be reached is asked less often.
 *
 * Sync runs every five minutes, per mailbox, whatever happened last time.
 * A mailbox with the wrong password answers "authentication failed" 288
 * times a day, and one whose server has gone gets there by timing out -
 * which costs a slot on the queue every time, for a mailbox that is not
 * going to work until a person changes something.
 *
 * So consecutive failures are counted, and after a few the mailbox is left
 * alone for a while: a quarter of an hour, then an hour, then six. One
 * success, or somebody pressing Refresh, and the count goes back to nought.
 * Nothing is switched off and nothing is hidden - the mailbox keeps its
 * last_error for the person to read, and keeps trying, just not constantly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_mail_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_mail_accounts', 'sync_failures')) {
                $table->unsignedSmallInteger('sync_failures')->default(0)->after('last_error');
            }
            if (! Schema::hasColumn('crm_mail_accounts', 'sync_paused_until')) {
                $table->timestamp('sync_paused_until')->nullable()->after('sync_failures');
            }
        });
    }

    /**
     * Only the resting goes back.
     *
     * `sync_failures` belongs to the migration beside this one, which
     * counts failures so a mailbox does not go red over one bad minute.
     * The two arrived the same day and share the column on purpose - the
     * pass that first says PROBLEM is the pass that first lets it rest -
     * so rolling this one back must not take the count with it.
     */
    public function down(): void
    {
        Schema::table('crm_mail_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('crm_mail_accounts', 'sync_paused_until')) {
                $table->dropColumn('sync_paused_until');
            }
        });
    }
};
