<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Connection;
use App\Models\Crm\Document;
use App\Models\Crm\Member;
use App\Models\Crm\SalaryRecord;
use App\Models\Role;
use App\Models\User;
use App\Services\AppIdService;
use App\Support\TextCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CRM employee master: Admin / Subadmin / Employee registration with the
 * full employment profile the old CRM carried — family details, statutory
 * numbers, bank account, per-module rights, salary history and documents.
 *
 * An employee is a Netvork account plus a crm_members row. Creating one here
 * either links an existing account (by email) or registers a fresh verified
 * account, so staff sign in through the normal Netvork door.
 */
class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = Member::visible()->with(['user:id,name,email', 'manager.user:id,name', 'leaders.user:id,name'])
            ->where('organization_id', $org->id);

        // A Team Head's window is their subtree; admins see the company.
        if (! in_array($me->crm_role, ['admin', 'subadmin'], true)) {
            $query->whereIn('id', $me->teamMemberIds());
        }

        // One leader's team: through the org chart or the Team Workspace.
        if ($reportsTo = $request->query('reports_to')) {
            $query->where(fn ($q) => $q
                ->whereHas('manager', fn ($m) => $m->where('uuid', $reportsTo))
                ->orWhereHas('leaders', fn ($m) => $m->where('uuid', $reportsTo)));
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('employee_code', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('designation', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }
        if ($role = $request->query('crm_role')) {
            $query->where('crm_role', $role);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $members = $query->orderBy('id')->paginate(25);
        $members->getCollection()->transform(fn ($m) => $this->serialize($m));

        return response()->json($members);
    }

    /** Names are not unique, so a search can only ever answer with a shortlist. */
    private const LOOKUP_LIMIT = 20;

    /**
     * The register form's first step: everyone signs up on Netvork the
     * normal way, then the company fetches that account here and only fills
     * in the employment side. Returns just enough identity to recognise the
     * person - never their private profile.
     *
     * Matching is partial and covers the name as well, because whoever is
     * registering a new hire knows them as "Priyanshu" and not as
     * priyanshuyadav@… — an exact-username box asks the company to already
     * know the answer it came here for. Several Priyanshus therefore come
     * back together and the person choosing decides, which is the honest
     * shape of the question: two people can share a name.
     */
    public function lookupAccount(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');

        /*
         * Two characters at the least. This is an ordinary search over every
         * Netvork account, so a single letter would hand back a slice of the
         * whole directory to anyone who can register an employee.
         */
        $valid = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:255'],
        ]);
        $q = mb_strtolower(trim((string) ($valid['q'] ?? '')));

        $me = $request->user();

        /*
         * The people this admin already knows.
         *
         * Netvork's own add-connection box leads with these, and a company
         * registering staff is almost always registering people it is
         * connected to — so they lead here too, and with no search typed at
         * all they are the whole list. An empty box that offers nothing is a
         * box asking you to guess.
         */
        $connectionIds = Connection::where('status', 'accepted')
            ->where(fn ($w) => $w->where('requester_id', $me->id)->orWhere('addressee_id', $me->id))
            ->get(['requester_id', 'addressee_id'])
            ->flatMap(fn ($c) => [$c->requester_id, $c->addressee_id])
            ->unique()
            ->reject(fn ($id) => $id === $me->id)
            ->values()
            ->all();

        $users = User::with('appId')->where('status', 'active')->whereKeyNot($me->id);

        if ($q === '') {
            // Nothing typed: this is the address book, not a search of
            // everybody, so it never reaches past the people they know.
            $users->whereIn('id', $connectionIds);
        } else {
            // LIKE's own wildcards, escaped: a search for "50%" is a search
            // for the characters, not for everything.
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

            $users->where(fn ($w) => $w
                ->whereRaw('LOWER(email) LIKE ?', [$like])
                ->orWhereRaw('LOWER(username) LIKE ?', [$like])
                ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                // The App ID is what the person is called on their own
                // profile screen, so it is what somebody reads it off and
                // types in. Only a live one: a retired App ID names nobody.
                ->orWhereHas('appId', fn ($a) => $a
                    ->whereRaw('LOWER(app_id) LIKE ?', [$like])
                    ->where('is_active', true)))
                /*
                 * Whoever typed the whole username or email meant that
                 * person, so they lead however many near-misses share the
                 * spelling.
                 */
                ->orderByRaw(
                    'CASE WHEN LOWER(email) = ? OR LOWER(username) = ? '
                    . 'OR EXISTS (SELECT 1 FROM app_ids WHERE app_ids.user_id = users.id AND LOWER(app_ids.app_id) = ?) '
                    . 'THEN 0 ELSE 1 END',
                    [$q, $q, $q],
                );
        }

        $users = $users
            // Someone you know beats a stranger of the same name.
            ->orderByRaw($connectionIds === []
                ? '1'
                : 'CASE WHEN users.id IN (' . implode(',', array_fill(0, count($connectionIds), '?')) . ') THEN 0 ELSE 1 END',
                $connectionIds)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::LOOKUP_LIMIT + 1)
            ->get();

        /*
         * An empty address book is not an error — there is simply nobody to
         * show yet, and the screen says so. A search that found nobody still
         * is: it was a question with an answer of no.
         */
        abort_if($users->isEmpty() && $q !== '', 404, 'No Netvork account matches that name, email, username or App ID.');

        // One more was fetched than is shown, purely to know whether to say so.
        $more = $users->count() > self::LOOKUP_LIMIT;
        $users = $users->take(self::LOOKUP_LIMIT);

        $members = Member::where('organization_id', $org->id)
            ->whereIn('user_id', $users->pluck('id'))
            ->pluck('user_id')->all();

        return response()->json([
            'data' => $users->map(fn ($user) => [
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                // Shown beside the name: two people called Priyanshu Kumar
                // are told apart by this, not by their surname.
                'app_id' => $user->appId?->app_id,
                'already_member' => in_array($user->id, $members, true),
                // Labelled, the way the add-connection box labels its own.
                'connected' => in_array($user->id, $connectionIds, true),
            ])->values(),
            'truncated' => $more,
        ]);
    }

    public function store(Request $request, AppIdService $appIds): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $data = $this->validateProfile($request, $org->id);
        if ($request->attributes->get('crm_member')?->crm_role !== 'admin') {
            unset($data['late_waived'], $data['punch_waived']);
        }

        // Left blank, the code numbers itself - EMP-101 onwards.
        if (empty($data['employee_code'])) {
            $data['employee_code'] = Member::nextEmployeeCode($org->id);
        }

        // Everyone sits under the company by default: a new hire reports to
        // the Admin. Who leads whom is the Team Workspace's job now, so the
        // form no longer asks — but the org chart still has a top.
        if (! array_key_exists('reporting_to', $data)) {
            $data['reporting_to'] = Member::visible()
                ->where('organization_id', $org->id)
                ->where('crm_role', 'admin')->where('status', 'active')
                ->orderBy('id')->value('id');
        }

        $account = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $member = DB::transaction(function () use ($org, $data, $account, $request, $appIds) {
            $user = User::where('email', $account['email'])->first();

            if (! $user) {
                if (empty($account['password'])) {
                    abort(422, 'A password is required when the email is not an existing Netvork account.');
                }
                $user = User::create([
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'password' => $account['password'],
                ]);
                // Registered by the company, not self-serve — the address is
                // taken as proven. Not fillable, so set explicitly.
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->profile()->create([]);
                $user->settings()->create([]);
                $appIds->generateFor($user);
                $role = Role::where('slug', 'user')->first();
                if ($role) {
                    $user->roles()->attach($role->id, ['assigned_by' => $request->user()->id]);
                }
            }

            if (Member::where('organization_id', $org->id)->where('user_id', $user->id)->exists()) {
                abort(422, 'This account is already an employee of this organization.');
            }

            $member = Member::create($data + [
                'organization_id' => $org->id,
                'user_id' => $user->id,
            ]);

            if ($salary = $request->input('salary')) {
                $request->validate([
                    'salary.amount' => ['required', 'numeric', 'min:0'],
                    'salary.effective_from' => ['required', 'date'],
                ]);
                SalaryRecord::create([
                    'member_id' => $member->id,
                    'amount' => $salary['amount'],
                    'currency' => $salary['currency'] ?? 'INR',
                    'effective_from' => $salary['effective_from'],
                    'note' => $salary['note'] ?? 'Starting salary',
                    'created_by' => $request->user()->id,
                ]);
            }

            return $member;
        });

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'employee.created', $member);
        $this->syncTeam($request, $member);

        return response()->json([
            'message' => 'Employee registered.',
            'data' => $this->serialize($member->load(['user:id,name,email', 'manager.user:id,name'])),
        ], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $member = $this->find($request, $uuid)
            ->load(['user:id,name,email', 'manager.user:id,name', 'salaryRecords', 'documents',
                'team.user:id,name', 'leaders.user:id,name']);

        $data = $this->serialize($member, full: true);

        // A person's file is their own: a Team Workspace leader opening a
        // team member's profile gets the WORKING record (name, code, role,
        // department, designation, joining, team) — never the personal
        // details, family, statutory numbers, bank account, pay, documents
        // or rights. Those are the Admin's and the person's alone.
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        if ($member->id !== $me->id && ! in_array($me->crm_role, ['admin', 'subadmin'], true)) {
            foreach ([
                'title', 'batch', 'gender', 'father_name', 'father_phone', 'mother_name',
                'mother_phone', 'dob', 'present_address', 'present_phone', 'office_phone',
                'permanent_address', 'permanent_phone', 'personal_email', 'pf_no', 'esi_no',
                'pan_no', 'aadhaar_no', 'bank_name', 'bank_account_no', 'bank_ifsc',
                'bank_account_name', 'note', 'probation_days', 'probation_ends_on',
                'late_waived', 'punch_waived', 'resigned_at',
            ] as $field) {
                $data[$field] = null;
            }
            $data['salary_records'] = [];
            $data['documents'] = [];
            $data['rights'] = (object) [];
            $data['capabilities'] = [];
            $data['personal_hidden'] = true;
        }

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $member = $this->find($request, $uuid);

        $data = $this->validateProfile($request, $org->id, $member->id);

        // Both waivers are the Admin's alone — a Subadmin's payload simply
        // does not carry them.
        if ($request->attributes->get('crm_member')?->crm_role !== 'admin') {
            unset($data['late_waived'], $data['punch_waived']);
        }

        // The last admin cannot demote or deactivate themselves out of the org.
        $losingAdmin = $member->crm_role === 'admin'
            && (($data['crm_role'] ?? 'admin') !== 'admin' || ($data['status'] ?? 'active') !== 'active');
        if ($losingAdmin && Member::visible()->where('organization_id', $org->id)->where('crm_role', 'admin')->where('status', 'active')->count() <= 1) {
            abort(422, 'The organization must keep at least one active CRM admin.');
        }

        $before = $member->only(array_keys($data));
        $member->update($data);

        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'employee.updated', $member, [
            'before' => array_diff_assoc(array_map('strval', array_filter($before, 'is_scalar')), array_map('strval', array_filter($member->only(array_keys($data)), 'is_scalar'))),
        ]);

        // A longer probation is a judgement about a person, so it gets a line
        // of its own in the trail rather than hiding inside a field diff.
        if (array_key_exists('probation_days', $data)
            && ($before['probation_days'] ?? null) != $data['probation_days']) {
            $policyDays = (int) $org->hrPolicy()['probation_days'];
            ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'employee.probation_changed', $member, [
                'from' => $before['probation_days'] === null
                    ? $policyDays . ' (policy)' : (string) $before['probation_days'],
                'to' => $data['probation_days'] === null
                    ? $policyDays . ' (policy)' : (string) $data['probation_days'],
                'ends_on' => $member->probationEndsOn($policyDays)?->toDateString(),
            ]);
        }

        $this->syncTeam($request, $member);

        return response()->json([
            'message' => 'Employee updated.',
            'data' => $this->serialize($member->fresh()->load([
                'user:id,name,email', 'manager.user:id,name', 'team.user:id,name', 'leaders.user:id,name',
            ]), full: true),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $member = $this->find($request, $uuid);

        if ($member->crm_role === 'admin'
            && Member::visible()->where('organization_id', $org->id)->where('crm_role', 'admin')->where('status', 'active')->count() <= 1) {
            abort(422, 'The organization must keep at least one active CRM admin.');
        }

        // Deactivate, never delete: invoices and logs keep pointing at people.
        $member->update(['status' => 'inactive', 'resigned_at' => $member->resigned_at ?? now()->toDateString()]);
        ActivityLog::record($request->attributes->get('crm_member'), $org->id, 'employee.deactivated', $member);

        return response()->json(['message' => 'Employee deactivated.']);
    }

    // ---- Salary ------------------------------------------------------------

    public function addSalary(Request $request, string $uuid): JsonResponse
    {
        $member = $this->find($request, $uuid);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'effective_from' => ['required', 'date'],
            'designation' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $record = SalaryRecord::create($data + [
            'member_id' => $member->id,
            'currency' => $data['currency'] ?? 'INR',
            'created_by' => $request->user()->id,
        ]);

        // A revision that names a designation IS the promotion: the profile
        // moves with it, and the record keeps the trail for letters.
        if (! empty($data['designation'])) {
            $member->update(['designation' => $data['designation']]);
        }

        ActivityLog::record($request->attributes->get('crm_member'), $member->organization_id, 'employee.salary_added', $member, array_filter([
            'amount' => $data['amount'],
            'effective_from' => $data['effective_from'],
            'designation' => $data['designation'] ?? null,
        ]));

        return response()->json(['message' => 'Salary recorded.', 'data' => $record], 201);
    }

    public function deleteSalary(Request $request, string $uuid, int $recordId): JsonResponse
    {
        $member = $this->find($request, $uuid);
        $member->salaryRecords()->whereKey($recordId)->firstOrFail()->delete();

        return response()->json(['message' => 'Salary record removed.']);
    }

    // ---- Documents ---------------------------------------------------------

    public function uploadDocument(Request $request, string $uuid): JsonResponse
    {
        $member = $this->find($request, $uuid);
        $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');
        $path = $file->store('crm-documents/' . $member->organization_id . '/members/' . $member->id, 'local');

        $document = Document::create([
            'organization_id' => $member->organization_id,
            'documentable_type' => Member::class,
            'documentable_id' => $member->id,
            // A blank name falls back to the file's own — one less reason
            // for the upload button to sit disabled.
            'name' => trim((string) $request->string('name')) !== ''
                ? (string) $request->string('name')
                : $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Document uploaded.', 'data' => $document], 201);
    }

    public function downloadDocument(Request $request, string $uuid, string $documentUuid): StreamedResponse
    {
        $member = $this->find($request, $uuid);
        $document = $member->documents()->where('uuid', $documentUuid)->firstOrFail();

        return Storage::disk('local')->download($document->path, $document->name);
    }

    public function deleteDocument(Request $request, string $uuid, string $documentUuid): JsonResponse
    {
        $member = $this->find($request, $uuid);
        $document = $member->documents()->where('uuid', $documentUuid)->firstOrFail();

        Storage::disk('local')->delete($document->path);
        $document->delete();

        return response()->json(['message' => 'Document removed.']);
    }

    // ---- One's own record, no employees right needed -----------------------

    /**
     * The employee's own profile — the data their HR letters and documents
     * card read from. Letters download rides the 'letters.download'
     * capability the Admin grants by name; documents are always theirs to
     * take (they are ABOUT them).
     */
    public function myProfile(Request $request): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $me->load(['user:id,name,email', 'manager.user:id,name', 'salaryRecords', 'documents',
            'team.user:id,name', 'leaders.user:id,name']);

        return response()->json(['data' => $this->serialize($me, full: true) + [
            'letters_allowed' => $me->allows('letters.download'),
        ]]);
    }

    /** Download one of one's OWN documents — the file is about them. */
    public function downloadMyDocument(Request $request, string $documentUuid): StreamedResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $document = $me->documents()->where('uuid', $documentUuid)->firstOrFail();

        return Storage::disk('local')->download($document->path, $document->name);
    }

    // ---- Helpers -----------------------------------------------------------

    private function find(Request $request, string $uuid): Member
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        return Member::where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid)
            // Team Heads can open only their own subtree by direct URL too.
            ->when(! in_array($me->crm_role, ['admin', 'subadmin'], true),
                fn ($q) => $q->whereIn('id', $me->teamMemberIds()))
            ->firstOrFail();
    }

    private function validateProfile(Request $request, int $orgId, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'crm_role' => ['required', Rule::in(Member::ROLES)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'employee_code' => ['nullable', 'string', 'max:64',
                Rule::unique('crm_members', 'employee_code')->where('organization_id', $orgId)->ignore($ignoreId)],
            'title' => ['nullable', 'string', 'max:8'],
            'department' => ['nullable', 'string', 'max:64'],
            'designation' => ['nullable', 'string', 'max:64'],
            'batch' => ['nullable', 'string', 'max:64'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'father_phone' => ['nullable', 'string', 'max:32'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'mother_phone' => ['nullable', 'string', 'max:32'],
            'dob' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'present_address' => ['nullable', 'string', 'max:512'],
            'present_phone' => ['nullable', 'string', 'max:32'],
            'office_phone' => ['nullable', 'string', 'max:32'],
            'permanent_address' => ['nullable', 'string', 'max:512'],
            'permanent_phone' => ['nullable', 'string', 'max:32'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'joined_at' => ['nullable', 'date'],
            // Null means "whatever the HR Policy says"; a number here is a
            // deliberate exception for this person, and is logged as one.
            'probation_days' => ['nullable', 'integer', 'min:0', 'max:1095'],
            // The Admin's waivers — only the Admin may move either.
            'late_waived' => ['nullable', 'boolean'],
            'punch_waived' => ['nullable', 'boolean'],
            'resigned_at' => ['nullable', 'date', 'after_or_equal:joined_at'],
            'is_salesperson' => ['nullable', 'boolean'],
            'pf_no' => ['nullable', 'string', 'max:64'],
            'esi_no' => ['nullable', 'string', 'max:64'],
            'pan_no' => ['nullable', 'string', 'max:32'],
            'aadhaar_no' => ['nullable', 'string', 'max:32'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:64'],
            'bank_ifsc' => ['nullable', 'string', 'max:32'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'reporting_to_uuid' => ['nullable', 'string'],
            'rights' => ['nullable', 'array'],
            // The delicate acts an Admin grants by name, account by account.
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => [Rule::in(array_keys(Member::CAPABILITIES))],
            'rights.*' => ['array'],
            'rights.*.*' => [Rule::in(Member::ABILITIES)],
            'note' => ['nullable', 'string', 'max:2000'],
            // The Team Workspace ticks; consumed by syncTeam, never a column.
            'team_member_uuids' => ['nullable', 'array'],
            'team_member_uuids.*' => ['string'],
        ]);
        unset($data['team_member_uuids']);

        foreach (['father_name', 'mother_name', 'bank_account_name', 'bank_name', 'department', 'designation'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = TextCase::name($data[$field]);
            }
        }
        if (array_key_exists('personal_email', $data)) {
            $data['personal_email'] = TextCase::email($data['personal_email']);
        }
        foreach (['pan_no', 'aadhaar_no', 'pf_no', 'esi_no', 'bank_ifsc', 'employee_code'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = TextCase::code($data[$field]);
            }
        }

        return $data + $this->resolveManager($request, $orgId, $ignoreId);
    }

    /**
     * The Team Workspace: apply the Admin's ticks of who this person
     * handles. Only an Admin/Subadmin steers this — anyone else's payload is
     * left unread, so employees see their access but never change it.
     */
    private function syncTeam(Request $request, Member $member): void
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        if (! $request->has('team_member_uuids')
            || ! in_array($me->crm_role, ['admin', 'subadmin'], true)) {
            return;
        }

        // Employees only: the Admin side controls everything already, so it
        // is never handed into someone's hands.
        $ids = Member::where('organization_id', $member->organization_id)
            ->whereIn('uuid', array_filter((array) $request->input('team_member_uuids')))
            ->where('id', '!=', $member->id)
            ->where('crm_role', 'employee')
            ->pluck('id')->all();

        $before = $member->team()->pluck('crm_members.id')->all();
        sort($before);
        $after = $ids;
        sort($after);
        if ($before === $after) {
            return;
        }

        // A withdrawal is dated, never deleted: the leader's ledger keeps
        // the team-incentive months already released, marked "access
        // withdrawn", and only the upcoming months stop.
        $removed = array_values(array_diff($before, $ids));
        $added = array_values(array_diff($ids, $before));
        if ($removed !== []) {
            DB::table('crm_team_access')->where('leader_id', $member->id)
                ->whereIn('member_id', $removed)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
        }
        foreach ($added as $id) {
            $revived = DB::table('crm_team_access')->where('leader_id', $member->id)
                ->where('member_id', $id)
                ->update(['revoked_at' => null, 'updated_at' => now()]);
            if (! $revived) {
                $member->team()->attach($id);
            }
        }

        $names = fn (array $memberIds) => Member::whereIn('id', $memberIds)->with('user:id,name')->get()
            ->map(fn ($m) => $m->user?->name)->filter()->implode(', ');

        // Who holds whom is authority, so the change gets its own trail line.
        ActivityLog::record($me, $member->organization_id, 'employee.team_updated', $member, array_filter([
            'handles' => $names($ids) ?: 'nobody',
            'access_withdrawn' => $removed !== [] ? $names($removed) : null,
        ]));
    }

    /** @return array{reporting_to?: int|null} */
    private function resolveManager(Request $request, int $orgId, ?int $ignoreId): array
    {
        if (! $request->has('reporting_to_uuid')) {
            return [];
        }
        $uuid = $request->input('reporting_to_uuid');
        if (! $uuid) {
            return ['reporting_to' => null];
        }

        $manager = Member::where('organization_id', $orgId)->where('uuid', $uuid)->first();
        if (! $manager || $manager->id === $ignoreId) {
            abort(422, 'The reporting manager must be another employee of this organization.');
        }

        return ['reporting_to' => $manager->id];
    }

    private function serialize(Member $m, bool $full = false): array
    {
        $base = [
            'uuid' => $m->uuid,
            'name' => $m->user?->name,
            'email' => $m->user?->email,
            'crm_role' => $m->crm_role,
            'status' => $m->status,
            'employee_code' => $m->employee_code,
            'department' => $m->department,
            'designation' => $m->designation,
            'is_salesperson' => $m->is_salesperson,
            'joined_at' => $m->joined_at?->toDateString(),
            'probation_days' => $m->probation_days,
            'late_waived' => (bool) $m->late_waived,
            'punch_waived' => (bool) $m->punch_waived,
            'probation_ends_on' => $m->probationEndsOn(
                (int) $m->organization?->hrPolicy()['probation_days']
            )?->toDateString(),
            'on_probation' => $m->onProbation((int) $m->organization?->hrPolicy()['probation_days']),
            'resigned_at' => $m->resigned_at?->toDateString(),
            'dob' => $m->dob?->toDateString(),
            'manager' => $m->manager ? ['uuid' => $m->manager->uuid, 'name' => $m->manager->user?->name] : null,
            // Whose hands this person is in (Team Workspace) — the list's
            // "Team leader" column prefers this over the org-chart manager.
            'team_leaders' => $m->relationLoaded('leaders')
                ? $m->leaders->map(fn ($l) => ['uuid' => $l->uuid, 'name' => $l->user?->name])->values()
                : null,
        ];

        if (! $full) {
            return $base;
        }

        return $base + [
            'title' => $m->title,
            'batch' => $m->batch,
            'gender' => $m->gender,
            'father_name' => $m->father_name,
            'father_phone' => $m->father_phone,
            'mother_name' => $m->mother_name,
            'mother_phone' => $m->mother_phone,
            'present_address' => $m->present_address,
            'present_phone' => $m->present_phone,
            'office_phone' => $m->office_phone,
            'permanent_address' => $m->permanent_address,
            'permanent_phone' => $m->permanent_phone,
            'personal_email' => $m->personal_email,
            'pf_no' => $m->pf_no,
            'esi_no' => $m->esi_no,
            'pan_no' => $m->pan_no,
            'aadhaar_no' => $m->aadhaar_no,
            'bank_name' => $m->bank_name,
            'bank_account_no' => $m->bank_account_no,
            'bank_ifsc' => $m->bank_ifsc,
            'bank_account_name' => $m->bank_account_name,
            'rights' => $m->rights ?? (object) [],
            'capabilities' => array_values((array) ($m->capabilities ?? [])),
            'note' => $m->note,
            // The Team Workspace: who this person handles. Everyone reads
            // it; only an Admin/Subadmin changes it. (team_leaders — whose
            // hands they are in — already rides on the base block.)
            'team' => $m->team->map(fn ($t) => ['uuid' => $t->uuid, 'name' => $t->user?->name])->values(),
            'salary_records' => $m->salaryRecords->map(fn ($s) => [
                'id' => $s->id,
                'amount' => $s->amount,
                'currency' => $s->currency,
                'effective_from' => $s->effective_from->toDateString(),
                'designation' => $s->designation,
                'note' => $s->note,
                // When the revision was recorded — the promotion letter's
                // own date when history reprints it.
                'created_at' => $s->created_at?->toDateString(),
            ]),
            'documents' => $m->documents->map(fn ($d) => [
                'uuid' => $d->uuid,
                'name' => $d->name,
                'mime' => $d->mime,
                'size' => $d->size,
                'uploaded_at' => $d->created_at->toDateTimeString(),
            ]),
        ];
    }
}
