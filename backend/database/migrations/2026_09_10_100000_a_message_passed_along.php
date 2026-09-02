<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Saying where a message came from.
 *
 * A forwarded message reads exactly like something the sender wrote, and the
 * difference matters: "the meeting is cancelled" carries one weight from the
 * person who decided it and another from somebody passing it on. Every
 * messenger marks these for that reason.
 *
 * A flag, not a link back to the original. Who sent it to you first is not
 * the recipient's business, and a foreign key would leak a conversation they
 * were never in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_forwarded')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('is_forwarded');
        });
    }
};
