<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A line about what you are up to.
 *
 * Separate from the bio, which is who you are and changes twice a year. This
 * is the one that changes on a Tuesday - "on leave until the 12th", "heads
 * down on the audit" - and it is the thing people actually want when they
 * open somebody's profile.
 *
 * Named status_text rather than status because users.status already exists
 * and means something entirely different: whether the account is active. Two
 * columns called status, one row apart, is a bug waiting for a careless join.
 * The API calls this one `status`, which is what a person calls it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('status_text', 140)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('status_text');
        });
    }
};
