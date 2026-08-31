<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Documents raised before salesperson attribution became automatic carry no
 * member_id, so they fell out of every per-person figure — a Team Head's
 * team total was missing a reportee's sale. The raiser is on record as
 * created_by, so the salesperson is recoverable: their membership in the
 * document's own organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('crm_invoices')
            ->whereNull('member_id')
            ->whereNotNull('created_by')
            ->get(['id', 'organization_id', 'created_by']);

        foreach ($orphans as $invoice) {
            $memberId = DB::table('crm_members')
                ->where('organization_id', $invoice->organization_id)
                ->where('user_id', $invoice->created_by)
                ->where('is_oversight', false)
                ->value('id');

            if ($memberId !== null) {
                DB::table('crm_invoices')->where('id', $invoice->id)->update(['member_id' => $memberId]);
            }
        }
    }

    public function down(): void
    {
        // No way to tell a backfilled attribution from a chosen one, and
        // removing either would re-break the per-person figures.
    }
};
