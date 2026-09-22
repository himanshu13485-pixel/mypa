<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mails: a mail client inside the CRM.
 *
 * Three doors, each opened by the one above it. The platform switches Mails
 * on for a company and says how many mailboxes any one person there may
 * hold; the company's admin decides who among its people gets Mails, and
 * how many mailboxes each of them may add, within that; and each person
 * adds and reads their own. Nobody's mail is anybody else's - a mailbox
 * belongs to the member who added it.
 *
 * The messages are kept here as well as on the mail server, so the list
 * opens at once and search works across folders; the server stays the
 * source of truth for what arrived, and flags and moves are written back
 * to it. Attachments are not copied - they are fetched from the server
 * when somebody opens one.
 *
 * Guarded throughout, because MySQL cannot roll back half a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // What the platform grants a company - on the company row, where the
        // company cannot widen it for itself (as with impersonation_level).
        Schema::table('crm_organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_organizations', 'mails_enabled')) {
                $table->boolean('mails_enabled')->default(false);
            }
            if (! Schema::hasColumn('crm_organizations', 'mails_mailbox_cap')) {
                $table->unsignedTinyInteger('mails_mailbox_cap')->default(3);
            }
        });

        // What the company grants a person, beyond the Mails right itself.
        Schema::table('crm_members', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_members', 'mail_mailbox_limit')) {
                $table->unsignedTinyInteger('mail_mailbox_limit')->nullable();
            }
            if (! Schema::hasColumn('crm_members', 'mail_prefs')) {
                $table->json('mail_prefs')->nullable();
            }
        });

        if (! Schema::hasTable('crm_mail_accounts')) {
            Schema::create('crm_mail_accounts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
                $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
                $table->string('label', 120)->nullable();
                $table->string('email', 255);
                $table->string('from_name', 120)->nullable();
                $table->string('reply_to', 255)->nullable();
                $table->string('provider', 32)->default('custom');
                // Reading: IMAP.
                $table->string('imap_host', 255)->nullable();
                $table->unsignedSmallInteger('imap_port')->default(993);
                $table->string('imap_encryption', 8)->default('ssl');
                $table->string('imap_username', 255)->nullable();
                $table->text('imap_password')->nullable();
                // Sending: SMTP.
                $table->string('smtp_host', 255)->nullable();
                $table->unsignedSmallInteger('smtp_port')->default(587);
                $table->string('smtp_encryption', 8)->default('tls');
                $table->string('smtp_username', 255)->nullable();
                $table->text('smtp_password')->nullable();
                $table->text('signature_html')->nullable();
                $table->json('auto_reply')->nullable();
                $table->string('forward_to', 255)->nullable();
                $table->boolean('is_default')->default(false);
                $table->json('sync_state')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->text('last_error')->nullable();
                $table->string('status', 16)->default('active');
                $table->timestamps();
                $table->index(['member_id', 'status']);
            });
        }

        if (! Schema::hasTable('crm_mail_messages')) {
            Schema::create('crm_mail_messages', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
                $table->foreignId('mail_account_id')->constrained('crm_mail_accounts')->cascadeOnDelete();
                // inbox, sent, drafts, spam, trash, outbox, scheduled, archive
                $table->string('folder', 16);
                // Where it lives on the server, when it lives there at all.
                $table->string('remote_folder', 255)->nullable();
                $table->unsignedBigInteger('uid')->nullable();
                $table->string('message_id', 512)->nullable();
                $table->string('in_reply_to', 512)->nullable();
                // "references" is a reserved word in MySQL.
                $table->text('reference_ids')->nullable();
                $table->string('thread_key', 64);
                $table->string('from_name', 255)->nullable();
                $table->string('from_email', 255)->nullable();
                $table->json('to')->nullable();
                $table->json('cc')->nullable();
                $table->json('bcc')->nullable();
                $table->string('reply_to', 255)->nullable();
                $table->text('subject')->nullable();
                $table->string('snippet', 255)->nullable();
                $table->longText('body_html')->nullable();
                $table->longText('body_text')->nullable();
                $table->boolean('has_attachments')->default(false);
                $table->boolean('is_read')->default(false);
                $table->boolean('is_starred')->default(false);
                $table->timestamp('date')->nullable();
                // Going out: when, whether it can still be stopped, and how it went.
                $table->timestamp('scheduled_for')->nullable();
                $table->timestamp('send_after')->nullable();
                $table->string('status', 16)->nullable();
                $table->text('error')->nullable();
                $table->unsignedInteger('size')->nullable();
                // Where a trashed message came from, so Restore puts it back.
                $table->string('trashed_from', 16)->nullable();
                $table->timestamps();

                $table->index(['mail_account_id', 'folder', 'date']);
                $table->index(['mail_account_id', 'thread_key']);
                $table->index(['folder', 'status', 'send_after']);
                $table->unique(['mail_account_id', 'remote_folder', 'uid'], 'crm_mail_messages_remote');
            });
        }

        if (! Schema::hasTable('crm_mail_attachments')) {
            Schema::create('crm_mail_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mail_message_id')->constrained('crm_mail_messages')->cascadeOnDelete();
                $table->string('filename', 255);
                $table->string('mime', 128)->nullable();
                $table->unsignedInteger('size')->nullable();
                $table->string('content_id', 255)->nullable();
                $table->boolean('is_inline')->default(false);
                // Which part of the server copy it is, for fetching on demand.
                $table->string('part', 64)->nullable();
                // Or a local file, for what somebody attached to a message going out.
                $table->string('path', 512)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('crm_mail_labels')) {
            Schema::create('crm_mail_labels', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
                $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
                $table->string('name', 64);
                $table->string('color', 16)->default('#64748b');
                $table->timestamps();
                $table->unique(['member_id', 'name']);
            });
        }

        if (! Schema::hasTable('crm_mail_label_message')) {
            Schema::create('crm_mail_label_message', function (Blueprint $table) {
                $table->foreignId('mail_label_id')->constrained('crm_mail_labels')->cascadeOnDelete();
                $table->foreignId('mail_message_id')->constrained('crm_mail_messages')->cascadeOnDelete();
                $table->primary(['mail_label_id', 'mail_message_id']);
            });
        }

        // An away message answers each sender once, not once per email.
        if (! Schema::hasTable('crm_mail_auto_replies')) {
            Schema::create('crm_mail_auto_replies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mail_account_id')->constrained('crm_mail_accounts')->cascadeOnDelete();
                $table->string('email', 255);
                $table->timestamp('last_sent_at');
                $table->unique(['mail_account_id', 'email']);
            });
        }
    }

    public function down(): void
    {
        foreach (['crm_mail_auto_replies', 'crm_mail_label_message', 'crm_mail_labels', 'crm_mail_attachments', 'crm_mail_messages', 'crm_mail_accounts'] as $name) {
            Schema::dropIfExists($name);
        }

        Schema::table('crm_members', function (Blueprint $table) {
            foreach (['mail_mailbox_limit', 'mail_prefs'] as $column) {
                if (Schema::hasColumn('crm_members', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('crm_organizations', function (Blueprint $table) {
            foreach (['mails_enabled', 'mails_mailbox_cap'] as $column) {
                if (Schema::hasColumn('crm_organizations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
