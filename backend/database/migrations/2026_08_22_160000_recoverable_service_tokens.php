<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            /*
             * The token itself, encrypted, for service accounts only.
             *
             * Sanctum keeps a hash and nothing else, which is right for a
             * person's session: it can be replaced by signing in again, and
             * nobody needs to read it back. An integration's token is not that.
             * It lives in another system's configuration, and losing the only
             * copy means an admin cannot check what was installed or repair a
             * setup without cutting the integration off first.
             *
             * Encrypted with APP_KEY, so a database dump on its own does not
             * carry the credentials — the same trade grapme already makes for
             * the portal keys it stores. Never populated for a person's token:
             * there is nothing to gain and a real amount to lose.
             */
            $table->text('encrypted_value')->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('encrypted_value');
        });
    }
};
