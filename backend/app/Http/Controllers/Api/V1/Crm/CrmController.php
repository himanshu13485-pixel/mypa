<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\MessageDeletion;
use App\Models\Crm\CmsPost;
use App\Models\Crm\Complaint;
use App\Models\Crm\Contest;
use App\Models\Crm\ContestAnswer;
use App\Models\Crm\ContestQuestion;
use App\Models\Crm\Lead;
use App\Models\Crm\PaymentInboxEntry;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Approval;
use App\Models\Crm\ClientAccessRequest;
use App\Models\Crm\InvoiceUpdateRequest;
use App\Models\Crm\Leave;
use App\Models\Crm\Task;
use App\Models\Crm\BankAccount;
use App\Models\Crm\Client;
use App\Models\Crm\Invoice;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bootstrap + dashboard for the CRM addon.
 *
 * /crm/me is deliberately outside the crm.member middleware: the sidebar
 * asks it for everyone, and "not a member" is an answer, not an error.
 */
class CrmController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $query = Member::with('organization')
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            // Without a hat, the person's REAL membership wins — exiting an
            // entered workspace always lands back home, never in another
            // oversight entry.
            ->orderBy('is_oversight');

        // Same org-hat header as the middleware: multi-org users (the Super
        // Admin who entered a company) pick which workspace this session is.
        // Slug or uuid — see EnsureCrmMember for why both.
        if ($org = $request->header('X-Crm-Org')) {
            $query->whereHas('organization', fn ($q) => $q->keyed($org));
        }

        $member = $query->first();

        $enabled = $member !== null && $member->organization->status === 'active';

        return response()->json(['data' => [
            'enabled' => $enabled,
            'is_super_admin' => $request->user()->isSuperAdmin(),
            'has_team' => $enabled && $member->leadsATeam(),
            'member' => $enabled ? $this->serializeMember($member) : null,
            'organization' => $enabled ? [
                'uuid' => $member->organization->uuid,
                // What the address bar shows, and what the shell redirects
                // to when somebody opens /crm with no company on it.
                'slug' => $member->organization->slug,
                'name' => $member->organization->name,
                'code' => $member->organization->code,
            ] : null,
        ]]);
    }

    /** Option lists every CRM form needs; one call, cached client-side. */
    public function masters(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        return response()->json(['data' => [
            'departments' => $org->optionList('departments'),
            'designations' => $org->optionList('designations'),
            'payment_modes' => $org->optionList('payment_modes'),
            'client_categories' => Client::CATEGORIES,
            // Dedicated Company Workspace: this org's approved extra fields.
            'client_custom_fields' => \App\Models\Crm\CustomField::approvedFor($org->id, 'client')->values(),
            // This company's own Work Order method (the invoice/proforma lines):
            // our columns as they word them, then the ones they added.
            'work_order_custom_fields' => \App\Models\Crm\CustomField::approvedFor($org->id, 'work_order')->values(),
            'work_order_method' => \App\Models\Crm\CustomField::workOrderMethod($org->id),
            // The document's own fields and money lines, likewise.
            'invoice_custom_fields' => \App\Models\Crm\CustomField::approvedFor($org->id, 'invoice')->values(),
            'invoice_method' => \App\Models\Crm\CustomField::invoiceMethod($org->id),
            'tax_setup' => \App\Models\Crm\CustomField::taxSetup($org->id),
            'expense_categories' => $org->optionList('expense_categories'),
            'leave_categories' => $org->optionList('leave_categories'),
            'approval_types' => $org->optionList('approval_types'),
            'lead_sources' => $org->optionList('lead_sources'),
            'lead_subjects' => $org->optionList('lead_subjects'),
            'lead_statuses' => \App\Models\Crm\Lead::STATUSES,
            'modules' => Member::moduleSlugs(),
            // Sent with them, so the rights screen cannot label a row from a
            // list of its own that has quietly stopped matching this one.
            'module_labels' => Member::MODULES,
            'abilities' => Member::ABILITIES,
            // What the rights screen offers beyond the module matrix.
            'capabilities' => collect(Member::CAPABILITIES)
                ->map(fn ($meta, $key) => ['key' => $key] + $meta)
                ->values(),
            'issuing_companies' => IssuingCompany::where('organization_id', $org->id)
                ->orderBy('name')
                ->get(['id', 'name', 'gstin', 'pan', 'phone', 'email', 'address', 'state_code', 'invoice_prefix', 'proforma_prefix', 'is_active', 'logo_path', 'currency', 'pays_salary']),
            'bank_accounts' => BankAccount::with('issuingCompany:id,name')
                ->where('organization_id', $org->id)
                ->orderBy('label')
                ->get(['id', 'issuing_company_id', 'label', 'bank_name', 'account_no', 'ifsc', 'is_active'])
                ->map(function ($b) use ($request) {
                    $row = $b->toArray() + ['issuing_company_name' => $b->issuingCompany?->name];
                    // Full account numbers are the managers' to see; every
                    // other member gets the label and a masked tail.
                    $me = $request->attributes->get('crm_member');
                    if (! in_array($me?->crm_role, ['admin', 'subadmin'], true)) {
                        $row['account_no'] = $b->account_no ? '…' . substr($b->account_no, -4) : null;
                        $row['ifsc'] = null;
                    }

                    return $row;
                }),
            'members' => Member::visible()->with('user:id,name')
                ->where('organization_id', $org->id)
                ->where('status', 'active')
                ->orderBy('id')
                ->get()
                ->map(fn ($m) => [
                    'uuid' => $m->uuid,
                    'name' => $m->user?->name,
                    'employee_code' => $m->employee_code,
                    'is_salesperson' => $m->is_salesperson,
                    // So pickers can leave the Admin side out (Team Workspace
                    // ticks list employees only — Admin controls everything).
                    'crm_role' => $m->crm_role,
                ]),
        ]]);
    }

    /**
     * The CRM chat dock's directory: every colleague with the handle the
     * Netvork messenger starts a conversation by, plus live presence —
     * online (< 2 min), idle (< 10), away (< 30), offline beyond that.
     * Reading the directory is itself a heartbeat.
     */
    public function chatDirectory(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        \Illuminate\Support\Facades\Cache::put(
            'crm-presence:' . $request->user()->id,
            ['at' => now()->timestamp, 'state' => (string) $request->query('state', 'active')],
            1800,
        );

        $members = Member::visible()->with(['user:id,uuid,name', 'user.appId', 'user.profile:user_id,photo_path,avatar,gender'])
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->where('id', '!=', $me->id)
            ->orderBy('id')
            ->get();

        $rows = $members->map(function (Member $m) {
            $ping = \Illuminate\Support\Facades\Cache::get('crm-presence:' . $m->user_id);
            $age = $ping ? now()->timestamp - (int) ($ping['at'] ?? 0) : null;
            $state = $ping['state'] ?? 'active';
            $status = $age === null || $age > 1800 ? 'offline'
                : ($age <= 120 && $state === 'active' ? 'online'
                    : ($age <= 600 ? 'idle' : 'away'));

            return [
                'uuid' => $m->uuid,
                'name' => $m->user?->name,
                'app_id' => $m->user?->appId?->app_id,
                'photo_path' => $m->user?->profile?->photo_path,
                'avatar' => $m->user?->profile?->avatar,
                'gender' => $m->gender ?? $m->user?->profile?->gender,
                'crm_role' => $m->crm_role,
                'status' => $status,
            ];
        });

        return response()->json(['data' => $rows]);
    }

    /**
     * The sidebar's pending-work counter. Each section is null when the
     * member cannot decide it, so the badge only nags actual approvers.
     */
    public function badges(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $sections = [
            'leaves' => $me->can('leaves', 'edit')
                ? \App\Models\Crm\Leave::where('organization_id', $org->id)->where('status', 'pending')->where('member_id', '!=', $me->id)->count()
                : null,
            'tasks' => $me->can('tasks', 'edit')
                ? \App\Models\Crm\Task::where('organization_id', $org->id)->where('status', 'submitted')->count()
                : null,
            'approvals' => $me->can('approvals', 'edit')
                ? \App\Models\Crm\Approval::where('organization_id', $org->id)->where('status', 'pending')->where('requested_by', '!=', $me->id)->count()
                : null,
            'invoice_updates' => $me->can('invoices', 'edit')
                ? \App\Models\Crm\InvoiceUpdateRequest::where('organization_id', $org->id)->where('status', 'pending')->count()
                : null,
            'client_access' => $me->can('clients', 'edit')
                ? \App\Models\Crm\ClientAccessRequest::where('organization_id', $org->id)->where('status', 'pending')->where('member_id', '!=', $me->id)->count()
                : null,
        ];

        // What colleagues have done since this member last looked — the
        // numbers beside the sidebar entries.
        $unread = $this->sectionCounts($org, $me);

        return response()->json(['data' => $sections + [
            'total' => collect($sections)->filter(fn ($v) => $v !== null)->sum(),
            'sections' => $unread,
            // Work sitting on THIS member's desk right now — the numbers in
            // brackets beside the menu, and what the alert sound watches.
            'attend' => $this->attendCounts($request, $org, $me),
        ]]);
    }

    /**
     * The unattended counters, one per menu entry: connection requests and
     * unread chats from the Connect inbox, leads and tasks on my desk, fresh
     * notices, live contests I have not finished, payments waiting on a
     * settle, and - for the authority side - leaves, the approvals register
     * and open complaints. A key a member has no business with stays absent.
     */
    private function attendCounts(Request $request, $org, Member $me): array
    {
        $user = $request->user();
        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);
        $badges = [];


        // Connect: the same inbox as the personal side — requests waiting
        // for my answer, and messages after my last read mark.
        $badges['connections'] = Connection::where('addressee_id', $user->id)
            ->where('status', 'pending')->count();
        $hidden = MessageDeletion::where('user_id', $user->id)->pluck('message_id');
        $messages = 0;
        Conversation::visibleTo($user)->with('members')->get()->each(function ($conversation) use ($user, $hidden, &$messages) {
            $lastRead = $conversation->members->firstWhere('id', $user->id)?->pivot->last_read_at;
            $messages += $conversation->messages()
                ->where('user_id', '!=', $user->id)
                ->whereNotIn('id', $hidden)
                ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
                ->count();
        });
        $badges['messages'] = $messages;

        // Leads on my desk: unattended arrivals plus follow-ups now due.
        if ($me->can('leads', 'view')) {
            $badges['leads'] = Lead::where('organization_id', $org->id)
                ->where('assigned_member_id', $me->id)
                ->where(fn ($q) => $q
                    ->where('lead_status', 'unattended')
                    ->orWhere(fn ($w) => $w->where('lead_status', 'follow_up')
                        ->where('follow_up_at', '<=', now())))
                ->count();
        }

        // My open work items.
        $badges['tasks'] = Task::where('organization_id', $org->id)
            ->where('assigned_member_id', $me->id)
            ->whereIn('status', ['open', 'in_progress', 'reopened'])
            ->count();

        // Notices currently on the board that went up in the last 7 days —
        // the closest honest "new" without per-member read receipts.
        $today = now()->toDateString();
        $badges['notice'] = CmsPost::where('organization_id', $org->id)
            ->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('publish_on')->orWhereDate('publish_on', '<=', $today))
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today))
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        // Live contests aimed at me with questions I have not answered.
        $contests = Contest::withCount('questions')
            ->where('organization_id', $org->id)
            ->where('status', 'published')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->when(! $manages, fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($x) => $x->whereNull('audience_department')->whereNull('audience_member_id'))
                ->orWhere('audience_member_id', $me->id)
                ->when($me->department, fn ($d) => $d->orWhere('audience_department', $me->department))))
            ->get();
        $answeredByContest = ContestAnswer::where('member_id', $me->id)
            ->whereIn('question_id', ContestQuestion::whereIn('contest_id', $contests->pluck('id'))->select('id'))
            ->with('question:id,contest_id')
            ->get()
            ->groupBy(fn ($a) => $a->question->contest_id);
        $badges['contests'] = $contests
            ->filter(fn ($c) => ($answeredByContest[$c->id] ?? collect())->count() < $c->questions_count)
            ->count();

        // Payments matched to a document, waiting on a settle.
        if ($me->can('payments', 'view')) {
            $badges['payments'] = PaymentInboxEntry::where('organization_id', $org->id)
                ->where('status', 'pending')->count();
        }

        // Authority side: what waits on an approver's pen.
        if ($manages || $me->can('leaves', 'edit')) {
            $badges['leaves'] = Leave::where('organization_id', $org->id)
                ->where('status', 'pending')->count();
        }
        if ($manages) {
            $badges['approvals'] = Approval::where('organization_id', $org->id)->where('status', 'pending')->count()
                + InvoiceUpdateRequest::where('organization_id', $org->id)->where('status', 'pending')->count()
                + ClientAccessRequest::where('organization_id', $org->id)->where('status', 'pending')->count();
        }

        // Open complaints on my desk (managers also carry the unallocated).
        if ($me->can('complaints', 'view')) {
            $badges['complaints'] = Complaint::where('organization_id', $org->id)
                ->where('status', 'not like', 'closed%')
                ->where(function ($q) use ($me, $manages) {
                    $q->where('allocated_to_member_id', $me->id);
                    if ($manages) {
                        $q->orWhereNull('allocated_to_member_id');
                    }
                })
                ->count();
        }
        return $badges;
    }

    /**
     * Which trail entries belong to which sidebar entry. A section with no
     * prefixes simply never counts — better a quiet menu than a wrong number.
     */
    private const SECTION_ACTIONS = [
        'leads' => ['lead.'],
        'clients' => ['client.'],
        'proforma' => ['proforma.'],
        'invoices' => ['invoice.'],
        'payments' => ['payment.'],
        'commissions' => ['commission.'],
        'expenses' => ['expense.'],
        'vendors' => ['vendor.'],
        'salary' => ['salary.'],
        'employees' => ['employee.'],
        'tasks' => ['task.'],
        'leaves' => ['leave.'],
        'approvals' => ['approval.'],
        'dwr' => ['dwr.'],
        'punch' => ['punch.'],
        'targets' => ['target.'],
        'contests' => ['contest.'],
        'newsletters' => ['newsletter.'],
        'cms' => ['cms.'],
        'complaints' => ['complaint.'],
        'settings' => ['settings.', 'dcw.'],
    ];

    /**
     * What colleagues have done in each section since this member last looked
     * there — the numbers beside the sidebar entries.
     *
     * The marker is the last trail entry they had seen, never a clock
     * reading: two things inside one second must not make one invisible.
     * Only other people's work counts — nobody needs a badge for what they
     * just did. An employee is told what their own team did; a manager, the
     * company.
     */
    private function sectionCounts($org, Member $me): array
    {
        $seen = DB::table('crm_section_views')
            ->where('member_id', $me->id)
            ->pluck('last_activity_id', 'section');

        // The sweep starts below every section's marker. A section never
        // opened has no marker at all, so the sweep then falls back to a
        // ninety-day window rather than the whole history of the company.
        $unseenSections = count($seen) < count(self::SECTION_ACTIONS);
        $floorId = $unseenSections ? 0 : (int) collect($seen)->min();

        $entries = ActivityLog::where('organization_id', $org->id)
            ->where('id', '>', $floorId)
            ->when($unseenSections, fn ($q) => $q->where('created_at', '>=', now()->subDays(90)))
            ->where(fn ($q) => $q->whereNull('member_id')->orWhere('member_id', '!=', $me->id))
            ->when(! in_array($me->crm_role, ['admin', 'subadmin'], true), function ($q) use ($me) {
                $team = $me->teamMemberIds();
                $q->where(fn ($w) => $w->whereIn('member_id', $team)->orWhereNull('member_id'));
            })
            ->get(['id', 'action']);

        $counts = [];
        foreach (self::SECTION_ACTIONS as $section => $prefixes) {
            // Never looked: everything the sweep holds is new to them.
            $floor = (int) ($seen[$section] ?? 0);

            $count = $entries->filter(function ($entry) use ($prefixes, $floor) {
                if ($entry->id <= $floor) {
                    return false;
                }
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($entry->action, $prefix)) {
                        return true;
                    }
                }

                return false;
            })->count();

            if ($count > 0) {
                $counts[$section] = $count;
            }
        }

        return $counts;
    }

    /** "I have looked at this section" — the badge goes quiet. */
    public function markSectionSeen(Request $request, string $section): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        if (! array_key_exists($section, self::SECTION_ACTIONS)) {
            abort(404, 'No such section.');
        }

        DB::table('crm_section_views')->updateOrInsert(
            ['member_id' => $me->id, 'section' => $section],
            [
                'last_activity_id' => (int) ActivityLog::where('organization_id', $org->id)->max('id'),
                'seen_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return response()->json(['message' => 'Seen.']);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        // Whose figures this dashboard shows: 'mine' is anyone's own sales;
        // the combined view is the company for a manager, the subtree for a
        // Team Head — the same two ledgers the document lists keep apart.
        $scope = $request->query('scope') === 'mine' ? 'mine' : 'team';
        $window = $me->salesWindow($scope);
        // The dropdown's options are the window BEFORE anyone is picked out
        // of it — picking a person must never shrink the list of people.
        $optionsWindow = $window;

        // One person's figures out of the combined view. Narrowing only: a
        // uuid outside the window shows nothing, never someone else's sales.
        if ($picked = $request->query('salesperson')) {
            $member = Member::where('organization_id', $org->id)->where('uuid', $picked)->first();
            $window = ($member && ($window === null || in_array($member->id, $window, true)))
                ? [$member->id] : [0];
        }

        $userWindow = $window === null ? null
            : Member::whereIn('id', $window)->pluck('user_id')->all();

        $sales = function ($query) use ($window, $userWindow) {
            return $window === null ? $query : $query->where(fn ($q) => $q
                ->whereIn('member_id', $window)
                ->orWhere(fn ($w) => $w->whereNull('member_id')->whereIn('created_by', $userWindow)));
        };

        $invoices = $sales(Invoice::where('organization_id', $org->id)->where('status', '!=', 'cancelled'));

        $received = \App\Models\Crm\InvoicePayment::whereHas('invoice', fn ($q) => $sales($q
            ->where('organization_id', $org->id)->where('status', '!=', 'cancelled')))
            ->where('received_at', '>=', $monthStart)
            ->sum('amount');

        // Birthdays within the next 7 days, month-boundary safe.
        $birthdays = Member::visible()->with(['user:id,name', 'user.profile:user_id,photo_path,avatar,gender'])
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->whereNotNull('dob')
            ->get()
            ->map(function ($m) {
                $next = $m->dob->copy()->year(now()->year);
                if ($next->isPast() && ! $next->isToday()) {
                    $next = $next->addYear();
                }

                return [
                    'name' => $m->user?->name,
                    'photo_path' => $m->user?->profile?->photo_path,
                    'avatar' => $m->user?->profile?->avatar,
                    'gender' => $m->gender ?? $m->user?->profile?->gender,
                    'date' => $next->toDateString(),
                    'in_days' => (int) now()->startOfDay()->diffInDays($next),
                ];
            })
            ->filter(fn ($b) => $b['in_days'] <= 7)
            ->sortBy('in_days')
            ->values();

        return response()->json(['data' => [
            'employees' => [
                'total' => Member::visible()->where('organization_id', $org->id)->count(),
                'active' => Member::visible()->where('organization_id', $org->id)->where('status', 'active')->count(),
            ],
            'clients' => [
                // The portfolio this window holds, not the whole book.
                'total' => $this->clientQuery($org->id, $window)->count(),
                'active' => $this->clientQuery($org->id, $window)->where('status', 'active')->count(),
            ],
            'invoices' => [
                'month_count' => (clone $invoices)->where('kind', 'invoice')->where('invoice_date', '>=', $monthStart)->count(),
                'month_total' => (clone $invoices)->where('kind', 'invoice')->where('invoice_date', '>=', $monthStart)->sum('total'),
                'proforma_open' => (clone $invoices)->where('kind', 'proforma')->whereDoesntHave('convertedTo')->count(),
                'outstanding' => (clone $invoices)->where('kind', 'invoice')->whereIn('payment_status', ['due', 'partial'])
                    ->get(['id', 'total'])
                    ->sum(fn ($i) => (float) $i->total - (float) $i->payments()->sum('amount')),
                'received_this_month' => $received,
            ],
            'recent_invoices' => (clone $invoices)->with('client:id,uuid,company_name')
                ->latest()->limit(8)->get()
                ->map(fn ($i) => [
                    'uuid' => $i->uuid,
                    'kind' => $i->kind,
                    'number' => $i->number,
                    'client' => $i->client?->company_name,
                    'invoice_date' => $i->invoice_date->toDateString(),
                    'total' => $i->total,
                    'currency' => $i->currency,
                    'payment_status' => $i->payment_status,
                ]),
            'birthdays' => $birthdays,
            // Chart feeds: the lead pipeline by status, and where invoice
            // money stands — both across the whole org, not just a month.
            'charts' => [
                'leads_by_status' => \App\Models\Crm\Lead::where('organization_id', $org->id)
                    ->when($window !== null, fn ($q) => $q->where(fn ($w) => $w
                        ->whereIn('assigned_member_id', $window)
                        ->orWhereIn('created_by', $userWindow)))
                    ->selectRaw('lead_status, count(*) as n')
                    ->groupBy('lead_status')
                    ->pluck('n', 'lead_status'),
                'invoices_by_payment' => (clone $invoices)->where('kind', 'invoice')
                    ->selectRaw('payment_status, count(*) as n, sum(total) as amount')
                    ->groupBy('payment_status')
                    ->get()
                    ->map(fn ($r) => ['status' => $r->payment_status, 'count' => (int) $r->n, 'amount' => (float) $r->amount]),
            ],
            'today' => $today,
            'scope' => $scope,
            // Who the combined view can be narrowed to.
            'salespeople' => $scope === 'team'
                ? Member::visible()->with('user:id,name')
                    ->where('organization_id', $org->id)
                    ->where('status', 'active')
                    ->when($optionsWindow !== null, fn ($q) => $q->whereIn('id', $optionsWindow))
                    ->get()
                    ->map(fn (Member $m) => [
                        'uuid' => $m->uuid,
                        'name' => $m->user?->name,
                        'is_me' => $m->id === $me->id,
                    ])
                    ->values()
                : null,
        ]]);
    }

    /** The clients a sales window holds: owned by it, or shared into it. */
    private function clientQuery(int $orgId, ?array $window)
    {
        return Client::where('organization_id', $orgId)
            ->when($window !== null, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('assigned_member_id', $window)
                ->orWhereHas('sharedWith', fn ($sh) => $sh->whereIn('crm_members.id', $window))));
    }

    private function serializeMember(Member $member): array
    {
        $profile = $member->user?->profile;

        return [
            'uuid' => $member->uuid,
            'name' => $member->user?->name,
            // The person's face, same resolution order as the Netvork side:
            // uploaded photo, picked illustration, gender default.
            'photo_path' => $profile?->photo_path,
            'avatar' => $profile?->avatar,
            'gender' => $member->gender ?? $profile?->gender,
            // Today is their day: the shell turns festive and the song plays.
            'birthday_today' => $member->dob !== null && $member->dob->isBirthday(),
            'crm_role' => $member->crm_role,
            'is_oversight' => $member->is_oversight,
            'employee_code' => $member->employee_code,
            'department' => $member->department,
            'designation' => $member->designation,
            'is_salesperson' => $member->is_salesperson,
            // The job carries every capability; anyone else holds what was
            // granted. The UI reads this to know which buttons to draw.
            'capabilities' => in_array($member->crm_role, ['admin', 'subadmin'], true)
                ? array_keys(Member::CAPABILITIES)
                : array_values((array) ($member->capabilities ?? [])),
            'leads_a_team' => $member->leadsATeam(),
            // The accounting export: the Admin, plus a Subadmin named with
            // the raw exports.excel grant (never by role alone).
            'can_export' => $member->crm_role === 'admin'
                || ($member->crm_role === 'subadmin'
                    && in_array('exports.excel', (array) ($member->capabilities ?? []), true)),
            // Who they may hand work to: a manager may pick anyone, so the
            // list is null; anyone else moves work inside their own team.
            'team_member_uuids' => in_array($member->crm_role, ['admin', 'subadmin'], true)
                ? null
                : Member::whereIn('id', $member->teamMemberIds())->pluck('uuid')->all(),
            'rights' => $member->crm_role === 'admin'
                ? collect(Member::moduleSlugs())->mapWithKeys(fn ($m) => [$m => Member::ABILITIES])
                : ($member->rights ?? (object) []),
            /*
             * Whether this admin may open a member's workspace, and how far
             * in — null when the platform has granted the company nothing,
             * which is also when the button is not drawn.
             *
             * Read from the organization on every call rather than cached
             * anywhere: it is a grant that can be withdrawn, and a screen
             * still offering a button after that is a screen making a promise
             * the server will refuse to keep.
             */
            'impersonation_level' => $member->impersonationLevel(),
            /*
             * Whether the rights editor is theirs to use at all.
             *
             * A boolean rather than leaving the screen to read `capabilities`,
             * because that list answers with every key by role for an Admin
             * and a Subadmin alike — so the one question that matters here,
             * "were you named", is exactly the one it cannot answer.
             */
            'can_set_rights' => $member->maySetRightsAtAll(),
            // And whether this session is itself a borrowed one, so the shell
            // can say whose seat it is and offer the way out of it.
            'impersonating' => $this->borrowedSeat(),
        ];
    }

    /**
     * The seat this session is borrowing, if it is borrowing one.
     *
     * Answered from the token rather than from anything the client sent,
     * because it is the token that decides — a browser claiming not to be
     * impersonating changes nothing about what it may reach, and one claiming
     * that it is would otherwise be able to draw the banner on a session that
     * is perfectly ordinary.
     */
    protected function borrowedSeat(): ?array
    {
        $token = request()->user()?->currentAccessToken();

        if (! ImpersonationController::isBorrowed($token)) {
            return null;
        }

        return ['level' => ImpersonationController::levelOf($token)];
    }
}
