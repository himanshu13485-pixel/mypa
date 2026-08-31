<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two new ledgers:
 *
 * crm_pl_lines — the P&L's manual entries: what the books know that the
 * system does not (a tax provision, a cash expense, an odd income), one
 * dated line a side.
 *
 * crm_assets + crm_asset_events — the office asset register: everything the
 * company hands out (laptop, mobile, SIM, charger…), who holds it, what sits
 * in stock, what came back damaged — with the full life story per item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_pl_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->char('month', 7);                      // YYYY-MM
            $table->string('side', 8);                     // income | expense
            $table->string('label');
            $table->decimal('amount', 14, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'month']);
        });

        Schema::create('crm_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('category', 64);                // Laptop, Mobile, SIM…
            $table->string('name');
            $table->string('model_no', 128)->nullable();
            $table->string('color', 64)->nullable();
            $table->string('serial_no', 128)->nullable();
            $table->string('details', 512)->nullable();
            $table->string('status', 16)->default('in_stock');   // in_stock | allocated | damaged
            $table->foreignId('allocated_to_member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->dateTime('allocated_at')->nullable();
            $table->date('purchased_on')->nullable();
            $table->string('note', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'allocated_to_member_id']);
        });

        Schema::create('crm_asset_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('crm_assets')->cascadeOnDelete();
            $table->string('action', 24);                  // created | allocated | returned | damaged | repaired
            $table->foreignId('member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->string('note', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_asset_events');
        Schema::dropIfExists('crm_assets');
        Schema::dropIfExists('crm_pl_lines');
    }
};
