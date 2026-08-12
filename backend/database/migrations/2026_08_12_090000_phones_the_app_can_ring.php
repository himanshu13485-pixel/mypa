<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the Android app's ring tokens live.
 *
 * Web push reaches browsers, and the native app is not one: Android's WebView
 * has no Push API at all, so the shell registers with Firebase instead and
 * hands us the token here. A user may have several — a phone and a tablet —
 * and a token belongs to exactly one user at a time: the same physical device
 * signing into a different account must ring for the new account, not both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // FCM tokens run long; 255 is not enough and text cannot be
            // uniquely indexed on MySQL without a prefix. 512 covers what
            // Firebase actually issues.
            $table->string('token', 512)->unique();
            $table->string('platform', 16)->default('android');
            // For pruning: a token silent for months belongs to an
            // uninstalled app.
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_tokens');
    }
};
