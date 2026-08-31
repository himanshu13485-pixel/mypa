<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A link you hand out, so people can book you without asking first.
 *
 * Three tables, because the three things have genuinely different lifetimes:
 * the page is configuration that changes rarely, the hours are a weekly
 * pattern edited as a set, and a booking is a fact about one moment that must
 * survive both of them being changed afterwards.
 *
 * That last point drives most of the column choices below. A booking records
 * its own start and end rather than deriving them from the page's duration —
 * shortening your meetings from 30 minutes to 15 must not retroactively
 * shorten everything already in the diary — and it keeps the guest's name,
 * email and timezone rather than pointing at an account, because the whole
 * premise is that the person booking does not have one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_pages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // One per person, enforced here rather than by convention.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // What appears in the URL. Unique across everyone, since it is the
            // whole address: netvork.app/book/{slug}.
            $table->string('slug', 64)->unique();
            $table->string('title')->nullable();
            $table->text('description')->nullable();

            $table->unsignedSmallInteger('duration_minutes')->default(30);
            /*
             * Breathing room after each booking, kept out of the slot itself.
             *
             * A 30-minute meeting with a 10-minute buffer occupies 40 minutes
             * of the diary but is still described to the person booking as 30
             * — they are not interested in your recovery time, and showing it
             * would make every slot look longer than the meeting they get.
             */
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            // The soonest something may be booked, so nobody appears in your
            // diary in four minutes' time.
            $table->unsignedInteger('min_notice_minutes')->default(120);
            // And the furthest ahead, so the calendar is not open forever.
            $table->unsignedSmallInteger('max_days_ahead')->default(30);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('booking_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_page_id')->constrained()->cascadeOnDelete();
            // 0 = Sunday, matching Carbon's dayOfWeek so no translation is
            // needed anywhere it is read.
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');

            /*
             * Several rows per weekday on purpose.
             *
             * "9 to 5" is rarely true — the common shape is a morning and an
             * afternoon with lunch cut out of the middle, and one row per day
             * cannot say that. Two rows can, and the slot maths does not care
             * how many there are.
             */
            $table->index(['booking_page_id', 'weekday']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('booking_page_id')->constrained()->cascadeOnDelete();
            /*
             * The host again, denormalised.
             *
             * Reachable through the page, but every conflict check asks "what
             * else is this person doing on Tuesday" and joining through the
             * page for that is a needless hop on the query that runs most.
             */
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();

            // The room and the diary entry this booking produced. Nullable and
            // nulled on delete: a booking is still a real record of what was
            // agreed even if the host later deletes the meeting or the event.
            $table->foreignId('meeting_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();

            // Whoever booked. Not a user_id — the entire point is that they
            // need no account.
            $table->string('name');
            $table->string('email');
            $table->text('note')->nullable();
            // Theirs, not the host's, so a confirmation reads in the time they
            // actually live in.
            $table->string('guest_timezone', 64)->default('UTC');

            /*
             * Its own start and end, rather than a start plus the page's
             * duration.
             *
             * Configuration changes and bookings do not: shortening your
             * meetings from thirty minutes to fifteen must not retroactively
             * shorten everything already agreed.
             */
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            /*
             * What stands in for a session when the guest comes back.
             *
             * The same device the file-share links use: a long unguessable
             * token in the URL, which is the only thing a person with no
             * account can present. It is what lets them cancel or move the
             * booking without one, and it is why the confirmation email is
             * worth keeping.
             */
            $table->string('manage_token', 64)->unique();

            $table->enum('status', ['confirmed', 'cancelled'])->default('confirmed');
            $table->timestamp('cancelled_at')->nullable();
            $table->enum('cancelled_by', ['host', 'guest'])->nullable();
            $table->timestamps();

            // The two questions actually asked: what is on this host's diary,
            // and what is on this page's.
            $table->index(['host_id', 'starts_at']);
            $table->index(['booking_page_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('booking_hours');
        Schema::dropIfExists('booking_pages');
    }
};
