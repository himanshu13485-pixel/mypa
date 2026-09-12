<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's own theme: background, sidebar, and the words their birthday
 * wishes and thank-yous start from.
 *
 * On the person rather than on their CRM membership, because the theme is
 * theirs across Netvork - the CRM screens and the personal ones - and
 * somebody with no company has a theme too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('user_settings', 'appearance')) {
            return;
        }

        Schema::table('user_settings', function (Blueprint $table) {
            $table->json('appearance')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_settings', 'appearance')) {
            Schema::table('user_settings', function (Blueprint $table) {
                $table->dropColumn('appearance');
            });
        }
    }
};
