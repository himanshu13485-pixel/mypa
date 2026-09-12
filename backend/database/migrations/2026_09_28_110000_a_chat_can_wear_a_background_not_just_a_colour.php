<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Behind the messages: a gradient or a pattern, not just a tint.
 *
 * Kept apart from `theme`, which colours the bubbles, so the two can be
 * chosen independently - a violet chat on a starfield, or plain bubbles on
 * an aurora. Like the theme, there is the chat's own (set by anybody in it,
 * and announced) and a private one each person can lay over it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('background', 32)->nullable()->after('theme');
        });

        Schema::table('conversation_members', function (Blueprint $table) {
            $table->string('background', 32)->nullable()->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_members', function (Blueprint $table) {
            $table->dropColumn('background');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('background');
        });
    }
};
