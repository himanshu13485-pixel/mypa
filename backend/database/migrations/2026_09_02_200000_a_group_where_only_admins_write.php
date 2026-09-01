<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Announcement groups: everybody reads, the people running it write.
 *
 * A company notice group, a class, a client broadcast — the shape where a
 * hundred people should hear something and not reply into it. Off by
 * default, because a group is a conversation until somebody says otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('only_admins_post')->default(false)->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('only_admins_post');
        });
    }
};
