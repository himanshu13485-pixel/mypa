<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Client;
use App\Models\Crm\Complaint;
use App\Models\Crm\ComplaintReply;
use App\Models\Crm\Document;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Notifications\CrmNotification;
use App\Support\TextCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Complaint Management System.
 *
 * A complaint is a promise with a clock on it. The register therefore tracks
 * three things at once: whose desk it is on, how long it has been waiting,
 * and — once it is closed — whose mistake it was.
 *
 * Each complaint carries two conversations. The `client` thread is what the
 * client is told; the `internal` thread is the office working out what
 * happened, and no client-facing surface ever renders it.
 *
 * The words a complaint is filed under — source, subject, type, mode — are
 * the company's own lists, kept by the Admin or a Subadmin. Nobody else can
 * add to them mid-complaint, so the same problem is always filed under the
 * same name and the reports mean something.
 */
class ComplaintController extends Controller
{
    /**
     * The popup's feed: open complaints that are THIS person's to answer —
     * allocated to them, or (for a manager) still unattended. Several ride
     * one popup, like the leads nag.
     */
    public function due(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);

        $complaints = Complaint::with('allocatedTo.user:id,name')
            ->where('organization_id', $org->id)
            ->where('status', 'not like', 'closed%')
            ->where(function ($q) use ($me, $manages) {
                $q->where('allocated_to_member_id', $me->id);
                if ($manages) {
                    $q->orWhereNull('allocated_to_member_id');
                }
            })
            ->orderByRaw("case when due_at is null then 1 else 0 end")
            ->orderBy('due_at')
            ->limit(50)
            ->get()
            ->map(fn (Complaint $c) => [
                'uuid' => $c->uuid,
                'cms_no' => $c->cms_no,
                'company_name' => $c->company_name,
                'subject' => $c->subject,
                'priority' => $c->priority,
                'status' => $c->status,
                'due_at' => $c->due_at?->toDateTimeString(),
                'overdue' => $c->isOverdue(),
                'allocated_to' => $c->allocatedTo?->user?->name,
            ]);

        return response()->json([
            'data' => $complaints,
            'alert_minutes' => $org->leadAlertMinutes(),
        ]);
    }

    /**
     * The complaint log: the same records, closed, behind its own right.
     *
     * Its own endpoint rather than a status filter on the list, because a
     * right that only hid a menu entry would be a checkbox that restricts
     * nothing — the API would go on answering the same question through the
     * other door. Now there is a door to be refused at.
     *
     * What it does not do is make closed complaints secret: somebody with the
     * Complaints right can still filter the list to them. This governs the
     * log screen, which is what the sidebar offers.
     */
    public function log(Request $request): JsonResponse
    {
        $request->merge(['status' => 'closed']);

        return $this->index($request);
    }

    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = Complaint::with([
            'client:id,uuid,company_name', 'invoice:id,uuid,number',
            'raisedBy.user:id,name', 'allocatedBy.user:id,name',
            'allocatedTo.user:id,name', 'keyResponsible.user:id,name',
            'errorOwner.user:id,name',
        ])
            ->withCount('replies')
            ->where('organization_id', $org->id)
            ->visibleTo($me);

        $this->applyFilters($request, $query, $org->id);

        // The wall figures are read off the same filtered set, so the cards
        // and the list can never disagree.
        $all = (clone $query)->get([
            'id', 'status', 'due_at', 'complained_on', 'first_response_at',
            'in_progress_at', 'closed_at', 'created_at', 'final_error_type',
            'final_error_member_id', 'subject',
        ]);
        $closedRows = $all->whereNotNull('closed_at');
        $errorNames = Member::with('user:id,name')
            ->whereIn('id', $closedRows->pluck('final_error_member_id')->filter()->unique())
            ->get()
            ->mapWithKeys(fn (Member $m) => [$m->id => $m->user?->name])
            ->all();
        $open = $all->reject(fn (Complaint $c) => $c->isClosed());
        $answered = $all->whereNotNull('first_response_at');

        $summary = [
            'count' => $all->count(),
            'unattended' => $all->where('status', 'unattended')->count(),
            'in_progress' => $all->where('status', 'in_progress')->count(),
            'closed_satisfied' => $all->where('status', 'closed_satisfied')->count(),
            'closed_dissatisfied' => $all->where('status', 'closed_dissatisfied')->count(),
            'overdue' => $open->filter(fn (Complaint $c) => $c->isOverdue())->count(),
            // Hours, because a complaint answered in days is already a story.
            'avg_first_response_hours' => $answered->count() === 0 ? null : round(
                $answered->avg(fn (Complaint $c) => $c->created_at->diffInMinutes($c->first_response_at) / 60), 1
            ),
            'avg_resolution_hours' => $closedRows->count() === 0 ? null : round(
                $closedRows->avg(fn (Complaint $c) => $c->created_at->diffInMinutes($c->closed_at) / 60), 1
            ),
            'by_error_type' => collect(Complaint::ERROR_TYPES)
                ->map(fn ($label, $key) => [
                    'key' => $key,
                    'label' => $label,
                    'count' => $all->where('final_error_type', $key)->count(),
                ])->values(),
            // Whose desk the mistakes trace back to, once they are closed.
            'by_error_member' => $closedRows->whereNotNull('final_error_member_id')
                ->groupBy('final_error_member_id')
                ->map(fn ($g, $memberId) => [
                    'name' => $errorNames[$memberId] ?? '—',
                    'count' => $g->count(),
                ])->sortByDesc('count')->take(10)->values(),
            'by_subject' => $all->whereNotNull('subject')->groupBy('subject')
                ->map(fn ($g, $subject) => ['subject' => $subject, 'count' => $g->count()])
                ->sortByDesc('count')->take(8)->values(),
        ];

        $complaints = $query->orderByRaw("case when status like 'closed%' then 1 else 0 end")
            ->orderByDesc('id')
            ->paginate(25);
        $complaints->getCollection()->transform(fn (Complaint $c) => $this->serialize($c));

        return response()->json(['summary' => $summary] + $complaints->toArray());
    }

    /**
     * Everything the search bar and the form need: the company's own word
     * lists, the fixed vocabularies, and the people a complaint can be put
     * on — each with how many they are carrying.
     */
    public function options(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $load = fn (string $column) => Complaint::where('organization_id', $org->id)
            ->visibleTo($me)
            ->selectRaw($column . ' as member_id, count(*) as total')
            ->whereNotNull($column)
            ->groupBy($column)
            ->pluck('total', 'member_id');

        $raisedCounts = $load('raised_by_member_id');
        $allocatedCounts = $load('allocated_to_member_id');

        $members = Member::visible()->with('user:id,name')
            ->where('organization_id', $org->id)
            ->where('status', 'active')
            ->get()
            ->map(fn (Member $m) => [
                'uuid' => $m->uuid,
                'name' => $m->user?->name,
                'raised' => (int) ($raisedCounts[$m->id] ?? 0),
                'allocated' => (int) ($allocatedCounts[$m->id] ?? 0),
                'is_me' => $m->id === $me->id,
            ])
            ->sortBy('name')->values();

        return response()->json(['data' => [
            'sources' => $org->optionList('complaint_sources'),
            'subjects' => $org->optionList('complaint_subjects'),
            'types' => $org->optionList('complaint_types'),
            'modes' => $org->optionList('complaint_modes'),
            'statuses' => Complaint::STATUSES,
            'error_types' => Complaint::ERROR_TYPES,
            'priorities' => Complaint::PRIORITIES,
            'resolve_hours' => $org->complaintHours(),
            'members' => $members,
            // Only a manager may hand a complaint to somebody else.
            'can_allocate' => $this->manages($me),
        ]]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $complaint = $this->find($request, $uuid);

        return response()->json(['data' => $this->serialize($complaint, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $this->validated($request, $org->id, $me);
        // Absent a time of its own, the promise is the company's standing one.
        $due = $data['due_at'] ?? null;
        unset($data['due_at']);

        $complaint = Complaint::create($data + [
            'organization_id' => $org->id,
            'cms_no' => Complaint::nextNumber($org->id),
            'status' => 'unattended',
            'raised_by_member_id' => $me->id,
            'due_at' => $due ?: now()->addHours($org->complaintHours()),
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record($me, $org->id, 'complaint.raised', $complaint, [
            'cms_no' => $complaint->cms_no,
            'company' => $complaint->company_name,
            'subject' => $complaint->subject,
        ]);
        $this->notifyOwner($complaint, $me, 'A complaint was logged for you: ' . $complaint->cms_no
            . ' — ' . $complaint->subject);

        return response()->json([
            'message' => 'Complaint ' . $complaint->cms_no . ' logged.',
            'data' => $this->serialize($complaint->fresh(), true),
        ], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $complaint = $this->find($request, $uuid);
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $complaint->update($this->validated($request, $complaint->organization_id, $me));

        ActivityLog::record($me, $complaint->organization_id, 'complaint.updated', $complaint, [
            'cms_no' => $complaint->cms_no,
        ]);

        return response()->json([
            'message' => 'Complaint updated.',
            'data' => $this->serialize($complaint->fresh(), true),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $complaint = $this->find($request, $uuid);

        foreach ($complaint->documents as $doc) {
            Storage::disk('local')->delete($doc->path);
            $doc->delete();
        }
        ActivityLog::record($request->attributes->get('crm_member'), $complaint->organization_id, 'complaint.deleted', $complaint, [
            'cms_no' => $complaint->cms_no,
        ]);
        $complaint->delete();

        return response()->json(['message' => 'Complaint removed.']);
    }

    // ---- Whose desk it is on -------------------------------------------------

    /**
     * Put the complaint on somebody's desk, and name who answers for it.
     * Allocation is a manager's act — it is how work gets shared out.
     */
    public function allocate(Request $request, string $uuid): JsonResponse
    {
        $complaint = $this->find($request, $uuid);
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        abort_unless($this->manages($me), 403, 'Allocating a complaint belongs to an Admin or Subadmin.');

        $data = $request->validate([
            'allocated_to' => ['required', 'string'],
            'key_responsible' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(array_keys(Complaint::PRIORITIES))],
            'due_at' => ['nullable', 'date'],
        ]);

        $to = $this->member($complaint->organization_id, $data['allocated_to']);
        $key = isset($data['key_responsible']) && $data['key_responsible'] !== ''
            ? $this->member($complaint->organization_id, $data['key_responsible'])
            : null;

        $complaint->update(array_filter([
            'allocated_to_member_id' => $to->id,
            'allocated_by_member_id' => $me->id,
            'key_responsible_member_id' => $key?->id,
            'priority' => $data['priority'] ?? null,
            'due_at' => $data['due_at'] ?? null,
        ], fn ($v) => $v !== null));

        ActivityLog::record($me, $complaint->organization_id, 'complaint.allocated', $complaint, [
            'cms_no' => $complaint->cms_no,
            'to' => $to->user?->name,
        ]);
        $to->user?->notify(new CrmNotification(
            'crm_complaint',
            $complaint->cms_no . ' (' . $complaint->company_name . ') is now yours to answer.',
            '/crm/complaints/' . $complaint->uuid,
        ));

        return response()->json([
            'message' => 'Complaint given to ' . ($to->user?->name ?? 'them') . '.',
            'data' => $this->serialize($complaint->fresh(), true),
        ]);
    }

    /**
     * Move the complaint along. Starting work stamps the clock; closing it
     * demands a resolution and an answer to "whose mistake was it?".
     */
    public function status(Request $request, string $uuid): JsonResponse
    {
        $complaint = $this->find($request, $uuid);
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Complaint::STATUSES))],
            'resolution' => ['nullable', 'string', 'max:4000'],
            'final_error_type' => ['nullable', Rule::in(array_keys(Complaint::ERROR_TYPES))],
            'final_error_member' => ['nullable', 'string'],
            'final_error_note' => ['nullable', 'string', 'max:512'],
        ]);

        $closing = str_starts_with($data['status'], 'closed');

        if ($closing) {
            if (($data['resolution'] ?? '') === '') {
                abort(422, 'Say what was actually done before closing the complaint.');
            }
            if (($data['final_error_type'] ?? '') === '') {
                abort(422, 'A closed complaint must record whose error it was.');
            }
            // An executive error is a person, not a category — the whole
            // point of asking is to know who it was.
            if ($data['final_error_type'] === 'executive' && ($data['final_error_member'] ?? '') === '') {
                abort(422, 'An executive error must name the executive.');
            }
        }

        $owner = ($data['final_error_member'] ?? '') !== ''
            ? $this->member($complaint->organization_id, $data['final_error_member'])
            : null;

        $complaint->update([
            'status' => $data['status'],
            'resolution' => $closing ? $data['resolution'] : $complaint->resolution,
            'final_error_type' => $closing ? $data['final_error_type'] : $complaint->final_error_type,
            'final_error_member_id' => $closing ? $owner?->id : $complaint->final_error_member_id,
            'final_error_note' => $closing ? ($data['final_error_note'] ?? null) : $complaint->final_error_note,
            'in_progress_at' => $data['status'] === 'in_progress'
                ? ($complaint->in_progress_at ?? now())
                : $complaint->in_progress_at,
            'closed_at' => $closing ? now() : null,
            'closed_by' => $closing ? $request->user()->id : null,
        ]);

        ActivityLog::record($me, $complaint->organization_id, 'complaint.' . ($closing ? 'closed' : 'reopened'), $complaint, array_filter([
            'cms_no' => $complaint->cms_no,
            'status' => Complaint::STATUSES[$data['status']],
            'error' => $closing ? Complaint::ERROR_TYPES[$data['final_error_type']] : null,
        ]));

        // Whoever raised it hears how it ended; so does the person blamed.
        if ($closing) {
            $line = $complaint->cms_no . ' closed — ' . Complaint::STATUSES[$data['status']] . '.';
            $this->notify($complaint->raisedBy, $me, $line, $complaint);
            if ($owner && $owner->id !== $me->id) {
                $this->notify($owner, $me, $complaint->cms_no . ' was closed as '
                    . Complaint::ERROR_TYPES[$data['final_error_type']] . ' against you.', $complaint);
            }
        }

        return response()->json([
            'message' => $closing ? 'Complaint closed.' : 'Complaint reopened.',
            'data' => $this->serialize($complaint->fresh(), true),
        ]);
    }

    // ---- The two conversations ----------------------------------------------

    /**
     * Add a turn to one of the threads. A word to the client is also the
     * moment the clock on "did anyone answer?" stops, and it drags an
     * untouched complaint into progress on its own.
     */
    public function reply(Request $request, string $uuid): JsonResponse
    {
        $complaint = $this->find($request, $uuid);
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $data = $request->validate([
            'audience' => ['required', Rule::in(ComplaintReply::AUDIENCES)],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $reply = $complaint->replies()->create([
            'member_id' => $me->id,
            'audience' => $data['audience'],
            'body' => trim($data['body']),
        ]);

        $changes = [];
        if ($data['audience'] === 'client' && $complaint->first_response_at === null) {
            $changes['first_response_at'] = now();
        }
        if ($complaint->status === 'unattended') {
            $changes['status'] = 'in_progress';
            $changes['in_progress_at'] = $complaint->in_progress_at ?? now();
        }
        if ($changes !== []) {
            $complaint->update($changes);
        }

        ActivityLog::record($me, $complaint->organization_id, 'complaint.replied', $complaint, [
            'cms_no' => $complaint->cms_no,
            'audience' => $data['audience'],
        ]);

        // The other desk hears about it — the client thread and the office
        // thread reach the same people, only the wording differs.
        $line = $data['audience'] === 'client'
            ? $complaint->cms_no . ': the client was answered.'
            : $complaint->cms_no . ': a note was added inside the office.';
        foreach ([$complaint->allocatedTo, $complaint->raisedBy, $complaint->keyResponsible] as $who) {
            $this->notify($who, $me, $line, $complaint);
        }

        return response()->json([
            'message' => $data['audience'] === 'client' ? 'Reply recorded.' : 'Internal note added.',
            'data' => $this->serialize($complaint->fresh(), true),
            'reply_uuid' => $reply->uuid,
        ], 201);
    }

    /** Take back something said in the wrong thread. */
    public function deleteReply(Request $request, string $uuid, string $replyUuid): JsonResponse
    {
        $complaint = $this->find($request, $uuid);
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $reply = $complaint->replies()->where('uuid', $replyUuid)->firstOrFail();
        abort_unless($reply->member_id === $me->id || $this->manages($me), 403,
            'Only the person who wrote it, or a manager, can remove it.');
        $reply->delete();

        return response()->json([
            'message' => 'Removed.',
            'data' => $this->serialize($complaint->fresh(), true),
        ]);
    }

    // ---- Evidence ------------------------------------------------------------

    public function uploadFile(Request $request, string $uuid): JsonResponse
    {
        $complaint = $this->find($request, $uuid);
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $file = $request->file('file');
        $path = $file->store('crm-documents/' . $complaint->organization_id . '/complaints/' . $complaint->id, 'local');

        $document = Document::create([
            'organization_id' => $complaint->organization_id,
            'documentable_type' => Complaint::class,
            'documentable_id' => $complaint->id,
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Attached.', 'data' => $document->only(['uuid', 'name', 'size'])], 201);
    }

    public function downloadFile(Request $request, string $uuid, string $documentUuid): StreamedResponse
    {
        $complaint = $this->find($request, $uuid);
        $document = $complaint->documents()->where('uuid', $documentUuid)->firstOrFail();

        return Storage::disk('local')->download($document->path, $document->name);
    }

    public function deleteFile(Request $request, string $uuid, string $documentUuid): JsonResponse
    {
        $complaint = $this->find($request, $uuid);
        $document = $complaint->documents()->where('uuid', $documentUuid)->firstOrFail();

        Storage::disk('local')->delete($document->path);
        $document->delete();

        return response()->json(['message' => 'Attachment removed.']);
    }

    // ---- Helpers -------------------------------------------------------------

    private function manages(Member $me): bool
    {
        return in_array($me->crm_role, ['admin', 'subadmin'], true);
    }

    private function find(Request $request, string $uuid): Complaint
    {
        return Complaint::where('organization_id', $request->attributes->get('crm_org')->id)
            ->visibleTo($request->attributes->get('crm_member'))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function member(int $orgId, string $uuid): Member
    {
        return Member::where('organization_id', $orgId)->where('uuid', $uuid)->firstOrFail();
    }

    private function notify(?Member $who, Member $me, string $line, Complaint $complaint): void
    {
        // Nobody is told about their own work.
        if (! $who || ! $who->user || $who->id === $me->id) {
            return;
        }
        $who->user->notify(new CrmNotification('crm_complaint', $line, '/crm/complaints/' . $complaint->uuid));
    }

    private function notifyOwner(Complaint $complaint, Member $me, string $line): void
    {
        $this->notify($complaint->allocatedTo, $me, $line, $complaint);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<Complaint>  $query */
    private function applyFilters(Request $request, $query, int $orgId): void
    {
        // Dates: when it came in, and when work started on it.
        if ($from = $request->query('date_from')) {
            $query->whereDate('complained_on', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('complained_on', '<=', $to);
        }
        if ($from = $request->query('closed_from')) {
            $query->whereDate('closed_at', '>=', $from);
        }
        if ($to = $request->query('closed_to')) {
            $query->whereDate('closed_at', '<=', $to);
        }
        if ($from = $request->query('progress_from')) {
            $query->whereDate('in_progress_at', '>=', $from);
        }
        if ($to = $request->query('progress_to')) {
            $query->whereDate('in_progress_at', '<=', $to);
        }

        // People, each named by their member uuid.
        foreach ([
            'user' => 'raised_by_member_id',
            'allocated_by' => 'allocated_by_member_id',
            'allocated_to' => 'allocated_to_member_id',
            'key_responsible' => 'key_responsible_member_id',
            'error_member' => 'final_error_member_id',
        ] as $param => $column) {
            if ($uuid = $request->query($param)) {
                $member = Member::where('organization_id', $orgId)->where('uuid', $uuid)->first();
                $query->where($column, $member?->id ?? 0);
            }
        }

        // The company's own words.
        foreach (['source', 'subject', 'mode'] as $param) {
            if ($value = $request->query($param)) {
                $query->where($param, $value);
            }
        }
        if ($type = $request->query('complaint_type')) {
            $query->where('complaint_type', $type);
        }
        if ($status = $request->query('status')) {
            // "Overdue" is a reading of the clock, not a stored state.
            if ($status === 'overdue') {
                $query->where('status', 'not like', 'closed%')
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', now());
            } elseif ($status === 'open') {
                $query->where('status', 'not like', 'closed%');
            } elseif ($status === 'closed') {
                // The log: everything settled, however it ended.
                $query->where('status', 'like', 'closed%');
            } else {
                $query->where('status', $status);
            }
        }
        if ($error = $request->query('final_error_type')) {
            $query->where('final_error_type', $error);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }

        // The contact block, each field on its own, as the old screen had it.
        foreach ([
            'company' => 'company_name',
            'contact_person' => 'contact_person',
            'mobile' => 'mobile',
            'phone' => 'phone',
            'email' => 'email',
            'alt_contact_person' => 'alt_contact_person',
            'alt_mobile' => 'alt_mobile',
            'alt_phone' => 'alt_phone',
            'alt_email' => 'alt_email',
            'cms_no' => 'cms_no',
        ] as $param => $column) {
            if ($value = trim((string) $request->query($param))) {
                $query->where($column, 'like', "%{$value}%");
            }
        }
        if ($invoice = trim((string) $request->query('invoice_no'))) {
            $query->whereHas('invoice', fn ($q) => $q->where('number', 'like', "%{$invoice}%"));
        }

        // One box that looks everywhere the eye would.
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q
                ->where('cms_no', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('contact_person', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")
                ->orWhere('details', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $orgId, Member $me): array
    {
        $data = $request->validate([
            'complained_on' => ['required', 'date'],
            'client_uuid' => ['nullable', 'string'],
            'company_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'alt_contact_person' => ['nullable', 'string', 'max:255'],
            'alt_mobile' => ['nullable', 'string', 'max:64'],
            'alt_phone' => ['nullable', 'string', 'max:64'],
            'alt_email' => ['nullable', 'email', 'max:255'],
            'invoice_uuid' => ['nullable', 'string'],
            'source' => ['nullable', 'string', 'max:96'],
            // The subject is what the complaint IS — it sits above the
            // description and is chosen from the company's own list.
            'subject' => ['required', 'string', 'max:191'],
            'complaint_type' => ['nullable', 'string', 'max:96'],
            'mode' => ['nullable', 'string', 'max:64'],
            'details' => ['nullable', 'string', 'max:8000'],
            'priority' => ['nullable', Rule::in(array_keys(Complaint::PRIORITIES))],
            'due_at' => ['nullable', 'date'],
            'allocated_to' => ['nullable', 'string'],
            'key_responsible' => ['nullable', 'string'],
        ]);

        // Filed under a word the company does not use, the reports stop
        // meaning anything — so the list is the list.
        $subjects = $request->attributes->get('crm_org')->optionList('complaint_subjects');
        if ($subjects !== [] && ! in_array($data['subject'], $subjects, true)) {
            abort(422, 'Pick a subject from the list. To add “' . $data['subject']
                . '” to it, ask your Company Admin — the list is theirs to keep.');
        }

        $data['company_name'] = TextCase::company($data['company_name']);
        if (array_key_exists('contact_person', $data)) {
            $data['contact_person'] = TextCase::name($data['contact_person']);
        }
        if (array_key_exists('email', $data)) {
            $data['email'] = TextCase::email($data['email']);
        }

        // A complaint about a registered client carries the link, so their
        // history is one click away.
        $data['client_id'] = null;
        if (($data['client_uuid'] ?? '') !== '') {
            $client = Client::where('organization_id', $orgId)->where('uuid', $data['client_uuid'])->firstOrFail();
            $data['client_id'] = $client->id;
            $data['company_name'] = $client->company_name;
        }
        unset($data['client_uuid']);

        $data['invoice_id'] = null;
        if (($data['invoice_uuid'] ?? '') !== '') {
            $data['invoice_id'] = Invoice::where('organization_id', $orgId)
                ->where('uuid', $data['invoice_uuid'])->firstOrFail()->id;
        }
        unset($data['invoice_uuid']);

        // Handing it to somebody is a manager's act, here as in allocate().
        $data['allocated_to_member_id'] = null;
        if (($data['allocated_to'] ?? '') !== '' && $this->manages($me)) {
            $data['allocated_to_member_id'] = $this->member($orgId, $data['allocated_to'])->id;
            $data['allocated_by_member_id'] = $me->id;
        }
        unset($data['allocated_to']);

        $data['key_responsible_member_id'] = ($data['key_responsible'] ?? '') !== ''
            ? $this->member($orgId, $data['key_responsible'])->id
            : null;
        unset($data['key_responsible']);

        return $data;
    }

    /** @return array<string, mixed> */
    private function serialize(Complaint $c, bool $full = false): array
    {
        $row = [
            'uuid' => $c->uuid,
            'cms_no' => $c->cms_no,
            'complained_on' => $c->complained_on->toDateString(),
            'client_uuid' => $c->client?->uuid,
            'company_name' => $c->company_name,
            'contact_person' => $c->contact_person,
            'mobile' => $c->mobile,
            'phone' => $c->phone,
            'email' => $c->email,
            'alt_contact_person' => $c->alt_contact_person,
            'alt_mobile' => $c->alt_mobile,
            'alt_phone' => $c->alt_phone,
            'alt_email' => $c->alt_email,
            'invoice_uuid' => $c->invoice?->uuid,
            'invoice_no' => $c->invoice?->number,
            'source' => $c->source,
            'subject' => $c->subject,
            'complaint_type' => $c->complaint_type,
            'mode' => $c->mode,
            'details' => $c->details,
            'status' => $c->status,
            'status_label' => Complaint::STATUSES[$c->status] ?? $c->status,
            'priority' => $c->priority,
            'due_at' => $c->due_at?->toDateTimeString(),
            'overdue' => $c->isOverdue(),
            'in_progress_at' => $c->in_progress_at?->toDateTimeString(),
            'first_response_at' => $c->first_response_at?->toDateTimeString(),
            'closed_at' => $c->closed_at?->toDateTimeString(),
            'closed_by' => $c->closer?->name,
            'resolution' => $c->resolution,
            'final_error_type' => $c->final_error_type,
            'final_error_label' => $c->final_error_type
                ? (Complaint::ERROR_TYPES[$c->final_error_type] ?? $c->final_error_type) : null,
            'final_error_member' => $c->errorOwner?->user?->name,
            'final_error_member_uuid' => $c->errorOwner?->uuid,
            'final_error_note' => $c->final_error_note,
            'raised_by' => $c->raisedBy?->user?->name,
            'raised_by_uuid' => $c->raisedBy?->uuid,
            'allocated_by' => $c->allocatedBy?->user?->name,
            'allocated_to' => $c->allocatedTo?->user?->name,
            'allocated_to_uuid' => $c->allocatedTo?->uuid,
            'key_responsible' => $c->keyResponsible?->user?->name,
            'key_responsible_uuid' => $c->keyResponsible?->uuid,
            'replies_count' => $c->replies_count ?? $c->replies()->count(),
            'created_at' => $c->created_at?->toDateTimeString(),
        ];

        if (! $full) {
            return $row;
        }

        return $row + [
            'replies' => $c->replies()->with('member.user:id,name')->orderBy('id')->get()
                ->map(fn (ComplaintReply $r) => [
                    'uuid' => $r->uuid,
                    'audience' => $r->audience,
                    'body' => $r->body,
                    'author' => $r->member?->user?->name,
                    'author_uuid' => $r->member?->uuid,
                    'created_at' => $r->created_at?->toDateTimeString(),
                ]),
            'documents' => $c->documents()->get()
                ->map(fn (Document $d) => ['uuid' => $d->uuid, 'name' => $d->name, 'size' => $d->size]),
        ];
    }
}
