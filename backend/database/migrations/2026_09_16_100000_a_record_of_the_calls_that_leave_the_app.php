<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The calls that leave the app — a salesperson's own SIM ringing a lead.
 *
 * Its own table rather than a row in `calls`, which requires a conversation
 * and a participant on the other end. A lead is a phone number belonging to
 * somebody who has never heard of Netvork; there is no conversation to point
 * at and no user to attach.
 *
 * What can be known automatically, and what cannot, is the whole shape of
 * this table. `placed_at` is certain: the app is what dialled, so the moment
 * of the attempt is ours to record. Everything after it — whether the lead
 * picked up, and for how long — happens on the cellular network, which
 * Android does not let an app observe. Since Android 10, reading the voice
 * call stream needs a privileged permission that only the dialler gets, and
 * the call log itself needs a permission Google Play reserves for dialler
 * apps.
 *
 * So the outcome columns are nullable and filled in afterwards, by the person
 * who made the call. That is a weaker fact than a network-recorded duration
 * and it is honest about being one: `duration_seconds` says what the caller
 * reported, not what the carrier metered. A company that needs the stronger
 * version needs either a cloud telephony provider in the path or the native
 * call-log reader, and both are decisions with a cost attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_calls', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The company this was made on behalf of, when there is one.
             *
             * Nullable because the same button exists outside the CRM — a
             * personal contact's number is still a number — and because a
             * call must not vanish from its maker's own history if they later
             * leave the company.
             */
            $table->foreignId('organization_id')->nullable()
                ->constrained('crm_organizations')->nullOnDelete();

            /*
             * What was being rung: a lead, a client, a complaint. A morph
             * rather than three nullable foreign keys, because the list of
             * things worth ringing will grow and a fourth column each time is
             * how a table stops being readable.
             */
            $table->nullableMorphs('subject');

            // The number as dialled, and who it belonged to at the time.
            $table->string('number', 32);
            $table->string('label')->nullable();

            /*
             * Whether the app opened the dialler here, or sent it to a phone.
             * Worth keeping apart: one is somebody at a desk and the other is
             * somebody in the field, and that is a real difference in how a
             * day was spent.
             */
            $table->enum('placed_from', ['phone', 'laptop'])->default('phone');

            // Certain, because the app is what dialled.
            $table->timestamp('placed_at')->index();

            /*
             * Reported, not measured. Null until the caller says.
             */
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('outcome', 24)->nullable()->index();
            $table->text('notes')->nullable();

            $table->timestamps();

            // The two questions asked of this table: one person's day, and
            // one lead's history.
            $table->index(['user_id', 'placed_at']);
            $table->index(['organization_id', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_calls');
    }
};
