<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bank account belongs to a registered company: the invoice then prints
 * ITS company's account, never a sister company's — and the Billing setup
 * can read the association both ways.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_bank_accounts', function (Blueprint $table) {
            $table->foreignId('issuing_company_id')->nullable()
                ->after('organization_id')
                ->constrained('crm_issuing_companies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_bank_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issuing_company_id');
        });
    }
};
