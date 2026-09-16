<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two questions that were one field.
 *
 * "Client status" held six words - New, Existing, Global-New, Global-Existing,
 * SEZ-New, SEZ-Existing - and answered two different things at once: is this
 * client new to us, and what kind of business is this. So a person raising a
 * document had to know both, and picked the first plausible word.
 *
 * Now the status is New or Existing and the system works it out (the first
 * document a client is ever given is new business, every one after it is
 * repeat business), and the kind of business is its own field, out of a list
 * the company keeps in Billing setup. Everything already raised is Regular
 * unless its old word said otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_invoices', 'client_segment')) {
            Schema::table('crm_invoices', function (Blueprint $table) {
                $table->string('client_segment', 64)->nullable();
            });
        }

        DB::table('crm_invoices')->whereNull('client_segment')->update(['client_segment' => 'Regular']);
        DB::table('crm_invoices')->whereIn('client_category', ['global_new', 'global_existing'])
            ->update(['client_segment' => 'Global']);
        DB::table('crm_invoices')->whereIn('client_category', ['sez_new', 'sez_existing'])
            ->update(['client_segment' => 'SEZ']);

        DB::table('crm_invoices')->whereIn('client_category', ['global_new', 'sez_new'])
            ->update(['client_category' => 'new']);
        DB::table('crm_invoices')->whereIn('client_category', ['global_existing', 'sez_existing'])
            ->update(['client_category' => 'existing']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_invoices', 'client_segment')) {
            Schema::table('crm_invoices', fn (Blueprint $table) => $table->dropColumn('client_segment'));
        }
    }
};
