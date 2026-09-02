<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Two different ways of marking a message, deliberately not one thing.
 *
 * Starring is private and personal: the address you were sent, the number you
 * will need again. Nobody else in the conversation learns you kept it, which
 * is exactly why people star things.
 *
 * Pinning is public and shared: the decision this group keeps referring back
 * to, held at the top for everyone. Whoever can post can pin, because a
 * conversation nobody may organise fills up and stays that way.
 *
 * Same button in most apps, and the difference matters enough to keep them
 * apart in the schema rather than discover later that one table meant both.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Private. One row per person per message: starring twice is starring.
        Schema::create('message_stars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
            // The "my starred messages" list, which is always by person.
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            /*
             * Shared, so it lives on the message rather than in a pivot.
             *
             * Null means not pinned; the timestamp orders the pins, most
             * recent first, and remembers when a decision was raised.
             */
            $table->timestamp('pinned_at')->nullable();
            $table->foreignId('pinned_by_id')->nullable()->constrained('users')->nullOnDelete();

            // "What is pinned in this conversation" is the only query this
            // column ever answers, and it answers it on every thread open.
            $table->index(['conversation_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_stars');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'pinned_at']);
            $table->dropConstrainedForeignId('pinned_by_id');
            $table->dropColumn('pinned_at');
        });
    }
};
