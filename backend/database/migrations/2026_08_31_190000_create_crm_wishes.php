<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wishes wall: on a festival (or a birthday) people wish each other
 * from the CRM itself, and the wall keeps the history occasion by occasion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_wishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->string('occasion', 128);
            $table->string('message', 512);
            $table->timestamps();
            $table->index(['organization_id', 'occasion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_wishes');
    }
};
