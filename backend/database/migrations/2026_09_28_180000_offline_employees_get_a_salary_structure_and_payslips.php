<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offline employees get a salary the way on-roll ones do.
 *
 * A structure - Basic, HRA, allowances, and any fixed deduction - with the
 * bank details a payslip prints. Each month's record keeps its own copy of
 * the structure it was made from, prorates it by the days paid, carries the
 * hand-typed additions and other deductions with their reasons, and knows
 * whether it has been paid. The net is still the figure the P&L counts.
 *
 * Records made before this are given the one line they always meant - the
 * monthly salary - so they read the same afterwards. Each column is checked
 * before it is added: MySQL cannot roll back a half-run migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'structure' => fn (Blueprint $t) => $t->json('structure')->nullable(),
            'designation' => fn (Blueprint $t) => $t->string('designation', 128)->nullable(),
            'joined_on' => fn (Blueprint $t) => $t->date('joined_on')->nullable(),
            'bank_name' => fn (Blueprint $t) => $t->string('bank_name', 128)->nullable(),
            'account_holder' => fn (Blueprint $t) => $t->string('account_holder', 128)->nullable(),
            'account_no' => fn (Blueprint $t) => $t->string('account_no', 64)->nullable(),
            'ifsc' => fn (Blueprint $t) => $t->string('ifsc', 32)->nullable(),
        ] as $column => $add) {
            if (! Schema::hasColumn('crm_offline_employees', $column)) {
                Schema::table('crm_offline_employees', $add);
            }
        }

        foreach ([
            'structure' => fn (Blueprint $t) => $t->json('structure')->nullable(),
            'month_days' => fn (Blueprint $t) => $t->unsignedTinyInteger('month_days')->nullable(),
            'lop_days' => fn (Blueprint $t) => $t->decimal('lop_days', 5, 2)->default(0),
            'payable_days' => fn (Blueprint $t) => $t->decimal('payable_days', 5, 2)->nullable(),
            'earnings' => fn (Blueprint $t) => $t->json('earnings')->nullable(),
            'deduction_lines' => fn (Blueprint $t) => $t->json('deduction_lines')->nullable(),
            'gross' => fn (Blueprint $t) => $t->decimal('gross', 12, 2)->default(0),
            'additions' => fn (Blueprint $t) => $t->decimal('additions', 12, 2)->default(0),
            'addition_note' => fn (Blueprint $t) => $t->string('addition_note', 500)->nullable(),
            'deductions' => fn (Blueprint $t) => $t->decimal('deductions', 12, 2)->default(0),
            'other_deductions' => fn (Blueprint $t) => $t->decimal('other_deductions', 12, 2)->default(0),
            'other_deduction_note' => fn (Blueprint $t) => $t->string('other_deduction_note', 500)->nullable(),
            'status' => fn (Blueprint $t) => $t->string('status', 16)->default('pending'),
            'payment_mode' => fn (Blueprint $t) => $t->string('payment_mode', 64)->nullable(),
        ] as $column => $add) {
            if (! Schema::hasColumn('crm_offline_salaries', $column)) {
                Schema::table('crm_offline_salaries', $add);
            }
        }

        // What an older record always meant: one monthly line, the whole month.
        foreach (DB::table('crm_offline_salaries')->whereNull('earnings')->get() as $row) {
            $line = [['key' => 'salary', 'label' => 'Monthly salary', 'amount' => round((float) $row->amount, 2)]];
            $days = Carbon::create((int) $row->year, (int) $row->month, 1)->daysInMonth;

            DB::table('crm_offline_salaries')->where('id', $row->id)->update([
                'structure' => json_encode(['earnings' => $line, 'deductions' => []]),
                'earnings' => json_encode($line),
                'deduction_lines' => json_encode([]),
                'gross' => round((float) $row->amount, 2),
                'month_days' => $days,
                'payable_days' => $days,
                'status' => $row->paid_on ? 'paid' : 'pending',
            ]);
        }
    }

    public function down(): void
    {
        $salaryColumns = ['structure', 'month_days', 'lop_days', 'payable_days', 'earnings', 'deduction_lines', 'gross',
            'additions', 'addition_note', 'deductions', 'other_deductions', 'other_deduction_note', 'status', 'payment_mode'];
        $present = array_values(array_filter($salaryColumns, fn ($c) => Schema::hasColumn('crm_offline_salaries', $c)));
        if ($present !== []) {
            Schema::table('crm_offline_salaries', fn (Blueprint $t) => $t->dropColumn($present));
        }

        $employeeColumns = ['structure', 'designation', 'joined_on', 'bank_name', 'account_holder', 'account_no', 'ifsc'];
        $present = array_values(array_filter($employeeColumns, fn ($c) => Schema::hasColumn('crm_offline_employees', $c)));
        if ($present !== []) {
            Schema::table('crm_offline_employees', fn (Blueprint $t) => $t->dropColumn($present));
        }
    }
};
