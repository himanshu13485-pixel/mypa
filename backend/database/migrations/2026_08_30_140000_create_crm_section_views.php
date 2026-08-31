<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each person last looked at each section.
 *
 * The sidebar counts what colleagues have done since — "Leads 2", "Payments
 * 5" — and the number goes away when the section is opened. One row per
 * person per section; the absence of a row means they have never looked, so
 * everything since they joined is new to them.
 *
 * The marker is the last trail entry they had seen, not a clock reading: two
 * things inside one second must not make one of them invisible forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_section_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->string('section', 32);
            $table->unsignedBigInteger('last_activity_id')->default(0);
            $table->dateTime('seen_at');
            $table->timestamps();
            $table->unique(['member_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_section_views');
    }
};
