<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chats kept behind a password, and chats kept out of sight.
 *
 * One password per person, not per chat: it opens every chat that person
 * has locked and the folder of the ones they have hidden, which is how the
 * messengers people already use behave - and a code per conversation is a
 * code nobody remembers by the fourth.
 *
 * Locked and hidden live on the membership, because they are one person's
 * arrangement of their own list. The other people in the chat see nothing
 * change, and are not told.
 *
 * Guarded, because MySQL cannot roll back half a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'chat_lock_hash')) {
                $table->string('chat_lock_hash')->nullable();
            }
            if (! Schema::hasColumn('users', 'chat_lock_set_at')) {
                $table->timestamp('chat_lock_set_at')->nullable();
            }
        });

        /*
         * A conversation with yourself.
         *
         * Marked outright rather than inferred from having one member: a
         * direct chat whose other person deleted their account also has one
         * member left, and it is not a notepad.
         */
        Schema::table('conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('conversations', 'is_self')) {
                $table->boolean('is_self')->default(false);
            }
        });

        Schema::table('conversation_members', function (Blueprint $table) {
            if (! Schema::hasColumn('conversation_members', 'locked_at')) {
                $table->timestamp('locked_at')->nullable();
            }
            if (! Schema::hasColumn('conversation_members', 'hidden_at')) {
                $table->timestamp('hidden_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversation_members', function (Blueprint $table) {
            foreach (['locked_at', 'hidden_at'] as $column) {
                if (Schema::hasColumn('conversation_members', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('conversations', function (Blueprint $table) {
            if (Schema::hasColumn('conversations', 'is_self')) {
                $table->dropColumn('is_self');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach (['chat_lock_hash', 'chat_lock_set_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
