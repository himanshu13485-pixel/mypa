<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much room a mailbox has, and who decides.
 *
 * The platform sets the ceiling for a company - so many gigabytes per
 * person, or none at all, which means unlimited. The Company Admin shares
 * that out among their people, never past the ceiling. What is actually
 * used is measured from the mail itself rather than stored, so it cannot
 * drift away from the truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_organizations', 'mails_storage_gb')) {
                // Null is unlimited - the platform's ceiling per person.
                $table->decimal('mails_storage_gb', 8, 2)->nullable()->after('mails_mailbox_cap');
            }
        });

        Schema::table('crm_members', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_members', 'mail_storage_mb')) {
                // What this person was given, in megabytes. Null follows the
                // company's ceiling.
                $table->unsignedInteger('mail_storage_mb')->nullable()->after('mail_mailbox_limit');
            }
        });

        Schema::table('crm_mail_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_mail_messages', 'spam_score')) {
                // What the checks made of it, and why - kept so a message in
                // Spam can explain itself rather than just being missing.
                $table->unsignedTinyInteger('spam_score')->default(0)->after('status');
                $table->json('spam_reasons')->nullable()->after('spam_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_organizations', function (Blueprint $table) {
            if (Schema::hasColumn('crm_organizations', 'mails_storage_gb')) {
                $table->dropColumn('mails_storage_gb');
            }
        });
        Schema::table('crm_members', function (Blueprint $table) {
            if (Schema::hasColumn('crm_members', 'mail_storage_mb')) {
                $table->dropColumn('mail_storage_mb');
            }
        });
        Schema::table('crm_mail_messages', function (Blueprint $table) {
            foreach (['spam_score', 'spam_reasons'] as $column) {
                if (Schema::hasColumn('crm_mail_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
