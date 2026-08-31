<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated Company Workspace (DCW) fields.
 *
 * A company can ask for extra fields on a form — starting with Client, and
 * designed for the other sections that will follow. The request goes to the
 * Super Admin; once approved the field exists in THAT company's workspace
 * only, never across the platform.
 *
 * Values live in a JSON column on the owning record, keyed by field key, so
 * adding a field never migrates anyone else's tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_custom_fields', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('entity', 32)->default('client'); // client | (more later)
            $table->string('key', 64);                       // machine name in the JSON
            $table->string('label');
            // text | textarea | number | alphanumeric | checkbox | date | select
            $table->string('type', 24)->default('text');
            $table->json('options')->nullable();             // for select
            $table->boolean('is_required')->default(false);
            $table->string('help', 255)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('status', 16)->default('pending'); // pending|approved|rejected
            $table->string('reason', 512)->nullable();        // why the company wants it
            $table->foreignId('requested_by')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 512)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'entity', 'key']);
            $table->index(['organization_id', 'entity', 'status']);
            $table->index('status');
        });

        Schema::table('crm_clients', function (Blueprint $table) {
            $table->json('custom_fields')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('crm_clients', function (Blueprint $table) {
            $table->dropColumn('custom_fields');
        });
        Schema::dropIfExists('crm_custom_fields');
    }
};
