<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remarks kept beside the payroll: on one person's salary, or on the month.
 *
 * Why somebody was paid less than their usual, what an addition was for,
 * which month the arrears belong to. The slip's own note fields answer for
 * one figure each (an addition, a deduction); this is the room to write down
 * anything else, as many times as the month needs it.
 *
 * Hung on the person and the month rather than on the slip row, deliberately.
 * Recalculating a slip deletes it and builds a fresh one - it is a button on
 * the register, pressed all the time - and a remark tied to the row would go
 * with it without a word. The person and the month do not change.
 *
 * member_id null means the payroll month as a whole.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_salary_notes')) {
            return;
        }

        Schema::create('crm_salary_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->smallInteger('year');
            $table->tinyInteger('month');
            $table->foreignId('member_id')->nullable()->constrained('crm_members')->cascadeOnDelete();
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_salary_notes');
    }
};
