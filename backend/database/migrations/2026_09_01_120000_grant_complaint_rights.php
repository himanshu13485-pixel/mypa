<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hand the new Complaints head to the people who already work the floor.
 *
 * A module added after the fact starts switched off for everyone, which
 * would leave the register invisible until an Admin opened twenty accounts
 * one at a time. So anybody who already has a rights matrix gets view,
 * create and edit on complaints — enough to log one, answer it and close
 * it. Deleting a complaint stays where deletions belong, with the Admin.
 *
 * Members with no matrix at all are left alone: somebody deliberately shut
 * out of everything should not be let in by a migration. And this only ever
 * adds — an Admin who unticks it keeps it unticked, because the key is then
 * present and this never runs again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $members = DB::table('crm_members')
            ->select('id', 'rights', 'crm_role')
            ->whereNotNull('rights')
            ->get();

        foreach ($members as $member) {
            // Admins work off their role, not the matrix — nothing to do.
            if ($member->crm_role === 'admin') {
                continue;
            }

            $rights = json_decode($member->rights, true);

            // An empty or malformed matrix is not a matrix.
            if (! is_array($rights) || $rights === []) {
                continue;
            }
            // Already decided, one way or the other. Leave it.
            if (array_key_exists('complaints', $rights)) {
                continue;
            }

            $rights['complaints'] = ['view', 'create', 'edit'];
            DB::table('crm_members')->where('id', $member->id)
                ->update(['rights' => json_encode($rights)]);
        }
    }

    public function down(): void
    {
        $members = DB::table('crm_members')->select('id', 'rights')->whereNotNull('rights')->get();

        foreach ($members as $member) {
            $rights = json_decode($member->rights, true);
            if (! is_array($rights) || ! array_key_exists('complaints', $rights)) {
                continue;
            }
            unset($rights['complaints']);
            DB::table('crm_members')->where('id', $member->id)
                ->update(['rights' => json_encode($rights)]);
        }
    }
};
