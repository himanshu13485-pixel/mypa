<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A task grows up.
 *
 * It was a title, a person and a due date - which is enough for "file the
 * returns by Friday" and nothing like enough for the thing people actually
 * use it for: accounts telling a salesperson that something on invoice
 * INV-200616 is pending from their side, and the two of them going back and
 * forth about it until it is not.
 *
 * So a task can now name the document it is about, carry a window it is
 * expected to run in rather than one deadline, say out loud that it was
 * edited after it was given out, and be talked about in its own thread. And
 * it can ask to be remembered: a reminder that appears when it is issued and
 * when it is replied to, that can be put off for a day, and that for
 * something due in three months does not start appearing today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            // What it is about. Nullable: most tasks are about nothing filed.
            $table->foreignId('invoice_id')->nullable()->after('assigned_by')
                ->constrained('crm_invoices')->nullOnDelete();
            // A pendency is a task that says "this is waiting on you".
            $table->string('kind', 16)->default('task')->after('priority'); // task | pendency

            // The window it is expected to run in, beside the deadline.
            $table->dateTime('start_at')->nullable()->after('due_at');
            $table->dateTime('end_at')->nullable()->after('start_at');

            // Changed after it was handed over, and by whom.
            $table->dateTime('edited_at')->nullable()->after('review_note');
            $table->foreignId('edited_by')->nullable()->after('edited_at')
                ->constrained('crm_members')->nullOnDelete();

            /*
             * When to put this in front of somebody again, and how often
             * after that. Null in `remind_at` means never - a reminder
             * nobody asked for is just an interruption.
             */
            $table->dateTime('remind_at')->nullable()->after('edited_by');
            $table->unsignedSmallInteger('remind_every_days')->nullable()->after('remind_at');
            // "Not today." Set by whoever is being reminded, honoured for both.
            $table->dateTime('assignee_snoozed_until')->nullable()->after('remind_every_days');
            $table->dateTime('assigner_snoozed_until')->nullable()->after('assignee_snoozed_until');
            // Who owes the next word: the reminder follows the answer.
            $table->dateTime('last_reply_at')->nullable()->after('assigner_snoozed_until');
            $table->string('awaiting', 16)->nullable()->after('last_reply_at'); // assignee | assigner

            $table->index(['organization_id', 'remind_at']);
        });

        // The conversation on a task. Both sides write here; nobody edits.
        Schema::create('crm_task_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('crm_tasks')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['task_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_task_comments');

        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'remind_at']);
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('edited_by');
            $table->dropColumn([
                'kind', 'start_at', 'end_at', 'edited_at', 'remind_at', 'remind_every_days',
                'assignee_snoozed_until', 'assigner_snoozed_until', 'last_reply_at', 'awaiting',
            ]);
        });
    }
};
