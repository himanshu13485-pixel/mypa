<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel database notifications
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Subtasks: a task may belong to a parent task
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('category_id')
                ->constrained('tasks')->cascadeOnDelete();
        });

        // Calendar events
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type', 32)->default('event'); // event, meeting, appointment, birthday, anniversary, holiday
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('location')->nullable();
            $table->string('meeting_link')->nullable();
            $table->string('color', 16)->nullable();
            $table->json('repeat_config')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'starts_at']);
        });

        Schema::create('event_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['invited', 'accepted', 'declined', 'tentative'])->default('invited');
            $table->timestamps();
            $table->unique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_participants');
        Schema::dropIfExists('events');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
        Schema::dropIfExists('notifications');
    }
};
