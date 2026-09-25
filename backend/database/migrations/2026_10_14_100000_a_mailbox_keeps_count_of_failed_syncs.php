<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many times in a row a mailbox has failed to sync.
 *
 * A single failed sync was enough to put PROBLEM on a mailbox and a red line
 * under it. Most of what a sync trips over is weather: a name that does not
 * resolve for ten seconds, a port that times out once, a host that drops the
 * connection mid-fetch. The mailbox is fine, and the next pass proves it.
 *
 * Counting them lets the difference be told. A refused sign-in is announced
 * at once, because it will not fix itself; a blip is only announced once it
 * has happened enough times running to stop being a blip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_mail_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('crm_mail_accounts', 'sync_failures')) {
                return;
            }

            $table->unsignedSmallInteger('sync_failures')->default(0)->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('crm_mail_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('crm_mail_accounts', 'sync_failures')) {
                $table->dropColumn('sync_failures');
            }
        });
    }
};
