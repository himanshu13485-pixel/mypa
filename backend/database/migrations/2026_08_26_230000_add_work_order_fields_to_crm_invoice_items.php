<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work Order lines carry Dedicated Company Workspace values.
 *
 * Every company words a work order differently — one sells chapter listings,
 * another sells site visits — so the fixed columns stay and each company adds
 * its own on top, with Super Admin approval, exactly as the client form does.
 * The values live in JSON on the line, so one company's method never touches
 * another company's tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_invoice_items', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('crm_invoice_items', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
    }
};
