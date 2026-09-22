<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mailboxes grow up.
 *
 * A mailbox stops being one person's private connection: the Company Admin
 * can create one and hand it to several people at once (a shared sales or
 * support box), it carries what the world can check about it - SPF, DKIM,
 * DMARC and a score - it can be held to a daily sending limit, and it can
 * be disconnected without taking its mail with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_mail_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_mail_accounts', 'created_by_member_id')) {
                // Who set it up, when that was not the person who reads it.
                $table->unsignedBigInteger('created_by_member_id')->nullable()->after('member_id');
            }
            if (! Schema::hasColumn('crm_mail_accounts', 'is_shared')) {
                $table->boolean('is_shared')->default(false)->after('created_by_member_id');
            }
            if (! Schema::hasColumn('crm_mail_accounts', 'tag')) {
                // A short badge the Admin writes: "Reports", "Support desk".
                $table->string('tag', 60)->nullable()->after('label');
            }
            if (! Schema::hasColumn('crm_mail_accounts', 'daily_cap')) {
                $table->unsignedInteger('daily_cap')->nullable()->after('smtp_password');
                $table->unsignedInteger('sent_today')->default(0)->after('daily_cap');
                $table->date('cap_date')->nullable()->after('sent_today');
            }
            if (! Schema::hasColumn('crm_mail_accounts', 'dkim_selector')) {
                $table->string('dkim_selector', 120)->nullable()->after('cap_date');
                // What the last DNS check found: spf, dkim, dmarc, score, when.
                $table->json('dns')->nullable()->after('dkim_selector');
            }
            if (! Schema::hasColumn('crm_mail_accounts', 'detached_at')) {
                // Disconnected: the mail stays and stays readable, the
                // credentials are gone, and a new account can take its place.
                $table->timestamp('detached_at')->nullable()->after('last_error');
            }
        });

        // Who else reads a shared mailbox. The owner is on the account row;
        // this is everybody the Admin let in beside them.
        if (! Schema::hasTable('crm_mail_account_member')) {
            Schema::create('crm_mail_account_member', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mail_account_id')->constrained('crm_mail_accounts')->cascadeOnDelete();
                $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
                $table->boolean('can_send')->default(true);
                $table->timestamps();
                $table->unique(['mail_account_id', 'member_id'], 'crm_mail_share_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_mail_account_member');

        Schema::table('crm_mail_accounts', function (Blueprint $table) {
            foreach (['created_by_member_id', 'is_shared', 'tag', 'daily_cap', 'sent_today', 'cap_date', 'dkim_selector', 'dns', 'detached_at'] as $column) {
                if (Schema::hasColumn('crm_mail_accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
