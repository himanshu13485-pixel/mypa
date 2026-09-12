<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        /*
         * Recovering from this migration's own first attempt.
         *
         * As first written, the last index here had no name of its own, and
         * Laravel's generated one - crm_birthday_wishes_organization_id_
         * to_member_id_birthday_year_index - is 68 characters. MySQL allows
         * 64. SQLite, which the tests run on, allows any length, so nothing
         * noticed until production.
         *
         * MySQL cannot roll back a CREATE TABLE, so that failure left the
         * table standing while the migration went unrecorded, and every
         * deploy after it stopped at "table already exists". The deploy that
         * failed never got as far as building the screens that use this
         * table, so a leftover one is empty - dropped and made again,
         * properly. One that somehow holds wishes is not dropped: that would
         * be losing somebody's data to tidy a schema, so it stops and says so.
         */
        if (Schema::hasTable('crm_birthday_wishes')) {
            $rows = DB::table('crm_birthday_wishes')->count();

            if ($rows > 0) {
                throw new RuntimeException(
                    "crm_birthday_wishes already exists and holds {$rows} row(s), so it was not dropped. "
                    . 'Check its indexes by hand, then mark this migration as run.'
                );
            }

            Schema::drop('crm_birthday_wishes');
        }

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

            // Both named explicitly, and both well under MySQL's 64.
            $table->unique(['from_member_id', 'to_member_id', 'birthday_year'], 'crm_birthday_wishes_once_a_year');
            $table->index(['organization_id', 'to_member_id', 'birthday_year'], 'crm_birthday_wishes_org_to_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_birthday_wishes');
    }
};
