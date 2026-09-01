<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devices this person has already answered a code on.
 *
 * A code on every login is security nobody keeps: people turn it off, or
 * they stop using the app. Asking once per device — the phone, the office
 * desktop, the laptop — puts the check where it belongs, on the moment a
 * new machine claims to be you, which is the moment that matters.
 *
 * Only a hash is stored. The token itself lives in the browser that earned
 * it, so a copy of this table proves nothing and unlocks nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('name')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
