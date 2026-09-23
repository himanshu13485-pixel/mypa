<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account somebody abroad can actually pay into.
 *
 * A bank name, an account number and an IFSC is everything a rupee transfer
 * needs and nothing a wire from America does: that wants a SWIFT/BIC, the
 * receiving bank by name and address, a routing number, often an
 * intermediary bank, and the beneficiary written exactly as the bank holds
 * it. Invoices going out of Corpcio Global LLC were carrying Indian details
 * their clients could not use.
 *
 * So an account can be marked as one for international payment, and carries
 * the fields such a payment needs. Whatever is filled in prints on the
 * invoice; whatever is blank prints nothing at all. A note rides along
 * either way - "quote the invoice number in the reference" is the sort of
 * thing that saves a fortnight of chasing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_bank_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('crm_bank_accounts', 'is_swift')) {
                return;
            }

            $table->boolean('is_swift')->default(false)->after('is_active');
            $table->string('beneficiary_name')->nullable()->after('is_swift');
            $table->string('swift_code', 32)->nullable()->after('beneficiary_name');
            $table->string('receiving_bank')->nullable()->after('swift_code');
            $table->string('aba_routing', 64)->nullable()->after('receiving_bank');
            $table->string('aba_routing_alt', 64)->nullable()->after('aba_routing');
            $table->string('intermediary_swift', 64)->nullable()->after('aba_routing_alt');
            $table->string('account_type', 64)->nullable()->after('intermediary_swift');
            $table->string('beneficiary_address', 500)->nullable()->after('account_type');
            $table->string('receiving_bank_address', 500)->nullable()->after('beneficiary_address');
            // On every account, wired or not.
            $table->string('note', 500)->nullable()->after('receiving_bank_address');
        });
    }

    public function down(): void
    {
        Schema::table('crm_bank_accounts', function (Blueprint $table) {
            foreach ([
                'is_swift', 'beneficiary_name', 'swift_code', 'receiving_bank', 'aba_routing', 'aba_routing_alt',
                'intermediary_swift', 'account_type', 'beneficiary_address', 'receiving_bank_address', 'note',
            ] as $column) {
                if (Schema::hasColumn('crm_bank_accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
