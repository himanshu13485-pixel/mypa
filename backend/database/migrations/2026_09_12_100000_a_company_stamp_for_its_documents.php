<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The rubber stamp, next to the signature.
 *
 * Separate from the logo, which is a different thing doing a different job:
 * the logo is branding and sits in the header, the stamp is the mark of
 * authority that belongs beside "Authorised signatory" at the foot. Plenty of
 * companies have both and they are rarely the same image — the stamp is
 * usually round, usually monochrome, and often carries the registration
 * number the header logo does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_issuing_companies', function (Blueprint $table) {
            $table->string('stamp_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_issuing_companies', function (Blueprint $table) {
            $table->dropColumn('stamp_path');
        });
    }
};
