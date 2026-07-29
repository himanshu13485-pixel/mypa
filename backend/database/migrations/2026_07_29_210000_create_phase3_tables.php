<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- Groups (family / team) -----------------------------------------
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32)->default('family'); // family, team, business, other
            $table->string('description')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('color', 16)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('member'); // owner, admin, manager, member, viewer
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['group_id', 'user_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('parent_id')
                ->constrained()->nullOnDelete();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
        });

        // --- Notes ----------------------------------------------------------
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->string('type', 32)->default('text'); // text, checklist
            $table->json('checklist')->nullable();
            $table->string('color', 16)->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->string('password_hash')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'is_pinned']);
        });

        Schema::create('note_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->json('checklist')->nullable();
            $table->timestamps();
        });

        Schema::create('note_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('permission', ['view', 'edit'])->default('view');
            $table->timestamps();
            $table->unique(['note_id', 'user_id']);
        });

        // --- Files ----------------------------------------------------------
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'parent_id']);
        });

        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('path', 500);
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'folder_id']);
        });

        Schema::create('file_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('permission', ['view', 'edit'])->default('view');
            $table->timestamps();
            $table->unique(['file_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_shares');
        Schema::dropIfExists('files');
        Schema::dropIfExists('folders');
        Schema::dropIfExists('note_users');
        Schema::dropIfExists('note_versions');
        Schema::dropIfExists('notes');
        Schema::table('events', fn (Blueprint $table) => $table->dropConstrainedForeignId('group_id'));
        Schema::table('tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('group_id'));
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('groups');
    }
};
