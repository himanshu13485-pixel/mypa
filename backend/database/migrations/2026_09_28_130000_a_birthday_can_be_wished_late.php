<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A wish sent after the day.
 *
 * Somebody who missed a colleague's birthday can still wish them from the
 * Birthdays menu for a week afterwards. Whether a wish came late is kept on
 * the wish itself: worked out from dates later, a wish edited after the day
 * would look late when it was not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('crm_birthday_wishes', 'belated')) {
            return;
        }

        Schema::table('crm_birthday_wishes', function (Blueprint $table) {
            $table->boolean('belated')->default(false);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_birthday_wishes', 'belated')) {
            Schema::table('crm_birthday_wishes', function (Blueprint $table) {
                $table->dropColumn('belated');
            });
        }
    }
};
