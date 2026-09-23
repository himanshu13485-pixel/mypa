<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shared mailbox, as each person's own.
 *
 * sales@ is one mailbox on one server, and everybody on it sees the same
 * mail arrive and the same replies go out - that is the point of a shared
 * desk. But two things are personal even there:
 *
 *   the signature, because a reply from sales@ should be signed by whoever
 *   actually wrote it;
 *
 *   what is half-written - drafts, the outbox, and anything scheduled -
 *   because an unfinished sentence belongs to the person writing it, and
 *   nobody should be able to send, edit or cancel it on their behalf.
 *
 * So the pivot carries each person's own signature and their own default,
 * and every outgoing message remembers who wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_mail_account_member', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_mail_account_member', 'signature_html')) {
                $table->text('signature_html')->nullable()->after('can_send');
                // Their own choice of which mailbox they write from by default.
                $table->boolean('is_default')->default(false)->after('signature_html');
            }
        });

        Schema::table('crm_mail_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_mail_messages', 'author_member_id')) {
                $table->unsignedBigInteger('author_member_id')->nullable()->after('mail_account_id');
                $table->index(['mail_account_id', 'author_member_id'], 'crm_mail_author');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_mail_account_member', function (Blueprint $table) {
            foreach (['signature_html', 'is_default'] as $column) {
                if (Schema::hasColumn('crm_mail_account_member', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('crm_mail_messages', function (Blueprint $table) {
            if (Schema::hasColumn('crm_mail_messages', 'author_member_id')) {
                $table->dropIndex('crm_mail_author');
                $table->dropColumn('author_member_id');
            }
        });
    }
};
