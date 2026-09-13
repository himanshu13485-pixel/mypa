<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Other deductions, and a note for each kind of manual money.
 *
 * A slip's deductions are the statutory lines the structure works out - PF,
 * EDLI, welfare fund, loan recoveries - and nothing typed by hand belongs in
 * that total. Money held back for anything else (a canteen bill, an advance,
 * an unpaid absence) is its own head, with its own note.
 *
 * And the one note a slip had was printed under the deductions whatever it
 * was about, so "Extra bonus" ended up explaining the PF. Each note now sits
 * beside the figure it explains: an existing note moves to the additions when
 * the slip has additions - that is what it was written for - and to the other
 * deductions otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_salary_slips', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_salary_slips', 'addition_note')) {
                $table->string('addition_note', 512)->nullable();
            }
            if (! Schema::hasColumn('crm_salary_slips', 'other_deductions')) {
                $table->decimal('other_deductions', 12, 2)->default(0);
            }
            if (! Schema::hasColumn('crm_salary_slips', 'other_deduction_note')) {
                $table->string('other_deduction_note', 512)->nullable();
            }
        });

        DB::table('crm_salary_slips')
            ->whereNotNull('deduction_note')
            ->where('additions', '>', 0)
            ->update(['addition_note' => DB::raw('deduction_note'), 'deduction_note' => null]);

        DB::table('crm_salary_slips')
            ->whereNotNull('deduction_note')
            ->update(['other_deduction_note' => DB::raw('deduction_note'), 'deduction_note' => null]);
    }

    public function down(): void
    {
        DB::table('crm_salary_slips')
            ->whereNull('deduction_note')
            ->update(['deduction_note' => DB::raw('COALESCE(addition_note, other_deduction_note)')]);

        Schema::table('crm_salary_slips', function (Blueprint $table) {
            $table->dropColumn(['addition_note', 'other_deductions', 'other_deduction_note']);
        });
    }
};
