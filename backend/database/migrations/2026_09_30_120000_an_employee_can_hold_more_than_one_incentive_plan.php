<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More than one incentive structure per person.
 *
 * One plan per employee assumed every sale is worth the same kind of money,
 * and the sheet never worked that way: the ordinary business runs on the
 * usual percentage, and a particular work order - an enterprise term, a
 * fixed-fee listing - is sold on its own terms. Companies were choosing
 * between the two.
 *
 * So a plan is named. "Type-1" is the default, applied to everything that
 * does not say otherwise; "Type-2" and any others stand beside it and are
 * applied where they are earned. Existing plans become Type-1 by the column
 * defaults, which is exactly what they already were.
 *
 * What earns Type-2 is decided in Billing setup, per plan name on the work
 * order - and an invoice can be pointed at a different structure by hand,
 * which is the incentive_plan_name below.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_incentive_plans')) {
            Schema::table('crm_incentive_plans', function (Blueprint $table) {
                if (! Schema::hasColumn('crm_incentive_plans', 'name')) {
                    $table->string('name', 40)->default('Type-1')->after('member_id');
                }
            });

            // Separately: MySQL cannot roll back half a migration, so a
            // re-run after a failure must not trip over its own first column.
            Schema::table('crm_incentive_plans', function (Blueprint $table) {
                if (! Schema::hasColumn('crm_incentive_plans', 'is_default')) {
                    $table->boolean('is_default')->default(true)->after('name');
                }
            });
        }

        if (Schema::hasTable('crm_invoices')) {
            Schema::table('crm_invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('crm_invoices', 'incentive_plan_name')) {
                    // Set by hand, by an Admin or a Subadmin the Admin named:
                    // this whole document pays under that structure whatever
                    // its work orders would otherwise have chosen.
                    $table->string('incentive_plan_name', 40)->nullable()->after('member_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('crm_invoices')) {
            Schema::table('crm_invoices', function (Blueprint $table) {
                if (Schema::hasColumn('crm_invoices', 'incentive_plan_name')) {
                    $table->dropColumn('incentive_plan_name');
                }
            });
        }

        if (Schema::hasTable('crm_incentive_plans')) {
            Schema::table('crm_incentive_plans', function (Blueprint $table) {
                foreach (['is_default', 'name'] as $column) {
                    if (Schema::hasColumn('crm_incentive_plans', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
