<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last five heads: Leaves, Tasks, the Approval register, Invoice Update
 * requests, Newsletters and the notice board (CMS).
 *
 * Leaves and tasks carry their own state machines; the Approvals screen is
 * partly a register of its own (the old CRM's money/error approvals) and
 * partly an inbox over the other modules' pending work. Invoice updates are
 * proposed as a JSON diff and applied only on approval, so a final invoice
 * never changes without a second pair of eyes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leaves', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->string('category', 64);                 // Sick / Casual / Paid…
            $table->string('duration', 16)->default('full'); // full | half | quarter
            $table->date('date_from');
            $table->date('date_to');
            $table->decimal('days', 5, 2)->default(1);
            $table->string('reason', 1000)->nullable();
            $table->string('status', 16)->default('pending'); // pending|approved|rejected|cancelled
            $table->foreignId('decided_by')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 512)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'member_id', 'date_from']);
        });

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assigned_member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->dateTime('due_at')->nullable();
            $table->string('priority', 16)->default('normal'); // low|normal|high|urgent
            // open → in_progress → submitted → done | reopened(→in_progress)
            $table->string('status', 16)->default('open');
            $table->string('progress_note', 1000)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note', 512)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'assigned_member_id', 'status']);
        });

        Schema::create('crm_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('type', 64);                    // First Approval, Executive Error…
            $table->date('approval_date');
            $table->foreignId('issuing_company_id')->nullable()->constrained('crm_issuing_companies')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('crm_invoices')->nullOnDelete();
            $table->decimal('amount', 14, 2)->default(0);
            $table->text('details')->nullable();
            $table->foreignId('requested_by')->constrained('crm_members')->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending|approved|rejected
            $table->foreignId('decided_by')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 512)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'approval_date']);
        });

        Schema::create('crm_invoice_update_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->json('changes');                       // {field: proposed value}
            $table->string('reason', 1000)->nullable();
            $table->foreignId('requested_by')->constrained('crm_members')->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending|approved|rejected
            $table->foreignId('decided_by')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 512)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('crm_newsletters', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('subject');
            $table->text('body');                          // HTML
            $table->string('audience', 32)->default('active_clients'); // active_clients|all_clients|leads|custom
            $table->json('custom_recipients')->nullable(); // for audience=custom
            $table->string('status', 16)->default('draft'); // draft|sent
            $table->dateTime('sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('crm_cms_posts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('kind', 32)->default('announcement'); // announcement|policy|holiday|news
            $table->boolean('is_pinned')->default(false);
            $table->string('status', 16)->default('published'); // draft|published
            $table->date('publish_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'is_pinned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_cms_posts');
        Schema::dropIfExists('crm_newsletters');
        Schema::dropIfExists('crm_invoice_update_requests');
        Schema::dropIfExists('crm_approvals');
        Schema::dropIfExists('crm_tasks');
        Schema::dropIfExists('crm_leaves');
    }
};
