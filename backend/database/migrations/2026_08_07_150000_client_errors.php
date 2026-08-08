<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What broke in somebody's browser.
 *
 * There was no error tracking of any kind: a white screen told nobody
 * anything, and you found out when a user got round to complaining. This is
 * the smallest thing that fixes that — no third-party account, no data
 * leaving the server.
 *
 * `fingerprint` is what makes it usable rather than a firehose: the same fault
 * hitting two hundred people is one row with a count of two hundred, not two
 * hundred rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_errors', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->text('message');
            $table->text('stack')->nullable();
            $table->string('url', 512)->nullable();
            $table->string('release', 64)->nullable();
            $table->unsignedInteger('hits')->default(1);
            // Who saw it last, for asking them what they were doing. Nulled
            // rather than orphaned if that account is later deleted.
            $table->foreignId('last_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('last_agent', 255)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['resolved_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_errors');
    }
};
