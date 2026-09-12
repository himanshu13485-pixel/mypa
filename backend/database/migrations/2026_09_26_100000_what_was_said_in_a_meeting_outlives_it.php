<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chat in a meeting, kept.
 *
 * It was a signal and nothing else: typed, broadcast to whoever happened to
 * be in the room at that second, and gone. The link somebody pasted, the
 * figure somebody read out, the address the client gave - all of it lived
 * only in the tabs that were open at the time, and closing one lost it.
 * Somebody who joined ten minutes late arrived to an empty panel.
 *
 * A screen share is a meeting with is_screen set, so this covers both.
 *
 * The file a message carried is already stored in meeting_files; this row
 * names it, so a transcript reads as the conversation it was rather than
 * as text with holes where the attachments were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            /*
             * The name they were wearing at the time.
             *
             * A guest is a user row with a display name set for that meeting
             * alone, and somebody who renames themselves later has not
             * renamed what they said last week.
             */
            $table->string('display_name')->nullable();
            $table->text('body')->nullable();
            // Set on a private message: only these two ever see it.
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('meeting_file_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['meeting_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_messages');
    }
};
