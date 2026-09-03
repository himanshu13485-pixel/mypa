<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One password a company admin can put somebody back in with.
 *
 * What happens today when a salesperson is locked out on a Monday morning is
 * that the admin invents a password, says it down the phone, and it is
 * different every time and written on something. The master key replaces the
 * inventing: one value the admin sets once, and a button that puts an
 * employee's account back onto it.
 *
 * Two decisions worth stating, because both could reasonably have gone the
 * other way.
 *
 * It is ENCRYPTED, not hashed. A hash cannot be applied to somebody else's
 * account — the whole point is to set a password TO this value, which means
 * being able to read it back. That is a real cost and it should be said
 * plainly: anyone holding both a database dump and APP_KEY holds this key, and
 * this key opens every non-admin account in the company. It is why the reset
 * forces a change on next sign-in, why it takes the target's live sessions
 * down with it, and why setting the key at all asks the admin for their own
 * password first.
 *
 * It is on the ORGANIZATION, not on the platform. This is a company's key to
 * its own staff's accounts, and nothing about it should reach across
 * companies — which is also why the reset refuses on anybody outside the
 * admin's own org, and on a fellow admin inside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_organizations', function (Blueprint $table) {
            $table->text('master_key')->nullable();
            $table->timestamp('master_key_set_at')->nullable();

            /*
             * Who set it, kept even after they leave.
             *
             * nullOnDelete rather than cascade: a key set by somebody who has
             * since gone is still the live key, and taking the column down
             * with their user row would silently unlock nothing and explain
             * nothing.
             */
            $table->foreignId('master_key_set_by')->nullable()
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_organizations', function (Blueprint $table) {
            $table->dropForeign(['master_key_set_by']);
            $table->dropColumn(['master_key', 'master_key_set_at', 'master_key_set_by']);
        });
    }
};
