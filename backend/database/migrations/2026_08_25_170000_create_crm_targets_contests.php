<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Targets and Contests.
 *
 * A target row stores only the number a manager set. Achievement is never
 * typed in — it is computed live from the invoices a salesperson actually
 * raised, so the target screen can't disagree with the ledger (the old CRM
 * kept both by hand and they drifted).
 *
 * A contest is a timed quiz: questions carry options, the right answer and
 * points; every member answers once per question and the leaderboard falls
 * out of the answers table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month'); // 1-12
            $table->decimal('target_amount', 14, 2)->default(0);
            $table->string('note', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'member_id', 'year', 'month']);
            $table->index(['organization_id', 'year', 'month']);
        });

        Schema::create('crm_contests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 16)->default('draft'); // draft | published | closed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'starts_at']);
        });

        Schema::create('crm_contest_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contest_id')->constrained('crm_contests')->cascadeOnDelete();
            $table->string('type', 16)->default('option'); // option | text
            $table->text('question');
            $table->json('options')->nullable();            // ["A", "B", "C", "D"]
            $table->unsignedTinyInteger('correct_option')->nullable(); // 0-based index
            $table->string('correct_text')->nullable();     // for auto-grading text answers
            $table->unsignedSmallInteger('points')->default(10);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('crm_contest_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('crm_contest_questions')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->unsignedTinyInteger('answer_option')->nullable();
            $table->text('answer_text')->nullable();
            // null = awaiting manual grading (free-text without a model answer)
            $table->boolean('is_correct')->nullable();
            $table->unsignedSmallInteger('points_awarded')->default(0);
            $table->timestamps();
            $table->unique(['question_id', 'member_id']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contest_answers');
        Schema::dropIfExists('crm_contest_questions');
        Schema::dropIfExists('crm_contests');
        Schema::dropIfExists('crm_targets');
    }
};
