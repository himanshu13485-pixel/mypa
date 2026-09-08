<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One work order line can name several memberships, and several plans.
 *
 * A single line covering three memberships had to be typed as three lines
 * charged separately, or as one line whose real contents lived in the
 * description where nothing could count them. Both are the same problem:
 * the column held one value and the sale had more.
 *
 * Stored as a comma-separated list in the column that already exists, so
 * every document ever raised stays exactly as it reads — one value is a list
 * of one, and nothing has to be migrated. The width goes up because two or
 * three names no longer have to share 128 characters with their separators.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_invoice_items', function (Blueprint $table) {
            $table->string('membership', 512)->nullable()->change();
            $table->string('plan_name', 512)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Truncated first, or the narrowing fails on a line that used the room.
        DB::table('crm_invoice_items')->update([
            'membership' => DB::raw('substr(membership, 1, 128)'),
            'plan_name' => DB::raw('substr(plan_name, 1, 128)'),
        ]);

        Schema::table('crm_invoice_items', function (Blueprint $table) {
            $table->string('membership', 128)->nullable()->change();
            $table->string('plan_name', 128)->nullable()->change();
        });
    }
};
