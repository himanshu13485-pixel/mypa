<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether documents must carry tax belongs to the company that issues them.
 *
 * An organization with a domestic arm and an export arm has one answer for
 * each: the domestic company charges GST on everything it raises, the export
 * company raises invoices without payment of tax. Held once for the whole
 * organization, either answer was wrong for half the documents.
 *
 * Any organization-wide answer already given is copied onto every company it
 * covered, so nobody's setting is silently dropped by the move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_issuing_companies', function (Blueprint $table) {
            $table->boolean('tax_required')->default(false)->after('currency');
        });

        foreach (DB::table('crm_organizations')->select('id', 'settings')->cursor() as $org) {
            $settings = json_decode((string) $org->settings, true);
            if (! data_get($settings, 'documents.tax_required')) {
                continue;
            }

            DB::table('crm_issuing_companies')
                ->where('organization_id', $org->id)
                ->update(['tax_required' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('crm_issuing_companies', function (Blueprint $table) {
            $table->dropColumn('tax_required');
        });
    }
};
