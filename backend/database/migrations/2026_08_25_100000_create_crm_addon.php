<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CRM addon. Everything lives in crm_* tables so the existing Netvork
 * schema is untouched; dropping these tables removes the addon completely.
 * Organizations exist from day one so the addon can be sold per-company
 * later without re-migrating every table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_organizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('code', 32)->unique();
            $table->string('status', 16)->default('active'); // active | suspended
            $table->json('settings')->nullable();            // departments, designations, taxes...
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('crm_members', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('crm_role', 16)->default('employee'); // admin | subadmin | employee
            $table->string('status', 16)->default('active');     // active | inactive
            // Employment profile (mirrors the old CRM's user master)
            $table->string('employee_code', 64)->nullable();
            $table->string('title', 8)->nullable();
            $table->string('department', 64)->nullable();
            $table->string('designation', 64)->nullable();
            $table->string('batch', 64)->nullable();
            $table->string('father_name')->nullable();
            $table->string('father_phone', 32)->nullable();
            $table->string('mother_name')->nullable();
            $table->string('mother_phone', 32)->nullable();
            $table->date('dob')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('present_address', 512)->nullable();
            $table->string('present_phone', 32)->nullable();
            $table->string('office_phone', 32)->nullable();
            $table->string('permanent_address', 512)->nullable();
            $table->string('permanent_phone', 32)->nullable();
            $table->string('personal_email')->nullable();
            $table->date('joined_at')->nullable();
            $table->date('resigned_at')->nullable();
            $table->boolean('is_salesperson')->default(false);
            // Statutory & bank
            $table->string('pf_no', 64)->nullable();
            $table->string('esi_no', 64)->nullable();
            $table->string('pan_no', 32)->nullable();
            $table->string('aadhaar_no', 32)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_no', 64)->nullable();
            $table->string('bank_ifsc', 32)->nullable();
            $table->string('bank_account_name')->nullable();
            $table->foreignId('reporting_to')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->json('rights')->nullable(); // { module: [view, create, edit, delete] }
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->unique(['organization_id', 'employee_code']);
        });

        Schema::create('crm_salary_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('crm_members')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');
            $table->date('effective_from');
            $table->string('note', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'effective_from']);
        });

        Schema::create('crm_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->morphs('documentable');
            $table->string('name');
            $table->string('path', 512);
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('crm_clients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('company_name');
            $table->string('title', 8)->nullable();
            $table->string('contact_person')->nullable();
            $table->string('designation', 128)->nullable();
            $table->string('address', 512)->nullable();
            $table->string('city', 128)->nullable();
            $table->string('state', 128)->nullable();
            $table->string('pincode', 16)->nullable();
            $table->string('country', 128)->nullable();
            $table->string('telephone', 32)->nullable();
            $table->string('mobile', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('alternate_email')->nullable();
            $table->string('website')->nullable();
            $table->string('gst_no', 32)->nullable();
            $table->string('pan_no', 32)->nullable();
            // new | existing | global_new | global_existing | sez_new | sez_existing
            $table->string('category', 32)->nullable();
            $table->foreignId('assigned_member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'company_name']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('crm_issuing_companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('address', 512)->nullable();
            $table->string('gstin', 32)->nullable();
            $table->string('pan', 32)->nullable();
            $table->string('state_code', 8)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('invoice_prefix', 16)->default('INV-');
            $table->string('proforma_prefix', 16)->default('PI-');
            $table->unsignedInteger('next_invoice_no')->default(1);
            $table->unsignedInteger('next_proforma_no')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('crm_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('label');
            $table->string('bank_name')->nullable();
            $table->string('account_no', 64)->nullable();
            $table->string('ifsc', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('crm_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->string('kind', 16); // proforma | invoice
            $table->string('number', 64);
            $table->foreignId('issuing_company_id')->nullable()->constrained('crm_issuing_companies')->nullOnDelete();
            $table->foreignId('client_id')->constrained('crm_clients')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('client_category', 32)->nullable();
            $table->string('pricing_tier', 16)->default('regular'); // regular | low
            $table->char('currency', 3)->default('INR');
            $table->string('terms_of_payment', 255)->nullable();
            $table->string('subscription_type', 16)->nullable(); // online | offline | both
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('cgst', 14, 2)->default(0);
            $table->decimal('sgst', 14, 2)->default(0);
            $table->decimal('igst', 14, 2)->default(0);
            $table->decimal('other_tax', 14, 2)->default(0);
            $table->decimal('tds', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            // Optional second currency (the old CRM billed some clients in USD)
            $table->char('fx_currency', 3)->nullable();
            $table->decimal('fx_rate', 10, 4)->nullable();
            $table->decimal('subtotal_fx', 14, 2)->nullable();
            $table->decimal('total_fx', 14, 2)->nullable();
            // due | partial | paid | refunded | credit_note | bad_debt
            $table->string('payment_status', 32)->default('due');
            // pending | partial | dispatched | in_process
            $table->string('dispatch_status', 32)->default('pending');
            $table->string('status', 16)->default('final'); // draft | final | cancelled
            $table->text('notes')->nullable();
            $table->foreignId('converted_from_id')->nullable()->constrained('crm_invoices')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'kind', 'number']);
            $table->index(['organization_id', 'kind', 'invoice_date']);
            $table->index(['organization_id', 'payment_status']);
        });

        Schema::create('crm_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->string('membership', 128)->nullable();
            $table->string('plan_name', 128)->nullable();
            $table->string('description', 512)->nullable();
            $table->date('validity_from')->nullable();
            $table->date('validity_to')->nullable();
            $table->decimal('qty', 10, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('amount_fx', 14, 2)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('crm_invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('crm_invoices')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('amount_fx', 14, 2)->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained('crm_bank_accounts')->nullOnDelete();
            $table->string('payment_mode', 64)->nullable();
            $table->string('reference_no', 128)->nullable();
            $table->string('drawee_bank', 128)->nullable();
            $table->date('instrument_date')->nullable();
            $table->date('received_at');
            $table->string('note', 512)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('crm_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('crm_organizations')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('crm_members')->nullOnDelete();
            $table->string('action', 64);
            $table->morphs('subject');
            $table->json('changes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activity_logs');
        Schema::dropIfExists('crm_invoice_payments');
        Schema::dropIfExists('crm_invoice_items');
        Schema::dropIfExists('crm_invoices');
        Schema::dropIfExists('crm_bank_accounts');
        Schema::dropIfExists('crm_issuing_companies');
        Schema::dropIfExists('crm_clients');
        Schema::dropIfExists('crm_documents');
        Schema::dropIfExists('crm_salary_records');
        Schema::dropIfExists('crm_members');
        Schema::dropIfExists('crm_organizations');
    }
};
