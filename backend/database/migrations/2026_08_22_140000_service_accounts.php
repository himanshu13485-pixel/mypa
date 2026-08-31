<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * An account another application signs in as, rather than a person.
             *
             * The only thing it changes is that connection requests to it are
             * accepted the moment they arrive. A person's account has someone
             * to read the request; a service account has nobody, so a request
             * to one would sit pending for ever and the integration would look
             * broken while waiting on a decision no one was going to make.
             *
             * Deliberately narrow. It does not bypass anybody's privacy: the
             * person still chooses to connect, and can disconnect or block the
             * same as with anyone else. It only removes a wait on the side
             * where there is no human.
             */
            $table->boolean('is_service_account')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_service_account');
        });
    }
};
