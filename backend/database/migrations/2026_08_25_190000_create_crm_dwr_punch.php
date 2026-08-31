<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DWR + Punch Report.
 *
 * The old CRM hard-coded ~78 KPI columns onto one giant table. Here the org
 * keeps a KPI catalog, each employee is assigned parameters with a weightage
 * and a daily target, and a DWR stores entries against that assignment —
 * with the weightage/target snapshotted per entry so history survives
 * later re-weighting. The performance band falls out of the arithmetic.
 *
 * A punch is one row per member per day: in/out stamps, the IPs they came
 * from, and a status that is computed on punch but can be overridden by an
 * admin (Sunday / holiday / half day), exactly like the old dropdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_kpi_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('name', 128);
            $table->string('unit', 16)->default('count'); // count | percent | currency | boolean
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('crm_member_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->foreignId('parameter_id')->constrained('crm_kpi_parameters')->cascadeOnDelete();
            $table->unsignedSmallInteger('weightage')->default(10); // percent share
            $table->decimal('daily_target', 14, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['member_id', 'parameter_id']);
        });

        Schema::create('crm_dwrs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->date('work_date');
            $table->text('note')->nullable();
            $table->decimal('score', 5, 1)->nullable();      // weighted achievement %
            $table->string('band', 24)->nullable();          // outstanding | good | needs_improvement | pip
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'member_id', 'work_date']);
            $table->index(['organization_id', 'work_date']);
        });

        Schema::create('crm_dwr_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dwr_id')->constrained('crm_dwrs')->cascadeOnDelete();
            $table->foreignId('parameter_id')->nullable()->constrained('crm_kpi_parameters')->nullOnDelete();
            // Snapshots: the name/target/weightage as they stood that day.
            $table->string('name', 128);
            $table->string('unit', 16)->default('count');
            $table->unsignedSmallInteger('weightage')->default(0);
            $table->decimal('target', 14, 2)->default(0);
            $table->decimal('value', 14, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('crm_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->date('work_date');
            $table->dateTime('punch_in')->nullable();
            $table->dateTime('punch_out')->nullable();
            $table->string('in_ip', 64)->nullable();
            $table->string('out_ip', 64)->nullable();
            // present | late | half_day | sunday | holiday | absent
            $table->string('status', 16)->default('present');
            $table->string('status_source', 8)->default('auto'); // auto | manual
            $table->string('note', 512)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'member_id', 'work_date']);
            $table->index(['organization_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_punches');
        Schema::dropIfExists('crm_dwr_entries');
        Schema::dropIfExists('crm_dwrs');
        Schema::dropIfExists('crm_member_kpis');
        Schema::dropIfExists('crm_kpi_parameters');
    }
};
