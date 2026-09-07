<?php

use App\Models\Crm\Member;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Splitting the coarse module rights, without anybody losing access.
 *
 * The rights screen was coarser than the menu it governs: one "Proforma &
 * invoices" tick opened five sidebar entries, so a junior meant to raise
 * proformas was handed the power to issue tax invoices with them. Granting
 * more than you meant to is the one mistake a permissions screen must not
 * invite, so each screen the server can actually tell apart now has its own
 * right.
 *
 * The whole risk of that is this migration. Every member holding a retired
 * right is given all the rights it used to open — the same access, spelled
 * out — because the alternative is a Monday morning where half a sales floor
 * cannot open the screen they worked in on Friday. Nobody gains anything they
 * could not already reach; the abilities (view/create/edit/delete) carry
 * across untouched, so a person who could only view invoices can still only
 * view the three rights that replace it.
 *
 * Reversible in the same spirit: holding any of the new rights puts the old
 * one back, with the abilities merged.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(Member::SPLIT_MODULES);
    }

    public function down(): void
    {
        $backwards = [];
        foreach (Member::SPLIT_MODULES as $old => $new) {
            foreach ($new as $slug) {
                $backwards[$slug] = array_merge($backwards[$slug] ?? [], [$old]);
            }
        }

        $this->rewrite($backwards);
    }

    /**
     * @param  array<string, list<string>>  $map  slug held → slugs to write
     */
    protected function rewrite(array $map): void
    {
        DB::table('crm_members')
            ->select('id', 'rights')
            ->whereNotNull('rights')
            ->orderBy('id')
            // Chunked because a company's whole staff is rewritten one row at
            // a time, and the rights column is JSON this has to decode.
            ->chunkById(200, function ($rows) use ($map) {
                foreach ($rows as $row) {
                    $rights = json_decode((string) $row->rights, true);
                    if (! is_array($rights)) {
                        continue;
                    }

                    $changed = false;

                    foreach ($map as $old => $replacements) {
                        if (! array_key_exists($old, $rights)) {
                            continue;
                        }

                        $abilities = (array) $rights[$old];
                        unset($rights[$old]);

                        foreach ($replacements as $slug) {
                            // Merged rather than overwritten: a member could
                            // already hold one of the new slugs from a later
                            // edit, and the wider of the two wins.
                            $rights[$slug] = array_values(array_unique(
                                array_merge($rights[$slug] ?? [], $abilities),
                            ));
                        }

                        $changed = true;
                    }

                    if ($changed) {
                        DB::table('crm_members')
                            ->where('id', $row->id)
                            ->update(['rights' => json_encode($rights)]);
                    }
                }
            });
    }
};
