<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

/**
 * A CRM employee: a Netvork user wearing a company hat. The Netvork account
 * handles login; everything employment-related lives here, so removing the
 * addon (or the employee) never touches the person's personal Netvork.
 */
class Member extends Model
{
    use HasUuids;

    protected $table = 'crm_members';

    public const ROLES = ['admin', 'subadmin', 'employee'];

    /** Module slugs a member's rights JSON may grant. */
    public const MODULES = [
        'dashboard', 'employees', 'clients', 'leads', 'targets', 'contests',
        'dwr', 'punch', 'tasks', 'leaves', 'approvals', 'proforma', 'invoices',
        'payments', 'expenses', 'salary', 'assets', 'newsletters', 'cms', 'complaints', 'masters',
        'reports', 'settings',
    ];

    public const ABILITIES = ['view', 'create', 'edit', 'delete'];

    /**
     * The delicate acts. These move ownership or money, so an Admin and a
     * Subadmin hold them by virtue of the job — and any one of them can be
     * granted to a named employee when a company works that way.
     *
     * The label is what the Admin reads on the rights screen; the group is
     * only how the screen stacks them.
     */
    public const CAPABILITIES = [
        'clients.edit_details' => ['group' => 'Clients', 'label' => 'Edit a client’s billing details — the name, address, GST and contacts that print on a proforma or invoice'],
        'clients.delete' => ['group' => 'Clients', 'label' => 'Delete a client'],
        'clients.transfer' => ['group' => 'Clients', 'label' => 'Transfer a client to someone else'],
        'clients.share' => ['group' => 'Clients', 'label' => 'Share a client, and decide access requests'],
        'leads.transfer' => ['group' => 'Leads', 'label' => 'Transfer a lead'],
        'leads.bulk_transfer' => ['group' => 'Leads', 'label' => 'Transfer leads in bulk'],
        'leads.share' => ['group' => 'Leads', 'label' => 'Share a lead, and decide Lead Duplication'],
        'leads.edit_contacts' => ['group' => 'Leads', 'label' => 'Change a lead’s mobile, phone or e-mail'],
        'leads.reopen' => ['group' => 'Leads', 'label' => 'Reopen a closed lead'],
        'payments.settle' => ['group' => 'Money', 'label' => 'Settle and re-match payments'],
        'commissions.remove' => ['group' => 'Money', 'label' => 'Remove a commission entry'],
        // Held by name even for a Subadmin: the accounting export is the
        // Admin's, plus exactly the people the Admin has named.
        'exports.excel' => ['group' => 'Money', 'label' => 'Download invoices & payments as Excel (accounting export)'],
        // Also held by name: the Reports screen is the Admin's, opened to a
        // Subadmin only when the Admin ticks them in.
        'reports.view' => ['group' => 'Money', 'label' => 'See the Reports screen (company-wide figures)'],
        // Held by name even for a Subadmin, and the reason is circular until
        // you see it: module rights and this very list are what decide what
        // somebody may do, so whoever may edit them may edit themselves into
        // anything. The Admin holds it by the job; a Subadmin holds it only
        // where the Admin ticked their name, and then only over employees.
        'employees.rights' => ['group' => 'Employees', 'label' => 'Set an employee’s module rights and special permissions — never another Subadmin’s, and never their own'],
        'hr.policy_edit' => ['group' => 'HR', 'label' => 'Edit the HR Policy (timings, late rule, statutory rates)'],
        'letters.download' => ['group' => 'HR', 'label' => 'Download their own HR letters (offer, appointment, promotion…)'],
    ];

    /**
     * The next automatic employee code. Numbering starts at EMP-101 and
     * continues past the highest EMP-n already issued, so hand-typed codes
     * (any format) never collide and never reset the counter.
     */
    public static function nextEmployeeCode(int $organizationId): string
    {
        $max = (int) static::where('organization_id', $organizationId)
            ->where('employee_code', 'like', 'EMP-%')
            ->pluck('employee_code')
            ->map(fn ($code) => preg_match('/^EMP-(\d+)$/i', (string) $code, $m) ? (int) $m[1] : 0)
            ->max();

        return 'EMP-' . max(101, $max + 1);
    }

    protected $fillable = [
        'organization_id', 'user_id', 'crm_role', 'is_oversight', 'status', 'employee_code', 'title',
        'capabilities',
        'impersonation_level',
        'department', 'designation', 'batch', 'father_name', 'father_phone',
        'mother_name', 'mother_phone', 'dob', 'gender', 'present_address',
        'present_phone', 'office_phone', 'permanent_address', 'permanent_phone',
        'personal_email', 'joined_at', 'probation_days', 'late_waived', 'punch_waived', 'resigned_at', 'is_salesperson', 'incentive_needs_payment', 'pf_no',
        'esi_no', 'pan_no', 'aadhaar_no', 'bank_name', 'bank_account_no',
        'bank_ifsc', 'bank_account_name', 'reporting_to', 'rights', 'note',
    ];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'joined_at' => 'date',
            'resigned_at' => 'date',
            'is_salesperson' => 'boolean',
            'late_waived' => 'boolean',
            'punch_waived' => 'boolean',
            'incentive_needs_payment' => 'boolean',
            'is_oversight' => 'boolean',
            'rights' => 'array',
            'capabilities' => 'array',
        ];
    }

    /** Company-facing lists: oversight memberships stay invisible. */
    public function scopeVisible($query)
    {
        return $query->where('is_oversight', false);
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reporting_to');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'reporting_to');
    }

    /**
     * The Team Workspace: the people the Admin has put in this person's
     * hands, ticked by name. A grant here makes a team leader. Withdrawn
     * grants (revoked_at set) stay as history but no longer count.
     */
    public function team(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'crm_team_access', 'leader_id', 'member_id')
            ->wherePivotNull('revoked_at')
            ->withTimestamps();
    }

    /** The other direction: who has been given this person to handle. */
    public function leaders(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'crm_team_access', 'member_id', 'leader_id')
            ->wherePivotNull('revoked_at')
            ->withTimestamps();
    }

    /**
     * A team leader by either door: people reporting to them on the org
     * chart, or people the Admin ticked into their Team Workspace. This is
     * the flag the sidebar and the mine/team scope toggles read.
     */
    public function leadsATeam(): bool
    {
        return $this->reports()->exists() || $this->team()->exists();
    }

    public function salaryRecords(): HasMany
    {
        return $this->hasMany(SalaryRecord::class, 'member_id')->orderByDesc('effective_from');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(Target::class, 'member_id');
    }

    public function kpis(): HasMany
    {
        return $this->hasMany(MemberKpi::class, 'member_id')->orderBy('sort');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /** Rights check: CRM admins can do everything, others use their grant. */
    /**
     * May this member perform one of the delicate acts? The job carries them
     * all; anyone else holds only what the Admin granted by name.
     */
    /**
     * The day probation ends. The company's HR Policy sets the length; a
     * member's own record only overrides it when someone deliberately did
     * so — which is why the column is null by default.
     */
    public function probationEndsOn(?int $policyDays = null): ?\Carbon\Carbon
    {
        if (! $this->joined_at) {
            return null;
        }
        $days = $this->probation_days
            ?? $policyDays
            ?? Organization::HR_DEFAULTS['probation_days'];

        return $this->joined_at->copy()->addDays((int) $days);
    }

    /** Still inside probation, and so still earning no leave. */
    public function onProbation(?int $policyDays = null, ?\Carbon\Carbon $on = null): bool
    {
        $ends = $this->probationEndsOn($policyDays);

        return $ends !== null && ($on ?? now())->lt($ends);
    }

    public function allows(string $capability): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        if (in_array($this->crm_role, ['admin', 'subadmin'], true)) {
            return true;
        }

        return in_array($capability, (array) ($this->capabilities ?? []), true);
    }

    /**
     * Whose seats this member may sit in — empty when nobody's.
     *
     * The Admin has this by the job and may open an employee or a subadmin.
     * A Subadmin has it only where the Admin ticked their name, and then only
     * for employees: opening a peer is sideways, and the point of the grant
     * is a manager looking down at their own team, not across at each other.
     *
     * Asked in one place because three screens need the same answer — the
     * list deciding which rows get a button, the endpoint deciding whether to
     * issue a token, and the shell deciding whether the feature exists at
     * all — and three copies of a rule about impersonation is two too many.
     *
     * @return list<string>
     */
    public function borrowableRoles(): array
    {
        if ($this->impersonationLevel() === null) {
            return [];
        }

        return $this->crm_role === 'admin' ? ['employee', 'subadmin'] : ['employee'];
    }

    /**
     * May this member set rights and special permissions on $target?
     *
     * The escalation this closes is the plainest one there is. Module rights
     * and the capability list are what decide what a person may do, and the
     * employee screen is open to Subadmins — so a Subadmin could open their
     * own row, tick everything, and save. They could do the same to a peer,
     * and, because crm_role rode in the same payload, promote themselves to
     * Admin outright. None of that needed a bug; it was simply never
     * restricted.
     *
     * So it belongs to the Admin, and reaches a Subadmin only by name. Even
     * then it points downwards only: at employees, never at a peer, never at
     * themselves, never at the Admin. A Subadmin who could edit another
     * Subadmin's rights could edit their own through them.
     *
     * $target is null when somebody is being registered rather than edited —
     * a new hire the caller is not yet able to name, and one whose role a
     * non-Admin does not get to choose.
     */
    public function maySetRightsOn(?self $target): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        if ($this->crm_role === 'admin') {
            return true;
        }
        if ($this->crm_role !== 'subadmin'
            || ! in_array('employees.rights', (array) ($this->capabilities ?? []), true)) {
            return false;
        }

        return $target === null
            || ($target->crm_role === 'employee' && $target->id !== $this->id);
    }

    /** Whether the screen offers the rights editor at all. */
    public function maySetRightsAtAll(): bool
    {
        return $this->maySetRightsOn(null);
    }

    /** The four answers, weakest first, so two of them can be compared. */
    public const IMPERSONATION_ORDER = ['crm_read', 'crm', 'account'];

    /**
     * How deeply this member may sit in somebody's seat — null for not at all.
     *
     * Two grants, and the narrower of them wins. The platform sets what the
     * company may do at all; within that, the Company Admin says which of
     * their Subadmins may do it and how far. The Admin themselves has whatever
     * the company has, by the job.
     *
     * Capped here rather than only at the moment it is written, because the
     * company's ceiling can be lowered afterwards — and a Subadmin granted
     * 'account' last month must not still hold it when the platform has since
     * cut the company back to 'crm'. Reading it through this method is the
     * only way that is true everywhere.
     */
    public function impersonationLevel(): ?string
    {
        if ($this->status !== 'active') {
            return null;
        }

        $ceiling = $this->organization?->impersonation_level;
        if (! in_array($ceiling, self::IMPERSONATION_ORDER, true)) {
            return null;
        }

        if ($this->crm_role === 'admin') {
            return $ceiling;
        }
        if ($this->crm_role !== 'subadmin') {
            return null;
        }

        $granted = $this->impersonation_level;
        if (! in_array($granted, self::IMPERSONATION_ORDER, true)) {
            return null;
        }

        return array_search($granted, self::IMPERSONATION_ORDER, true)
            <= array_search($ceiling, self::IMPERSONATION_ORDER, true)
                ? $granted
                : $ceiling;
    }

    public function can(string $module, string $ability = 'view'): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        if ($this->crm_role === 'admin') {
            return true;
        }

        $abilities = $this->rights[$module] ?? [];

        // Any write grant implies view, so screens never half-load.
        if ($ability === 'view' && $abilities !== []) {
            return true;
        }

        return in_array($ability, $abilities, true);
    }

    /**
     * The member ids of this person's team: themselves plus everyone put in
     * their hands — through the reporting chain, and through the Team
     * Workspace grants the Admin ticks person by person. Both kinds of edge
     * are walked to any depth: handling someone means handling the people
     * under them too. This is what makes a Team Head — their window widens
     * to these people while admins/subadmins keep the whole company.
     *
     * @return int[]
     */
    public function teamMemberIds(): array
    {
        $parents = static::where('organization_id', $this->organization_id)
            ->pluck('reporting_to', 'id');

        $children = [];
        foreach ($parents as $id => $parent) {
            if ($parent !== null) {
                $children[$parent][] = $id;
            }
        }

        // The Team Workspace edges — explicit grants beside the org chart.
        // Withdrawn grants no longer open any window.
        $granted = DB::table('crm_team_access')
            ->whereIn('leader_id', $parents->keys())
            ->whereNull('revoked_at')
            ->get(['leader_id', 'member_id']);
        foreach ($granted as $edge) {
            $children[$edge->leader_id][] = (int) $edge->member_id;
        }

        $ids = [];
        $queue = [$this->id];
        while ($queue !== []) {
            $id = array_pop($queue);
            if (in_array($id, $ids, true)) {
                continue; // cycle guard: A reports to B reports to A
            }
            $ids[] = $id;
            foreach ($children[$id] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $ids;
    }

    /** The matching Netvork user ids, for created_by-style columns. */
    /**
     * Whose sales a screen is showing: null means unrestricted (a manager's
     * combined view), otherwise the member ids whose figures belong on it.
     * 'mine' narrows anyone to themselves; the combined view is the whole
     * company for a manager and the subtree for a Team Head.
     */
    public function salesWindow(?string $scope): ?array
    {
        if ($scope === 'mine') {
            return [$this->id];
        }

        return in_array($this->crm_role, ['admin', 'subadmin'], true) ? null : $this->teamMemberIds();
    }

    public function teamUserIds(): array
    {
        return static::whereIn('id', $this->teamMemberIds())->pluck('user_id')->all();
    }

    /**
     * The users who can decide requests for a module (edit right; admins
     * always qualify). The requester is excluded — nobody is notified about
     * work they created for themselves.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    public static function deciders(int $organizationId, string $module, ?int $exceptMemberId = null)
    {
        return static::with('user')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            // Oversight members are not chased with the company's paperwork.
            ->where('is_oversight', false)
            ->when($exceptMemberId, fn ($q) => $q->whereKeyNot($exceptMemberId))
            ->get()
            ->filter(fn (self $m) => $m->can($module, 'edit'))
            ->pluck('user')
            ->filter()
            ->values();
    }

    public function currentSalary(): ?SalaryRecord
    {
        return $this->salaryRecords()->where('effective_from', '<=', now()->toDateString())->first()
            ?? $this->salaryRecords()->first();
    }
}
