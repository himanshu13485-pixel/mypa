<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One quota, three places it can be moved.
 *
 * A person's plan says how much room they have. Mail was counting its own
 * megabytes in a corner, unaware of it - so somebody on the 20 GB plan had
 * 20 GB for files and some other number for mail, which is two answers to
 * one question.
 *
 * Now it is one number, and the platform can raise or lower it for:
 *
 *   one person          users.storage_override_bytes
 *   one company's CRM   crm_organizations.mails_storage_gb (per person there)
 *   one employee        crm_members.mail_storage_mb
 *
 * Null everywhere means "whatever their plan says", so nothing is decided
 * twice and an override is always a deliberate act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'storage_override_bytes')) {
                $table->unsignedBigInteger('storage_override_bytes')->nullable()->after('status');
                // Why it was changed, and by whom - a number nobody can
                // explain is a number nobody dares change back.
                $table->string('storage_override_note', 255)->nullable()->after('storage_override_bytes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['storage_override_bytes', 'storage_override_note'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
