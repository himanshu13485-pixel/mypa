<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three things a project and a note were missing.
 *
 * A project entry is a line in a ledger - "cement, 50 bags, 18,400" - and
 * the bill for it lived in somebody's phone. Now it can live with the entry,
 * and ride out with the daily report when the entry changes.
 *
 * A note behind a password had no way back if the password went with
 * somebody who left, which made a forgotten password the same thing as a
 * lost note. A code to the owner's own address is the way back, on the same
 * terms the chat password already uses.
 *
 * And a note can send its own daily report, which projects have had all
 * along and notes - where people keep the thing they are about to forget -
 * had not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_entry_files')) {
            Schema::create('project_entry_files', function (Blueprint $table) {
                $table->id();
                $table->uuid()->unique();
                $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
                $table->foreignId('project_entry_id')->constrained('project_entries')->cascadeOnDelete();
                $table->string('name');
                $table->string('path');
                $table->string('mime')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('project_entry_id');
            });
        }

        // A note's own way back in, on the same terms a project already has.
        Schema::table('notes', function (Blueprint $table) {
            if (! Schema::hasColumn('notes', 'reset_code_hash')) {
                $table->string('reset_code_hash')->nullable()->after('password_hash');
                $table->timestamp('reset_code_expires_at')->nullable()->after('reset_code_hash');
            }
            if (! Schema::hasColumn('notes', 'daily_report')) {
                $table->boolean('daily_report')->default(false)->after('reset_code_expires_at');
                $table->timestamp('last_reported_at')->nullable()->after('daily_report');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_entry_files');

        Schema::table('notes', function (Blueprint $table) {
            foreach (['reset_code_hash', 'reset_code_expires_at', 'daily_report', 'last_reported_at'] as $column) {
                if (Schema::hasColumn('notes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
