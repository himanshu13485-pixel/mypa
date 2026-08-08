<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One switch instead of two.
 *
 * guest_access was a separate flag that only ever meant anything alongside a
 * passcode, so every screen that touched it had to explain the pairing and
 * every code path had to check both. The passcode is now the whole rule: set
 * one and people without an account can join with it, leave it empty and they
 * cannot. Meetings that had guest access on already have a passcode — that was
 * enforced when the flag was set — so dropping the column loses nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('guest_access');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->boolean('guest_access')->default(false)->after('passcode');
        });

        // Rebuild the flag from the rule that replaced it.
        \Illuminate\Support\Facades\DB::table('meetings')
            ->whereNotNull('passcode')
            ->update(['guest_access' => true]);
    }
};
