<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasUuids;

    protected $table = 'crm_organizations';

    protected $fillable = ['name', 'code', 'status', 'settings', 'created_by'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'organization_id');
    }

    /**
     * Spend the system books on the company's behalf. These are always
     * offered, even to a company that has rewritten its own category list —
     * otherwise the rows exist and nothing can filter to them.
     */
    public const SYSTEM_EXPENSE_CATEGORIES = ['Client Commission', 'Payment Gateway Charges'];

    /** Option lists the org can customise; defaults mirror the old CRM. */
    public function optionList(string $key): array
    {
        $defaults = [
            'departments' => [
                'Sales', 'Marketing', 'Operations', 'Administration', 'Human Resource',
                'Finance', 'Information Technology', 'Sales & Operations', 'Sales & Marketing',
                'Database Management', 'Admin. & H.R.', 'H.R. & Finance', 'Admin. & Operations',
                'Operations & H.R.',
            ],
            'designations' => [
                'Junior Executive', 'Executive', 'Senior Executive', 'Deputy Manager',
                'Junior Corporate Manager', 'Corporate Manager', 'Senior Corporate Manager',
                'Assistant Vice President', 'Vice President', 'Senior Vice President',
                'Deputy President', 'President', 'Senior President', 'Deputy Director', 'Director',
            ],
            'payment_modes' => [
                'Cash', 'Cheque', 'DD', 'NEFT', 'RTGS', 'IMPS', 'UPI', 'SWIFT',
                'Payment Gateway', 'Credit Note',
            ],
            'lead_sources' => [
                'Website', 'Call', 'Email', 'Message', 'WhatsApp', 'Chat',
                'Self Lead', 'Reference Lead', 'Telecalling', 'LinkedIn',
                'Facebook', 'Google Ads', 'Exhibition', 'Other',
            ],
            'leave_categories' => [
                'Casual Leave', 'Sick Leave', 'Paid Leave', 'Travel Leave',
                'Marriage Leave', 'Family Function Leave', 'Family Issue Leave',
                'Maternity Leave', 'Paternity Leave', 'Unpaid Leave',
            ],
            'approval_types' => [
                'First Approval', 'Repeated Approval', 'Executive Error',
                'Client Error', 'Both Error', 'Office Recharge', 'Discount',
                'Refund Request', 'Other',
            ],
            'expense_categories' => [
                'Rent', 'Utilities', 'Office Supplies', 'Pantry', 'Travel',
                'Marketing', 'Software & Subscriptions', 'Hardware', 'Repairs',
                'Professional Fees', 'Bank Charges', 'Miscellaneous',
            ] + self::SYSTEM_EXPENSE_CATEGORIES,
            'complaint_sources' => [
                'Client', 'Sales Executive', 'Customer Care Executive',
                'Operations', 'Website', 'Email', 'Social Media', 'Other',
            ],
            'complaint_subjects' => [
                'HS Code / Product Mismatch Issue',
                'Client Self Shipments Not Showing In Data',
                'Client Claim Other Company Shipments',
                'Data Is Incomplete', 'Data Not Updated', 'Wrong Contact Details',
                'Login / Access Issue', 'Invoice Or Billing Issue',
                'Service Delay', 'Staff Behaviour', 'Refund Request', 'Other',
            ],
            'complaint_types' => [
                'Data', 'Service', 'Billing', 'Technical', 'Behaviour', 'Other',
            ],
            'complaint_modes' => [
                'Call', 'Email', 'WhatsApp', 'Message', 'Portal', 'In Person', 'Other',
            ],
            'lead_subjects' => [
                'Unspecified', 'High Rate', 'Contact Details Needed', 'Data Quality',
                'Client Not Available', 'Pending for Approval', 'Services Taken From Others',
                'Client Only Needs Sample', 'Client Not Required Now',
                'Sample Sent - Waiting for Response', 'Online Demo Given - Waiting for Response',
                'Online Demo to be Given', 'Proposal Sent - Waiting for Response',
                'Online - Closed', 'Offline - Closed', 'Only Enquiry',
            ],
        ];

        $list = $this->settings[$key] ?? $defaults[$key] ?? [];

        // A company may rewrite its spend categories, but never away the two
        // the system itself files under.
        if ($key === 'expense_categories') {
            $list = collect($list)->merge(self::SYSTEM_EXPENSE_CATEGORIES)->unique()->values()->all();
        }

        return $list;
    }

    /**
     * How a claimed payment is settled by default: `manual` means an Admin
     * checks it first, which is the safe way round and so the default.
     */
    public function settlementMode(): string
    {
        return data_get($this->settings, 'payments.settlement_mode') === 'auto' ? 'auto' : 'manual';
    }

    /**
     * The HR Policy: one set of house rules every employee is measured
     * against, Subadmins included. An employee's own record may lengthen
     * their probation, but nothing else here is per-person — that is what
     * makes it a policy rather than a preference.
     */
    public const HR_DEFAULTS = [
        'work_start' => '10:00',
        // Per-day office timings (Carbon dayOfWeek as the key). A day with
        // no row falls back to work_start/work_end; the weekly-off list
        // still decides which days are off at all.
        'day_schedule' => [
            '1' => ['start' => '10:00', 'end' => '18:30'],
            '2' => ['start' => '10:00', 'end' => '18:30'],
            '3' => ['start' => '10:00', 'end' => '18:30'],
            '4' => ['start' => '10:00', 'end' => '18:30'],
            '5' => ['start' => '10:00', 'end' => '18:30'],
            '6' => ['start' => '10:00', 'end' => '18:00'],
        ],
        // The late rule: every N lates in a month cost half a day's pay.
        // 0 turns the rule off.
        'lates_per_half_day' => 4,
        'work_end' => '19:00',
        // Arrive after start + grace and the day is Late.
        'grace_minutes' => 15,
        // Arrive this far past the start and lateness stops being lateness.
        'half_day_after_minutes' => 180,
        // Leave before working this long and the day is a Half day.
        'half_day_hours' => 4.5,
        'full_day_hours' => 8.0,
        // 0 = Sunday. The days nobody is expected in.
        'week_off_days' => [0],
        // No leave at all until this has passed since joining.
        'probation_days' => 180,
        // Earned on the 1st of every month once probation is behind you.
        'monthly_leave_credit' => 1.0,
        // Anything left on 31 March is paid at one day of basic salary.
        'encash_unused_leave' => true,
        'financial_year_start_month' => 4,
        // Statutory payroll rates, as the company's own sheet applies them.
        // PF and EDLI are taken on basic capped at the PF wage ceiling; ESI
        // on the whole gross, and only for employees flagged into it; the
        // welfare fund is a small fixed-cap cut matched twice over by the
        // employer. Rates move by notification, so they are policy, not code.
        // Named separately per side: today both are 12%, but a notification
        // can move one without the other, and the policy must be ready.
        'pf_employer_rate' => 12.0,
        'pf_employee_rate' => 12.0,
        'pf_wage_cap' => 15000,
        'esi_employer_rate' => 3.25,
        'esi_employee_rate' => 0.75,
        'edli_rate' => 1.0,
        'welfare_employee_cap' => 34,
        'welfare_employee_rate' => 0.2,
        'welfare_employer_multiple' => 2,
        // The STANDARD structure: which facilities a new employee is put in
        // by default. Each is still switchable per person — some staff want
        // only the discussed in-hand salary and take none of them.
        // Incentive standard: how many months a spread plan divides a
        // sale's incentive over. (TDS is never a standard — each invoice
        // carries what its client actually deducted.)
        'incentive_spread_months' => 12,
        // No incentive until the client has paid in full; on full payment
        // the waiting installments release themselves automatically.
        'incentive_needs_full_payment' => true,
        'pf_default' => true,
        'edli_default' => true,
        'esi_default' => false,
        'welfare_default' => true,
    ];

    /** @return array<string, mixed> */
    /**
     * The office hours for one weekday: the day's own row from the policy's
     * day_schedule, else the single work_start/work_end.
     *
     * @return array{start: string, end: string}
     */
    public function scheduleFor(int $dayOfWeek): array
    {
        $policy = $this->hrPolicy();
        $day = ($policy['day_schedule'] ?? [])[(string) $dayOfWeek] ?? null;

        return [
            'start' => $day['start'] ?? $policy['work_start'],
            'end' => $day['end'] ?? $policy['work_end'],
        ];
    }

    public function hrPolicy(): array
    {
        $saved = $this->settings['hr'] ?? [];
        $policy = array_replace(self::HR_DEFAULTS, is_array($saved) ? $saved : []);

        // A policy saved before the PF rate split carries one 'pf_rate' for
        // both sides; it keeps meaning exactly that.
        if (isset($policy['pf_rate'])) {
            $policy['pf_employer_rate'] = $policy['pf_employer_rate'] ?? $policy['pf_rate'];
            $policy['pf_employee_rate'] = $policy['pf_employee_rate'] ?? $policy['pf_rate'];
            if (! isset($saved['pf_employer_rate'])) {
                $policy['pf_employer_rate'] = $policy['pf_rate'];
            }
            if (! isset($saved['pf_employee_rate'])) {
                $policy['pf_employee_rate'] = $policy['pf_rate'];
            }
            unset($policy['pf_rate']);
        }

        return $policy;
    }

    /**
     * How long the office gives itself to close a complaint. The Admin sets
     * it; 48 hours is the default, and a complaint may still name its own.
     */
    public function complaintHours(): int
    {
        return max(1, min(720, (int) (data_get($this->settings, 'complaints.resolve_hours') ?: 48)));
    }

    /**
     * How often a due lead nags the screen again if nobody attends it.
     * The Admin turns this knob; 15 minutes is the default.
     */
    public function leadAlertMinutes(): int
    {
        return max(5, min(120, (int) (data_get($this->settings, 'leads.alert_minutes') ?: 15)));
    }

    /**
     * How often an UNATTENDED new lead nags its assignee again. The first
     * popup fires the moment it arrives; this is the repeat interval.
     */
    public function newLeadAlertMinutes(): int
    {
        return max(5, min(120, (int) (data_get($this->settings, 'leads.new_alert_minutes') ?: 15)));
    }

    /**
     * The automatic chasing schedule: whether it runs, on which days
     * relative to the due date (negative is before), and when to give up.
     */
    public function reminderSchedule(): array
    {
        $schedule = (array) data_get($this->settings, 'reminders', []);

        $offsets = collect($schedule['offsets'] ?? [0, 7, 15, 30])
            ->map(fn ($d) => (int) $d)
            ->filter(fn ($d) => $d >= -60 && $d <= 365)
            ->unique()->sort()->values()->all();

        return [
            'enabled' => (bool) ($schedule['enabled'] ?? false),
            'offsets' => $offsets,
            'stop_after' => max(1, min(20, (int) ($schedule['stop_after'] ?? 4))),
        ];
    }
}
