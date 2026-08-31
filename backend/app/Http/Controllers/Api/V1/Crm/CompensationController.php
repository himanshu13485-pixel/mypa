<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\IncentivePlan;
use App\Models\Crm\Loan;
use App\Models\Crm\Member;
use App\Models\Crm\SalaryStructure;
use App\Services\Crm\IncentiveCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The compensation tab of an employee: their CTC structure, their incentive
 * plan, and the loans working their way back out of the payroll.
 *
 * Structures and plans are dated rows, never edits-in-place — a raise or a
 * new slab starts from its month and every old payslip keeps the terms it
 * was computed under. Managing any of it rides the salary module rights.
 */
class CompensationController extends Controller
{
    /** Everything the employee's compensation card needs, in one read. */
    public function show(Request $request, string $memberUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $member = $this->member($request, $memberUuid);

        $structures = SalaryStructure::with('creator:id,name')
            ->where('member_id', $member->id)
            ->orderByDesc('effective_from')
            ->get();
        $plans = IncentivePlan::with('creator:id,name')
            ->where('member_id', $member->id)
            ->orderByDesc('effective_from')
            ->get();
        $loans = Loan::with('repayments')
            ->where('organization_id', $org->id)
            ->where('member_id', $member->id)
            ->orderByDesc('taken_on')
            ->get();

        $policy = $org->hrPolicy();

        return response()->json(['data' => [
            'member' => ['uuid' => $member->uuid, 'name' => $member->user?->name],
            'component_labels' => SalaryStructure::COMPONENT_LABELS,
            // The standard structure: the HR Policy's rates for the live
            // in-hand preview, and which facilities a new structure starts
            // inside. Every switch stays individual — some staff take none.
            'statutory' => [
                'pf_employer_rate' => (float) $policy['pf_employer_rate'],
                'pf_employee_rate' => (float) $policy['pf_employee_rate'],
                'pf_wage_cap' => (float) $policy['pf_wage_cap'],
                'esi_employer_rate' => (float) $policy['esi_employer_rate'],
                'esi_employee_rate' => (float) $policy['esi_employee_rate'],
                'edli_rate' => (float) $policy['edli_rate'],
                'welfare_employee_rate' => (float) $policy['welfare_employee_rate'],
                'welfare_employee_cap' => (float) $policy['welfare_employee_cap'],
                'welfare_employer_multiple' => (float) $policy['welfare_employer_multiple'],
            ],
            'incentive_defaults' => [
                'spread_months' => (int) $policy['incentive_spread_months'],
            ],
            // The payment gate as it applies to THIS person: the house rule,
            // their own override if any, and what actually governs.
            'payment_gate' => [
                'policy' => (bool) ($policy['incentive_needs_full_payment'] ?? true),
                'override' => $member->incentive_needs_payment,
                'effective' => (new IncentiveCalculator($org))->paymentGate($member),
            ],
            'scheme_defaults' => [
                'has_pf' => (bool) $policy['pf_default'],
                'has_edli' => (bool) $policy['edli_default'],
                'has_esi' => (bool) $policy['esi_default'],
                'has_welfare' => (bool) $policy['welfare_default'],
            ],
            'plan_kinds' => IncentivePlan::KINDS,
            'structures' => $structures->map(fn (SalaryStructure $s) => $this->structureRow($s)),
            'plans' => $plans->map(fn (IncentivePlan $p) => $this->planRow($p)),
            'loans' => $loans->map(fn (Loan $l) => $this->loanRow($l)),
            // The single legacy number, so the card can say what stands in
            // while no structure exists yet.
            'legacy_salary' => $member->currentSalary()?->amount,
        ]]);
    }

    // ---- The CTC structure ---------------------------------------------------

    public function storeStructure(Request $request, string $memberUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $member = $this->member($request, $memberUuid);

        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'basic' => ['required', 'numeric', 'min:0'],
            'hra' => ['nullable', 'numeric', 'min:0'],
            'components' => ['nullable', 'array'],
            'components.*' => ['numeric', 'min:0'],
            'has_pf' => ['required', 'boolean'],
            'has_edli' => ['required', 'boolean'],
            'has_esi' => ['required', 'boolean'],
            'has_welfare' => ['required', 'boolean'],
            'pt_amount' => ['nullable', 'numeric', 'min:0'],
            'tds_monthly' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // Components with nothing in them are noise, not structure.
        $data['components'] = collect($data['components'] ?? [])
            ->map(fn ($v) => round((float) $v, 2))
            ->filter(fn ($v) => $v > 0)
            ->all();

        $structure = SalaryStructure::create($data + [
            'member_id' => $member->id,
            'created_by' => $request->user()->id,
        ]);

        // The CTC is what the seat costs: arithmetic, never typed.
        $structure->update(['ctc_monthly' => $structure->grossMonthly()]);

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'salary.structure_set', $member, [
            'employee' => $member->user?->name,
            'from' => $structure->effective_from->toDateString(),
            'gross' => $structure->grossMonthly(),
        ]);

        return response()->json([
            'message' => 'Salary structure effective ' . $structure->effective_from->format('d M Y') . ' saved.',
            'data' => $this->structureRow($structure),
        ], 201);
    }

    public function deleteStructure(Request $request, string $memberUuid, string $uuid): JsonResponse
    {
        $member = $this->member($request, $memberUuid);
        SalaryStructure::where('member_id', $member->id)->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Structure removed.']);
    }

    // ---- The incentive plan --------------------------------------------------

    public function storePlan(Request $request, string $memberUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $member = $this->member($request, $memberUuid);

        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'kind' => ['required', Rule::in(array_keys(IncentivePlan::KINDS))],
            'config' => ['nullable', 'array'],
            'config.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'config.base_amount' => ['nullable', 'numeric', 'min:0'],
            'config.team_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // The spread plan's own knob; blank falls back to HR Policy.
            // (TDS is never configured here — each invoice carries the TDS
            // its client actually deducted, and the totals are net of it.)
            'config.spread_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'config.slab_mode' => ['nullable', Rule::in(['whole', 'marginal'])],
            'config.slabs' => ['nullable', 'array', 'max:12'],
            'config.slabs.*.upto' => ['nullable', 'numeric', 'min:0'],
            'config.slabs.*.percent' => ['required_with:config.slabs', 'numeric', 'min:0', 'max:100'],
            'release_offset_months' => ['nullable', 'integer', 'min:0', 'max:6'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $plan = IncentivePlan::create([
            'member_id' => $member->id,
            'effective_from' => $data['effective_from'],
            'kind' => $data['kind'],
            'config' => $data['config'] ?? [],
            'release_offset_months' => $data['release_offset_months'] ?? 1,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'salary.incentive_plan_set', $member, [
            'employee' => $member->user?->name,
            'kind' => IncentivePlan::KINDS[$plan->kind],
            'from' => $plan->effective_from->toDateString(),
        ]);

        return response()->json([
            'message' => 'Incentive plan saved.',
            'data' => $this->planRow($plan),
        ], 201);
    }

    public function deletePlan(Request $request, string $memberUuid, string $uuid): JsonResponse
    {
        $member = $this->member($request, $memberUuid);
        IncentivePlan::where('member_id', $member->id)->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->json(['message' => 'Plan removed.']);
    }

    /**
     * What the plan would pay for a given month, before any slip exists —
     * so a slab can be argued about with real numbers on the table.
     */
    public function preview(Request $request, string $memberUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $member = $this->member($request, $memberUuid);
        $month = Carbon::parse($request->query('month', now()->subMonthNoOverflow()->format('Y-m')) . '-01');

        return response()->json(['data' => (new IncentiveCalculator($org))->compute($member, $month)]);
    }

    /**
     * Rule on this one person's payment gate: follow the policy, hold until
     * paid regardless, or release regardless — the deliberate exception for
     * an employee whose incentive must flow anyway. Manager authority, and
     * on the trail like every hand that moves money.
     */
    public function setPaymentGate(Request $request, string $memberUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $member = $this->member($request, $memberUuid);

        $data = $request->validate([
            'mode' => ['required', Rule::in(['policy', 'require', 'release'])],
        ]);

        $before = $member->incentive_needs_payment;
        $member->update(['incentive_needs_payment' => match ($data['mode']) {
            'require' => true,
            'release' => false,
            default => null,
        }]);

        $words = fn ($v) => $v === null ? 'follow the HR Policy' : ($v ? 'hold until paid in full' : 'pay without waiting for payment');
        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'incentive.payment_gate_set', $member, [
            'employee' => $member->user?->name,
            'from' => $words($before),
            'to' => $words($member->incentive_needs_payment),
        ]);

        return response()->json([
            'message' => ($member->user?->name ?? 'This employee') . '’s incentive will now '
                . $words($member->incentive_needs_payment) . '.',
        ]);
    }

    // ---- Loans and advances --------------------------------------------------

    public function storeLoan(Request $request, string $memberUuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $member = $this->member($request, $memberUuid);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['loan', 'advance'])],
            'amount' => ['required', 'numeric', 'min:1'],
            // 0 on an advance means "recover it whole next payroll".
            'monthly_installment' => ['nullable', 'numeric', 'min:0'],
            'taken_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $loan = Loan::create($data + [
            'organization_id' => $org->id,
            'member_id' => $member->id,
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'salary.loan_given', $member, [
            'employee' => $member->user?->name,
            'kind' => $loan->kind,
            'amount' => (float) $loan->amount,
        ]);

        return response()->json([
            'message' => ucfirst($loan->kind) . ' of ' . number_format((float) $loan->amount, 2) . ' recorded.',
            'data' => $this->loanRow($loan),
        ], 201);
    }

    /** A repayment made outside payroll — cash, transfer, adjustment. */
    public function repayLoan(Request $request, string $memberUuid, string $uuid): JsonResponse
    {
        $member = $this->member($request, $memberUuid);
        $loan = Loan::where('member_id', $member->id)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'repaid_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['amount'] > $loan->balance() + 0.01) {
            abort(422, 'That is more than the ' . number_format($loan->balance(), 2) . ' still owed.');
        }

        $loan->repayments()->create([
            'amount' => $data['amount'],
            'repaid_on' => $data['repaid_on'] ?? now()->toDateString(),
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        if ($loan->balance() <= 0) {
            $loan->update(['status' => 'closed']);
        }

        // Money coming back outside payroll is a hand on the books — trailed
        // like every other one.
        ActivityLog::record($request->attributes->get('crm_member'), $loan->organization_id, 'salary.loan_repaid', $member, array_filter([
            'employee' => $member->user?->name,
            'kind' => $loan->kind,
            'amount' => (float) $data['amount'],
            'repaid_on' => $data['repaid_on'] ?? now()->toDateString(),
            'note' => $data['note'] ?? null,
            'left' => $loan->fresh()->balance() ?: null,
        ]));

        return response()->json([
            'message' => 'Repayment recorded — ' . number_format($loan->fresh()->balance(), 2) . ' left.',
            'data' => $this->loanRow($loan->fresh()->load('repayments')),
        ], 201);
    }

    public function closeLoan(Request $request, string $memberUuid, string $uuid): JsonResponse
    {
        $member = $this->member($request, $memberUuid);
        $loan = Loan::where('member_id', $member->id)->where('uuid', $uuid)->firstOrFail();
        // Closing writes off whatever is left — deliberately, and on the trail.
        $left = $loan->balance();
        $loan->update(['status' => 'closed']);

        ActivityLog::record($request->attributes->get('crm_member'), $loan->organization_id, 'salary.loan_closed', $member, [
            'employee' => $member->user?->name,
            'written_off' => $left > 0 ? $left : null,
        ]);

        return response()->json(['message' => $left > 0
            ? 'Closed — ' . number_format($left, 2) . ' written off.'
            : 'Closed.']);
    }

    // ---- Helpers -------------------------------------------------------------

    private function member(Request $request, string $uuid): Member
    {
        $member = Member::with('user:id,name')
            ->where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        // Pay is an individual matter: one's own compensation, or the
        // Admin/Subadmin — never a colleague's, whatever other rights or
        // Team Workspace windows someone holds.
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless(
            $member->id === $me->id || in_array($me->crm_role, ['admin', 'subadmin'], true),
            403,
            'Another person’s compensation is the Admin’s or a Subadmin’s to read.',
        );

        return $member;
    }

    /** @return array<string, mixed> */
    private function structureRow(SalaryStructure $s): array
    {
        return [
            'uuid' => $s->uuid,
            'effective_from' => $s->effective_from->toDateString(),
            'ctc_monthly' => $s->ctc_monthly,
            'gross_monthly' => $s->grossMonthly(),
            'basic' => $s->basic,
            'hra' => $s->hra,
            'components' => $s->components ?? (object) [],
            'has_pf' => $s->has_pf,
            'has_edli' => $s->has_edli,
            'has_esi' => $s->has_esi,
            'has_welfare' => $s->has_welfare,
            'pt_amount' => $s->pt_amount,
            'tds_monthly' => $s->tds_monthly,
            'note' => $s->note,
            'created_by' => $s->creator?->name,
        ];
    }

    /** @return array<string, mixed> */
    private function planRow(IncentivePlan $p): array
    {
        return [
            'uuid' => $p->uuid,
            'effective_from' => $p->effective_from->toDateString(),
            'kind' => $p->kind,
            'kind_label' => IncentivePlan::KINDS[$p->kind] ?? $p->kind,
            'config' => $p->config ?? (object) [],
            'release_offset_months' => $p->release_offset_months,
            'note' => $p->note,
            'created_by' => $p->creator?->name,
            // The day the change was actually made — distinct from the day
            // it takes effect, and what the history reads as "changed on".
            'created_at' => $p->created_at?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function loanRow(Loan $l): array
    {
        return [
            'uuid' => $l->uuid,
            'kind' => $l->kind,
            'amount' => $l->amount,
            'monthly_installment' => $l->monthly_installment,
            'taken_on' => $l->taken_on->toDateString(),
            'balance' => $l->balance(),
            'status' => $l->status,
            'note' => $l->note,
            'repayments' => $l->repayments->sortByDesc('repaid_on')->values()
                ->map(fn ($r) => [
                    'amount' => $r->amount,
                    'repaid_on' => $r->repaid_on->toDateString(),
                    'via_payroll' => $r->salary_slip_id !== null,
                    'note' => $r->note,
                ]),
        ];
    }
}
