<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A password on the group, set by whoever runs it.
 *
 * The chat password we already had is one person's own arrangement: they
 * choose a password and lock their own copy of a chat with it, and nobody
 * else in the room knows or is affected. That is the right shape for a
 * phone left on a desk, and the wrong shape for a group whose contents are
 * the group's business rather than one member's.
 *
 * So a group can carry a password of its own. The owner and admins set it,
 * change it and take it away; everybody in the group - admins included -
 * gives it to open the chat. An admin's phone is as easy to pick up as
 * anybody's, and a lock its owner can walk past is decoration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('groups') || Schema::hasColumn('groups', 'chat_password_hash')) {
            return;
        }

        Schema::table('groups', function (Blueprint $table) {
            $table->string('chat_password_hash')->nullable()->after('only_admins_post');
            $table->timestamp('chat_password_set_at')->nullable()->after('chat_password_hash');
            // Who last set it, so the group can say who to ask for it.
            $table->foreignId('chat_password_set_by')->nullable()->after('chat_password_set_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('groups') || ! Schema::hasColumn('groups', 'chat_password_hash')) {
            return;
        }

        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chat_password_set_by');
            $table->dropColumn(['chat_password_hash', 'chat_password_set_at']);
        });
    }
};
