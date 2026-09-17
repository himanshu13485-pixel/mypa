<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lead's expected amount is not always in rupees.
 *
 * The field was labelled with a rupee sign and stored a bare number, so an
 * enquiry worth $8,000 was either written down as 8,000 - and read by
 * everyone afterwards as eight thousand rupees - or converted by hand on the
 * day, which is a guess that ages.
 *
 * The currency is the company's own list, kept in Billing setup, so adding
 * one is done once and appears wherever a currency is offered. Existing rows
 * default to INR, which is what they already were.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_leads') || Schema::hasColumn('crm_leads', 'currency')) {
            return;
        }

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->char('currency', 3)->default('INR')->after('amount');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('crm_leads') && Schema::hasColumn('crm_leads', 'currency')) {
            Schema::table('crm_leads', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }
};
