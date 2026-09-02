<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Joining a group without being added one name at a time.
 *
 * Every member had to be typed in by an admin, which is fine for a family and
 * hopeless for a team of forty: the admin becomes a queue, and the last
 * person to be added is the first to be forgotten.
 *
 * Two shapes, because "anyone with this link is in" and "anyone with this
 * link may ask" are different groups. The second is the safer default — a
 * link is a thing people forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            /*
             * Null means there is no link. Turning the link off has to be a
             * real revocation rather than a flag beside a token that still
             * works, or "off" is a decoration.
             */
            $table->string('invite_token', 32)->nullable()->unique();

            // 'open' — the link admits. 'request' — the link asks.
            $table->string('invite_mode', 16)->default('request');
        });

        Schema::create('group_join_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('status', ['pending', 'approved', 'declined'])->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            /*
             * One standing request per person per group.
             *
             * Somebody who opens the link twice has not asked twice, and an
             * admin looking at a list of the same name four times learns
             * nothing from the repetition. A decided row is reused rather
             * than duplicated when they ask again.
             */
            $table->unique(['group_id', 'user_id']);
            $table->index(['group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_join_requests');

        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn(['invite_token', 'invite_mode']);
        });
    }
};
