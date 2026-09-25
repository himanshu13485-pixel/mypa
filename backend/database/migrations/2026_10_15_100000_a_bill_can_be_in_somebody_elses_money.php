<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An expense in the currency it was actually paid in.
 *
 * A company with an export arm buys abroad as well as selling abroad - a
 * hosting bill in dollars, a fair in euros, an agent's fee in pounds - and
 * until now every bill was entered as though the figure on it were rupees.
 * Sales already work this way; the purchase side did not.
 *
 * The books stay in rupees all the same, so the rate is frozen onto the
 * bill as it is saved, exactly as an invoice freezes its own, and a rupee
 * figure is stored beside the real one. Every total the office reads - the
 * P&L, the reports, a vendor's ledger - adds up that rupee column, so a
 * $500 bill can never quietly become ₹500 in a yearly figure.
 *
 * Bills already entered are rupee bills, which is what they always were:
 * currency INR, rate 1, and the rupee column filled from the total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('crm_expenses', 'currency')) {
                $table->char('currency', 3)->default('INR')->after('description');
            }
            if (! Schema::hasColumn('crm_expenses', 'fx_rate')) {
                $table->decimal('fx_rate', 12, 4)->nullable()->after('currency');
            }
            if (! Schema::hasColumn('crm_expenses', 'total_inr')) {
                $table->decimal('total_inr', 14, 2)->default(0)->after('total_amount');
            }
        });

        // What every existing bill already was.
        DB::table('crm_expenses')->where('total_inr', 0)->update([
            'total_inr' => DB::raw('total_amount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('crm_expenses', function (Blueprint $table) {
            foreach (['currency', 'fx_rate', 'total_inr'] as $column) {
                if (Schema::hasColumn('crm_expenses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
