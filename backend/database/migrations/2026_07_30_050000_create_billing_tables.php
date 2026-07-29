<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // All monetary values are stored in minor units (paise) as integers —
        // no floating-point arithmetic anywhere in the billing path.
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('title');
            $table->string('description')->nullable();
            $table->enum('discount_type', ['fixed', 'percent']);
            $table->unsignedBigInteger('discount_value'); // paise for fixed, basis points for percent
            $table->unsignedBigInteger('max_discount_amount')->nullable(); // paise
            $table->unsignedBigInteger('min_order_amount')->nullable();    // paise
            $table->json('applicable_plans')->nullable();       // slugs; null = all
            $table->json('applicable_frequencies')->nullable(); // monthly/annual; null = both
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->boolean('new_users_only')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_number', 32)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->enum('billing_frequency', ['monthly', 'annual']);
            $table->unsignedBigInteger('base_amount');     // paise
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->string('currency', 8)->default('INR');
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['created', 'pending', 'paid', 'failed', 'cancelled', 'expired'])
                ->default('created')->index();
            $table->string('gateway_order_id')->nullable()->index();
            $table->string('payment_session_id', 500)->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->json('customer_snapshot')->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway_payment_id')->nullable()->index();
            $table->unsignedBigInteger('amount'); // paise
            $table->string('currency', 8)->default('INR');
            $table->enum('status', ['successful', 'failed', 'pending', 'refunded', 'partially_refunded'])
                ->default('pending')->index();
            $table->string('method', 64)->nullable();
            $table->json('gateway_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32)->default('cashfree');
            $table->string('event_id')->nullable();
            $table->string('event_type', 64)->nullable()->index();
            $table->string('dedupe_hash', 64)->unique();
            $table->json('payload');
            $table->boolean('signature_valid')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('invoice_number', 32)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('plan_name');
            $table->enum('billing_frequency', ['monthly', 'annual']);
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->unsignedBigInteger('base_amount');
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->string('currency', 8)->default('INR');
            $table->string('tax_label', 32)->nullable();
            $table->unsignedInteger('tax_percent_bp')->default(0); // basis points, e.g. 1800 = 18%
            $table->json('billing_snapshot')->nullable(); // buyer name/email, seller details
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_order_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index(['coupon_id', 'user_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('gateway_refund_id')->nullable();
            $table->unsignedBigInteger('amount'); // paise
            $table->string('reason', 500)->nullable();
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('gateway_response')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payment_webhooks');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_orders');
    }
};
