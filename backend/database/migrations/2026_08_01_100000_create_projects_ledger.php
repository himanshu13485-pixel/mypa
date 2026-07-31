<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('purpose', 64)->default('general'); // construction, business, personal…
            $table->string('base_currency', 8)->default('INR');
            $table->text('notes')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_archived']);
        });

        Schema::create('project_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('description');
            $table->enum('direction', ['credit', 'debit']); // credit = money in, debit = money out
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('INR');
            $table->enum('mode', ['cash', 'bank'])->default('cash');
            $table->string('bank_account')->nullable();
            $table->string('counterparty')->nullable(); // to whom given / from whom taken
            $table->timestamp('reminder_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'entry_date']);
            $table->index(['reminder_at', 'reminder_sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_entries');
        Schema::dropIfExists('projects');
    }
};
