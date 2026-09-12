<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A birthday wish addressed to somebody, and the thanks that comes back.
 *
 * The existing crm_wishes table is a wall: messages about an occasion, from
 * a member, to nobody in particular. That suits a festival and does not suit
 * a birthday, where the whole point is that Priyanshu sees who wished him
 * and can say thank you to each of them. So this is its own table - one wish
 * from one person to one person, for one year's birthday, with room for the
 * reply.
 *
 * One wish per sender per recipient per year: wishing twice updates the
 * wish rather than stacking a second one on somebody's screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_birthday_wishes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('from_member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->foreignId('to_member_id')->constrained('crm_members')->cascadeOnDelete();
            // Which birthday: the same two people wish each other every year.
            $table->unsignedSmallInteger('birthday_year');
            $table->string('message', 1000);
            $table->string('reply', 1000)->nullable();
            $table->dateTime('replied_at')->nullable();
            // Seen, before or without a written answer.
            $table->dateTime('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['from_member_id', 'to_member_id', 'birthday_year'], 'crm_birthday_wishes_once_a_year');
            $table->index(['organization_id', 'to_member_id', 'birthday_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_birthday_wishes');
    }
};
