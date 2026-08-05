<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public share links for files.
 *
 * Sharing already existed, but only to a named Netvork user by app id. A link
 * is the other half: something you can paste to somebody who has no account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // Null means the file is not link-shared. Unique so a token can be
            // looked up on its own, without knowing which file it belongs to.
            $table->string('share_token', 64)->nullable()->unique()->after('size');
            $table->timestamp('share_expires_at')->nullable()->after('share_token');
            $table->unsignedInteger('share_downloads')->default(0)->after('share_expires_at');
            $table->timestamp('shared_at')->nullable()->after('share_downloads');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['share_token', 'share_expires_at', 'share_downloads', 'shared_at']);
        });
    }
};
