<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far a company admin may sit in one of their staff's seats.
 *
 * Signing in as somebody else is the most powerful thing an ordinary
 * administrator can be handed, and it is not one setting but four different
 * decisions — so it is not a boolean, and it is not the company's to make.
 * The platform grants it, per company, at exactly one of these:
 *
 *   none      nothing at all, and the button is not drawn. The default,
 *             because a right nobody asked for should not arrive switched on.
 *   crm_read  their workspace, to look at. Enough to settle "what can this
 *             person actually see", which is the question most of the time.
 *   crm       their workspace, to work in. Enough to reproduce something
 *             that only goes wrong when they press the button themselves.
 *   account   the whole of their Netvork — private notes, files and messages
 *             included. Only for a company whose staff have been told.
 *
 * A column rather than a line in the settings JSON. This one decides who may
 * read whose private messages, and a permission like that should be visible
 * in the schema rather than buried in a blob nobody greps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_organizations', function (Blueprint $table) {
            $table->string('impersonation_level', 16)->default('none')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('crm_organizations', function (Blueprint $table) {
            $table->dropColumn('impersonation_level');
        });
    }
};
