<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for the mail to be, besides the database.
 *
 * Every mailbox gets a folder of its own on the server, holding one .eml
 * file per message - the ordinary format every mail program on earth reads -
 * with an index beside them. From there a copy can be pushed to whatever
 * else the company trusts, and every run is written down: what was copied,
 * where to, how much of it, and what went wrong if anything did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_mail_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_mail_accounts', 'backup')) {
                // Where this mailbox is copied to, and the keys to get there -
                // encrypted whole, because cloud keys live in here.
                $table->text('backup')->nullable()->after('dns');
            }
        });

        if (! Schema::hasTable('crm_mail_backup_runs')) {
            Schema::create('crm_mail_backup_runs', function (Blueprint $table) {
                $table->id();
                $table->uuid()->unique();
                $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
                $table->foreignId('mail_account_id')->nullable()->constrained('crm_mail_accounts')->nullOnDelete();
                $table->unsignedBigInteger('member_id')->nullable();
                // backup, export, import - what somebody asked for.
                $table->string('kind', 20);
                // server, s3, webdav, gdrive, local - where it went.
                $table->string('destination', 20)->default('server');
                $table->string('status', 20)->default('running');
                $table->unsignedInteger('messages')->default(0);
                $table->unsignedBigInteger('bytes')->default(0);
                $table->string('path', 500)->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['organization_id', 'mail_account_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_mail_backup_runs');

        Schema::table('crm_mail_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('crm_mail_accounts', 'backup')) {
                $table->dropColumn('backup');
            }
        });
    }
};
