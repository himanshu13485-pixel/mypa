<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far one Subadmin, by name, may sit in an employee's seat.
 *
 * The company's own ceiling lives on the organization and is the platform's
 * to set. This is the second half: within that ceiling, the Company Admin
 * decides which of their Subadmins may do it and how deeply — the same four
 * answers, because "may they" was never the whole question. A Subadmin
 * trusted to check what an employee can see is not automatically one trusted
 * to read their private messages.
 *
 * Null means no. Not a boolean, and deliberately not a row in the member's
 * capabilities list either: that list is written by anyone who may edit an
 * employee, which includes Subadmins editing each other and themselves — so
 * a capability would have been a right they could hand themselves. A column
 * the update path refuses from anybody but an Admin cannot be.
 *
 * Always read through Member::impersonationLevel(), which caps it against the
 * organization's own level. Lowering the company ceiling has to lower
 * everybody under it, and it would not if this column were trusted on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_members', function (Blueprint $table) {
            $table->string('impersonation_level', 16)->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('crm_members', function (Blueprint $table) {
            $table->dropColumn('impersonation_level');
        });
    }
};
