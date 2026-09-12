<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people you actually talk to, kept at the top.
 *
 * Its own table rather than a column on connections, because a connection is
 * one row shared by two people and a favourite belongs to one of them: Asha
 * starring Bala says nothing about whether Bala has starred Asha, and a
 * boolean on the shared row could only ever say one thing for both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('favorite_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'favorite_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_favorites');
    }
};
