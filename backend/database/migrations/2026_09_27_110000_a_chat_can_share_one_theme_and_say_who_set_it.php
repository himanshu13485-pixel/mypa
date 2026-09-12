<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A theme the whole conversation wears.
 *
 * The colour was only ever one person's, stored on their membership, which
 * made it impossible to say in the chat that it had changed - the other side
 * would read "Asha changed the theme" and see nothing different. So there are
 * now two: the conversation's own theme, set by anybody in it and announced
 * in the thread the way a group rename is, and the private one each person
 * can still lay over it for themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('theme', 24)->nullable()->after('auto_delete_hours');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
