<?php

namespace App\Services\Crm;

use App\Models\Crm\Loan;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\SalaryStructure;
use Carbon\Carbon;

/**
 * One month's pay, computed the way the company's own sheet computes it.
 *
 * The CTC model: the payable side carries the employee's components
 * (prorated by the attendance calendar) plus the incentive plus the money
 * the employer puts into the statutory schemes on their behalf — PF, ESI,
 * EDLI, the welfare fund. The deduction side then recovers BOTH halves of
 * every scheme, employer and employee, so the net that reaches the bank is
 * earnings less the employee's own statutory share. That is exactly how
 * the sheet's SALARY PAYABLE and TOTAL DEDUCTION columns work, and it is
 * why its net for a 98,200 basic is 96,366: 98,200 − 1,800 PF − 34 welfare.
 *
 * Everything is returned as labelled lines, so a slip explains itself and
 * an Admin can still override any figure before paying.
 */
class SalaryCalculator
{
    public function __construct(
        private Organization $org,
        private IncentiveCalculator $incentives,
    ) {
    }

    /** The structure standing for this member in a given month, if any. */
    public function structureFor(Member $member, Carbon $month): ?SalaryStructure
    {
        return SalaryStructure::where('member_id', $member->id)
            ->whereDate('effective_from', '<=', $month->copy()->endOfMonth()->toDateString())
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Compute the month. $attendance is the member's row out of
     * AttendanceCalendar::summarise(), or null when the month holds no
     * evidence at all — in which case the month is paid whole rather than
     * zeroed by silence.
     *
     * @param  array<string, mixed>|null  $attendance
     * @return array<string, mixed>
     */
    public function compute(Member $member, Carbon $month, ?array $attendance): array
    {
        $policy = $this->org->hrPolicy();
        $structure = $this->structureFor($member, $month);

        // No structure yet: the old single salary number stands in as a
        // basic-only structure, so nothing breaks the day this ships.
        $basic = (float) ($structure?->basic ?? $member->currentSalary()?->amount ?? 0);
        $hra = (float) ($structure?->hra ?? 0);
        $components = collect($structure?->components ?? [])
            ->map(fn ($v) => round((float) $v, 2))
            ->filter(fn ($v) => $v != 0);

        $monthDays = $month->daysInMonth;
        $counted = $attendance !== null && ($attendance['has_attendance'] ?? false);
        $payableDays = $counted ? (float) $attendance['payable_days'] : (float) $monthDays;
        $ratio = $monthDays > 0 ? min(1, $payableDays / $monthDays) : 1;

        $pro = fn (float $v) => round($v * $ratio, 2);

        // ---- Earnings ------------------------------------------------------
        $earnings = [];
        $add = function (string $key, string $label, float $amount) use (&$earnings) {
            if (round($amount, 2) != 0) {
                $earnings[] = ['key' => $key, 'label' => $label, 'amount' => round($amount, 2)];
            }
        };

        $add('basic', 'Basic', $pro($basic));
        $add('hra', 'HRA', $pro($hra));
        foreach ($components as $key => $amount) {
            $add($key, SalaryStructure::COMPONENT_LABELS[$key] ?? ucwords(str_replace('_', ' ', $key)), $pro($amount));
        }

        // The incentive earned release_offset months ago rides this slip.
        $incentive = ['total' => 0.0, 'incentive_month' => null, 'plan' => 'none'];
        $plan = $this->incentives->planFor($member, $month);
        if ($plan && $plan->kind !== 'none') {
            $earned = $month->copy()->subMonthsNoOverflow(max(0, (int) $plan->release_offset_months));
            $incentive = $this->incentives->compute($member, $earned);
        }
        // A released hold pays inside this line, and a recovered sale takes
        // its money back through it — the label says both, because "why is
        // this month different?" must be answerable from the slip alone.
        $arrear = (float) ($incentive['arrear_total'] ?? 0);
        $recovery = (float) ($incentive['recovery_total'] ?? 0);
        $add('incentive', 'Incentive' . ($incentive['incentive_month']
            ? ' (for ' . Carbon::parse($incentive['incentive_month'] . '-01')->format('M Y') . ')' : '')
            . ($arrear > 0 ? ' — incl. arrear incentive release ' . number_format($arrear, 2) : '')
            . ($recovery > 0 ? ' — less incentive recovery ' . number_format($recovery, 2) . ' (sale returned)' : ''),
            (float) $incentive['total']);

        // ---- The employer's statutory money, into the payable --------------
        $proBasic = $pro($basic);
        $grossEarned = round(collect($earnings)->where('key', '!=', 'incentive')->sum('amount'), 2);
        // ESI wages exclude incentive money — the sheet takes Praveen's ESI
        // on his 18,500 salary gross, never on the 7,335 fixed incentive
        // riding beside it. The fixed-incentive component follows the same
        // rule as the plan-computed incentive.
        $esiBase = round(collect($earnings)
            ->whereNotIn('key', ['incentive', 'fix_allowance'])
            ->sum('amount'), 2);
        $cappedBasic = min($proBasic, (float) $policy['pf_wage_cap']);

        $employerPf = 0.0;
        $edli = 0.0;
        if ($structure?->has_pf) {
            $employerPf = round($cappedBasic * (float) $policy['pf_employer_rate'] / 100, 2);
            $add('employer_pf', 'PF — employer (' . $policy['pf_employer_rate'] . '% of capped basic)', $employerPf);
        }
        // Its own facility now — an employee can hold PF without it or
        // neither, when all they want is the discussed in-hand figure.
        if ($structure?->has_edli) {
            $edli = round($cappedBasic * (float) $policy['edli_rate'] / 100, 2);
            $add('edli', 'EDLI (' . $policy['edli_rate'] . '% of capped basic)', $edli);
        }

        $employerEsi = 0.0;
        if ($structure?->has_esi) {
            // ESI rounds UP to the rupee — the statutory rule, and the sheet's.
            $employerEsi = (float) ceil($esiBase * (float) $policy['esi_employer_rate'] / 100);
            $add('employer_esi', 'ESI — employer (' . $policy['esi_employer_rate'] . '% of gross)', $employerEsi);
        }

        $welfareEmployee = 0.0;
        $welfareEmployer = 0.0;
        if ($structure === null || $structure->has_welfare) {
            $welfareEmployee = round(min(
                $grossEarned * (float) $policy['welfare_employee_rate'] / 100,
                (float) $policy['welfare_employee_cap'],
            ), 2);
            $welfareEmployer = round($welfareEmployee * (float) $policy['welfare_employer_multiple'], 2);
            if ($structure !== null) {
                $add('welfare_employer', 'Welfare fund — employer', $welfareEmployer);
            } else {
                $welfareEmployee = $welfareEmployer = 0.0;
            }
        }

        $grossPayable = round(collect($earnings)->sum('amount'), 2);

        // ---- Deductions ----------------------------------------------------
        $deductions = [];
        $take = function (string $key, string $label, float $amount) use (&$deductions) {
            if (round($amount, 2) != 0) {
                $deductions[] = ['key' => $key, 'label' => $label, 'amount' => round($amount, 2)];
            }
        };

        // Both halves of every scheme come back out — the employer's share
        // was only ever passing through the payable on its way to the fund.
        if ($structure?->has_pf) {
            // Each side at its own rate — the law can move one without the other.
            $employeePf = round($cappedBasic * (float) $policy['pf_employee_rate'] / 100, 2);
            $take('pf', 'PF — employer + employee', round($employerPf + $employeePf, 2));
        }
        if ($edli > 0) {
            $take('edli', 'EDLI (employer)', $edli);
        }
        if ($structure?->has_esi) {
            $employeeEsi = (float) ceil($esiBase * (float) $policy['esi_employee_rate'] / 100);
            $take('esi', 'ESI — employer + employee', round($employerEsi + $employeeEsi, 2));
        }
        if ($welfareEmployee > 0) {
            $take('welfare', 'Welfare fund — employer + employee', round($welfareEmployer + $welfareEmployee, 2));
        }
        if ((float) ($structure?->pt_amount ?? 0) > 0) {
            $take('pt', 'Professional tax', (float) $structure->pt_amount);
        }
        if ((float) ($structure?->tds_monthly ?? 0) > 0) {
            $take('tds', 'TDS', (float) $structure->tds_monthly);
        }

        // Loans and advances work their way back out of the payroll.
        $loanLines = [];
        foreach (Loan::where('organization_id', $this->org->id)
            ->where('member_id', $member->id)
            ->where('status', 'open')
            ->get() as $loan) {
            $due = $loan->dueInstallment();
            if ($due > 0) {
                $take(
                    'loan_' . $loan->id,
                    ($loan->kind === 'advance' ? 'Salary advance adj.' : 'Loan repayment')
                        . ' (' . number_format($loan->balance(), 0) . ' left)',
                    $due,
                );
                $loanLines[] = ['loan_id' => $loan->id, 'amount' => $due];
            }
        }

        $totalDeductions = round(collect($deductions)->sum('amount'), 2);
        $net = round($grossPayable - $totalDeductions, 2);

        return [
            'structure_uuid' => $structure?->uuid,
            'monthly_salary' => $structure ? $structure->grossMonthly() : round($basic, 2),
            'month_days' => $counted ? $monthDays : null,
            'payable_days' => $counted ? $payableDays : null,
            'lop_days' => $counted ? (float) ($attendance['lop_days'] ?? 0) : 0,
            'earnings' => $earnings,
            'deduction_lines' => $deductions,
            'gross_payable' => $grossPayable,
            'total_deductions' => $totalDeductions,
            'incentive_amount' => (float) $incentive['total'],
            'incentive_breakdown' => $incentive,
            'incentive_month' => $incentive['incentive_month'],
            'net_salary' => $net,
            // The same month with the incentive stripped out — it enters the
            // payable and nothing on the deduction side keys off it.
            'net_without_incentive' => round($net - (float) $incentive['total'], 2),
            'loan_lines' => $loanLines,
        ];
    }
}
