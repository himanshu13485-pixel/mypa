<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paying a proforma or an invoice online.
 *
 * Each company brings its own Cashfree account — the money must land in that
 * company's bank, not ours — so the credentials are per organization and the
 * secret is encrypted at rest. A link is raised against one document for one
 * amount, and the gateway tells us when it is paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('provider', 32)->default('cashfree');
            $table->string('mode', 16)->default('sandbox');   // sandbox | production
            $table->string('app_id')->nullable();
            $table->text('secret')->nullable();               // encrypted
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'provider']);
        });

        Schema::create('crm_payment_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->string('provider', 32)->default('cashfree');
            // Ours, and theirs. `link_id` is what the webhook comes back with.
            $table->string('link_id', 64);
            $table->string('cf_link_id', 64)->nullable();
            $table->text('link_url')->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->char('currency', 3)->default('INR');
            $table->string('purpose', 255)->nullable();
            // active | paid | partially_paid | expired | cancelled | failed
            $table->string('status', 24)->default('active');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            // What the gateway last told us, kept for when a figure is disputed.
            $table->json('last_event')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'link_id']);
            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_payment_links');
        Schema::dropIfExists('crm_payment_gateways');
    }
};
