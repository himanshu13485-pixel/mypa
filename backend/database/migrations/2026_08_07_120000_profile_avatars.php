<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A picked avatar, for the many people who will never upload a photo.
 *
 * Deliberately not a file: this holds a short key like "f3" naming one of the
 * illustrations the frontend draws, so choosing one costs no upload, no disk,
 * no storage quota and no image request. An uploaded photo still wins when
 * there is one — `photo_path` is the real picture, this is the stand-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('avatar', 8)->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
    }
};
