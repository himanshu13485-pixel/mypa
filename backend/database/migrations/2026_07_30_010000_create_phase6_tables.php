<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Habits ---------------------------------------------------------
        Schema::create('habits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->enum('frequency', ['daily', 'weekly', 'monthly'])->default('daily');
            $table->unsignedInteger('target_per_period')->default(1);
            $table->string('icon', 64)->nullable();
            $table->string('color', 16)->nullable();
            $table->time('reminder_time')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('habit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
            $table->date('logged_on');
            $table->unsignedInteger('count')->default(1);
            $table->timestamps();
            $table->unique(['habit_id', 'logged_on']);
        });

        // --- Goals ----------------------------------------------------------
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 32)->default('personal'); // personal, family, work, health, financial
            $table->date('target_date')->nullable();
            $table->enum('status', ['active', 'completed', 'abandoned'])->default('active')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('motivation')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('goal_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('due_on')->nullable();
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // --- Bills ----------------------------------------------------------
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('category', 64)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 8)->default('INR');
            $table->date('due_on')->index();
            $table->enum('status', ['unpaid', 'paid'])->default('unpaid')->index();
            $table->string('repeat_frequency', 16)->nullable(); // monthly, quarterly, yearly, weekly
            $table->string('payment_account')->nullable();
            $table->unsignedInteger('remind_days_before')->default(3);
            $table->timestamp('last_reminded_at')->nullable();
            $table->foreignId('receipt_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // --- Subscription architecture -------------------------------------
        // Phase 6 keeps limits/features as JSON on plans; the Cashfree phase
        // (spec §34) normalizes into plan_prices/plan_features/plan_limits.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('monthly_price', 10, 2)->default(0);
            $table->decimal('annual_price', 10, 2)->default(0);
            $table->string('currency', 8)->default('INR');
            $table->unsignedInteger('trial_days')->default(0);
            $table->json('limits')->nullable();   // max_tasks, storage_bytes, max_groups, max_group_members, …
            $table->json('features')->nullable(); // calls, voice_assistant, reports_export, …
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_recommended')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->enum('status', ['active', 'trial', 'cancelled', 'expired'])->default('active')->index();
            $table->timestamp('started_at');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable(); // null = does not expire (free / manually granted)
            $table->timestamp('cancelled_at')->nullable();
            $table->string('note')->nullable(); // admin note for manual changes
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('bills');
        Schema::dropIfExists('goal_milestones');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('habit_logs');
        Schema::dropIfExists('habits');
    }
};
