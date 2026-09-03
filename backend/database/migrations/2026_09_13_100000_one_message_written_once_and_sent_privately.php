<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The same message to thirty people, arriving as thirty private messages.
 *
 * Not a group. A group is a room: everyone in it can see who else is there
 * and everything anybody says. This is the other thing people keep using a
 * group for and then regretting — one announcement to a list of individuals,
 * where the recipients are not introduced to each other and any reply comes
 * back as an ordinary private conversation with the sender alone.
 *
 * The two tables say exactly that. Each recipient gets a real message in a
 * real direct conversation, indistinguishable from one typed to them alone,
 * because that is what it is. The broadcast row exists solely so the SENDER's
 * own screen can say "you sent this to thirty people" instead of showing them
 * thirty identical messages with no explanation of where they came from — and
 * it is joined only when serialising for that sender, which is what keeps the
 * fact off everybody else's copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The text, kept beside the copies rather than only in them.
             *
             * A sender deleting their copy from one conversation must not take
             * the record of what was broadcast with it, and this is also what
             * lets a future "send again" exist without reading somebody's
             * thread back.
             */
            $table->text('body')->nullable();

            /*
             * How many it actually reached — counted after the refusals, not
             * before. A number that includes the people who never got it is a
             * number that lies to the only person who reads it.
             */
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            /*
             * Nullable, and it stays null for every ordinary message.
             *
             * nullOnDelete rather than cascade: removing the sender's record
             * of a broadcast must never delete the messages people received.
             * They are their messages now.
             */
            $table->foreignId('broadcast_id')->nullable()->after('reply_to_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['broadcast_id']);
            $table->dropColumn('broadcast_id');
        });

        Schema::dropIfExists('broadcasts');
    }
};
