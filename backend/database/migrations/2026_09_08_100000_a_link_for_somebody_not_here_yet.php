<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Inviting somebody who has no Netvork account.
 *
 * Every way in assumed the other person was already here: search them, scan
 * their code, type their App ID. Someone whose colleague has never heard of
 * Netvork had nothing to send them.
 *
 * The code is its own random string rather than the App ID, which is
 * sequential — NV-1, NV-2, NV-3 — and would let anyone holding one link walk
 * the whole directory by counting. This page is public, so what it is keyed
 * on has to be unguessable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            // Null until the first invite link is asked for: most people
            // never send one, and a column of unused secrets is a liability
            // rather than a feature.
            $table->string('invite_code', 24)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('invite_code');
        });
    }
};
