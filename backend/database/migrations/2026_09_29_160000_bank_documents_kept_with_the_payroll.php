<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The paperwork a payroll run leaves behind: the bank's own documents.
 *
 * The NEFT advice, the salary transfer confirmation, the statement page that
 * proves the month went out. They live in somebody's mail or on a desktop
 * until the month they are asked for, which is the month nobody can find
 * them.
 *
 * Kept per payroll month, on the private disk with everything else that is
 * nobody's business but the office's, and each one carries a note of its own
 * - what it is, which bank, which batch - because a file named
 * "Document(3).pdf" answers nothing a year later.
 *
 * member_id is here for the same reason it is on crm_salary_notes: a
 * document may belong to one person's pay rather than to the whole run.
 * Null means the run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_salary_documents')) {
            return;
        }

        Schema::create('crm_salary_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->smallInteger('year');
            $table->tinyInteger('month');
            $table->foreignId('member_id')->nullable()->constrained('crm_members')->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_salary_documents');
    }
};
