<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mobile is now an optional profile record (not a login/search identity):
        // duplicates are allowed (e.g. a family sharing one number).
        Schema::table('users', function (Blueprint $table) {
            // The plain index from the original users migration remains.
            $table->dropUnique(['mobile']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('mobile');
        });
    }
};
