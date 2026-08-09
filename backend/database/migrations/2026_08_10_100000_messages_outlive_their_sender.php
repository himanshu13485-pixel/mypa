<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A message keeps its place when its author leaves.
 *
 * Closing an account is supposed to take the account's own data and leave
 * everyone else's conversations alone. Those two could not both be true:
 * messages.user_id cascaded on delete, so genuinely removing a user would have
 * reached into other people's threads and taken half of every conversation
 * with it. Faced with that, the deletion was made soft — and a soft delete
 * cascades to nothing at all, so it quietly stopped deleting anything.
 *
 * The sender becomes optional instead. The message stays where it is, with
 * nobody behind it, and the app already reads that as an absent sender: the
 * serializer has always guarded the relation and returns a null sender rather
 * than assuming one.
 *
 * SQLite cannot drop a foreign key, so the constraint is only swapped where
 * the driver allows it. AccountController does not lean on the constraint
 * either way — it releases the messages itself, which behaves the same on
 * every driver, including the one the tests run on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Messages with no sender cannot be given one, and the column cannot go
        // back to NOT NULL while they exist. Anything already orphaned by a
        // closed account would have to be destroyed to reverse this, which is
        // the thing the migration exists to prevent.
    }
};
