<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // Screen-share sessions reuse the whole meeting engine but render
            // as one-way host->viewers rooms in their own "Screen" module.
            $table->boolean('is_screen')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('is_screen');
        });
    }
};
