<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\Invoice;
use App\Models\Crm\Member;
use App\Models\Crm\Task;
use App\Models\Crm\TaskComment;
use App\Notifications\CrmNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/**
 * Tasks, and the thing tasks are actually used for.
 *
 * The approval loop is unchanged: a manager assigns, the assignee works and
 * submits, the manager approves (done) or rejects (reopened). What is new is
 * everything around it - a task can name the invoice or proforma it is about,
 * carry the window it is expected to run in, be talked about in its own
 * thread, and ask to be put in front of whoever owes the next word.
 *
 * A pendency is the same object with a different word on it: accounts saying
 * to a salesperson "this is waiting on you". It is not a second module,
 * because it is not a second loop.
 */
class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request)->with([
            'assignee.user:id,name', 'assigner.user:id,name', 'editor.user:id,name',
            'invoice:id,uuid,number,kind,client_id', 'invoice.client:id,company_name',
        ])->withCount('comments');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }
        if ($member = $request->query('member')) {
            $query->whereHas('assignee', fn ($m) => $m->where('uuid', $member));
        }
        // "Mine": the two sides of a task, not one. Somebody chasing a
        // pendency needs their own outbox as much as their inbox.
        if ($request->boolean('mine')) {
            /** @var Member $me */
            $me = $request->attributes->get('crm_member');
            $query->where(fn ($q) => $q->where('assigned_member_id', $me->id)->orWhere('assigned_by', $me->id));
        }
        if ($invoice = $request->query('invoice')) {
            $query->whereHas('invoice', fn ($i) => $i->where('uuid', $invoice));
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhereHas('invoice', fn ($i) => $i->where('number', 'like', "%{$search}%")));
        }

        $all = (clone $query)->get(['id', 'status', 'assigned_member_id', 'due_at', 'priority', 'kind']);
        $summary = [
            'by_status' => collect(Task::STATUSES)
                ->map(fn ($s) => ['status' => $s, 'count' => $all->where('status', $s)->count()])
                ->filter(fn ($s) => $s['count'] > 0)->values(),
            'overdue' => $all->whereNotIn('status', ['done'])
                ->filter(fn ($t) => $t->due_at !== null && $t->due_at->isPast())->count(),
            'awaiting_review' => $all->where('status', 'submitted')->count(),
            'pendencies' => $all->where('kind', 'pendency')->whereNotIn('status', ['done'])->count(),
        ];

        $tasks = $query->orderByRaw("case status when 'submitted' then 0 when 'reopened' then 1 when 'in_progress' then 2 when 'open' then 3 else 4 end")
            ->orderByDesc('id')
            ->paginate(25);
        $tasks->getCollection()->transform(fn ($t) => $this->serialize($t, $request));

        return response()->json(['summary' => $summary] + $tasks->toArray());
    }

    /** One task, with everything said about it. */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $task = $this->scoped($request)->with([
            'assignee.user:id,name', 'assigner.user:id,name', 'editor.user:id,name', 'reviewer.user:id,name',
            'invoice:id,uuid,number,kind,client_id,total,invoice_date', 'invoice.client:id,company_name,contact_person,email,mobile',
            'comments.member.user:id,name',
        ])->withCount('comments')->where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'data' => $this->serialize($task, $request) + [
                'comments' => $task->comments->sortBy('id')->map(fn (TaskComment $c) => [
                    'uuid' => $c->uuid,
                    'body' => $c->body,
                    'by' => $c->member?->user?->name ?? '-',
                    'by_uuid' => $c->member?->uuid,
                    'at' => $c->created_at?->toDateTimeString(),
                ])->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $data = $this->validateTask($request, $org->id);

        $task = Task::create($data + [
            'organization_id' => $org->id,
            'assigned_by' => $me->id,
            // Issued means it is the assignee's move, and the first reminder
            // is now unless a later date was asked for.
            'awaiting' => 'assignee',
            'remind_at' => $data['remind_at'] ?? now(),
        ]);

        $task->load(['assignee.user:id,name', 'assigner.user:id,name', 'invoice.client:id,company_name']);

        $this->tell($task, $task->assignee, ($me->user?->name ?? 'Someone')
            . ($task->kind === 'pendency' ? ' raised a pendency with you: "' : ' assigned you "')
            . $task->title . '".');

        ActivityLog::record($me, $org->id, 'task.assigned', $task, array_filter([
            'title' => $task->title,
            'kind' => $task->kind,
            'to' => $task->assignee?->user?->name,
            'due' => $task->due_at?->toDateTimeString(),
            'invoice' => $task->invoice?->number,
        ]));

        return response()->json([
            'message' => $task->kind === 'pendency' ? 'Pendency raised.' : 'Task assigned.',
            'data' => $this->serialize($task, $request),
        ], 201);
    }

    /**
     * The assigner changes their mind.
     *
     * Said out loud on the task itself: somebody who was given work on
     * Monday and finds it different on Wednesday should be able to see that
     * it changed, who changed it and when - not wonder whether they read it
     * wrong the first time.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $task = $this->scoped($request)->where('uuid', $uuid)->firstOrFail();

        if ($task->status === 'done') {
            abort(422, 'A finished task cannot be edited.');
        }

        $data = $this->validateTask($request, $org->id);
        $before = $task->only(array_keys($data));

        $task->update($data + ['edited_at' => now(), 'edited_by' => $me->id]);
        $task->refresh()->load(['assignee.user:id,name', 'assigner.user:id,name', 'editor.user:id,name', 'invoice.client:id,company_name']);

        $after = $task->only(array_keys($data));
        $changed = array_keys(array_filter(
            $after,
            fn ($value, $key) => (string) $value !== (string) ($before[$key] ?? ''),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed !== [] && $task->assigned_member_id !== $me->id) {
            $this->tell($task, $task->assignee, ($me->user?->name ?? 'Someone')
                . ' changed "' . $task->title . '".');
        }

        ActivityLog::record($me, $org->id, 'task.updated', $task, array_filter([
            'title' => $task->title,
            'changed' => $changed,
        ]));

        return response()->json([
            'message' => 'Task updated.',
            'data' => $this->serialize($task, $request),
        ]);
    }

    /** The assignee moves their own task forward. */
    public function progress(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $task = $this->scoped($request)->where('uuid', $uuid)->firstOrFail();

        if ($task->assigned_member_id !== $me->id) {
            abort(403, 'Only the assignee can update progress.');
        }
        if (in_array($task->status, ['done', 'submitted'], true)) {
            abort(422, 'This task is ' . $task->status . ' - nothing to progress.');
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(['in_progress', 'submitted'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $task->update([
            'status' => $data['status'],
            'progress_note' => $data['note'] ?? $task->progress_note,
            'submitted_at' => $data['status'] === 'submitted' ? now() : $task->submitted_at,
        ]);

        // Moving it is answering it: the ball, and the reminder, go back.
        $this->handBack($task, 'assigner');

        if ($data['status'] === 'submitted') {
            Notification::send(
                Member::deciders($task->organization_id, 'tasks', $me->id),
                new CrmNotification(
                    'crm_task',
                    ($me->user?->name ?? 'Someone') . ' submitted "' . $task->title . '" for approval.',
                    '/crm/tasks?status=submitted',
                ),
            );
        }

        ActivityLog::record($me, $org->id, 'task.progress', $task, array_filter([
            'title' => $task->title,
            'status' => $data['status'],
            'note' => $data['note'] ?? null,
        ]));

        return response()->json([
            'message' => $data['status'] === 'submitted' ? 'Submitted for approval.' : 'Marked in progress.',
            'data' => $this->serialize(
                $task->fresh()->load(['assignee.user:id,name', 'assigner.user:id,name', 'invoice.client:id,company_name']),
                $request,
            ),
        ]);
    }

    /**
     * A word on the task, from either side.
     *
     * Whoever writes has answered; the ball - and with it the reminder -
     * passes to the other. That is the whole of what makes a pendency work:
     * accounts ask, the salesperson answers, accounts are reminded to look
     * at the answer, and nobody has to remember to chase.
     */
    public function comment(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $task = $this->scoped($request)->with(['assignee.user:id,name', 'assigner.user:id,name'])
            ->where('uuid', $uuid)->firstOrFail();

        $isAssignee = $task->assigned_member_id === $me->id;
        $isAssigner = $task->assigned_by === $me->id;
        $manages = in_array($me->crm_role, ['admin', 'subadmin'], true);

        abort_unless($isAssignee || $isAssigner || $manages, 403,
            'Only the two people on a task can talk on it.');

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $comment = $task->comments()->create([
            'organization_id' => $org->id,
            'member_id' => $me->id,
            'body' => trim($data['body']),
        ]);

        // The other side owes the next word. A manager stepping in leaves the
        // turn where it was rather than taking it from either of them.
        $them = $isAssignee ? $task->assigner : $task->assignee;
        if ($isAssignee || $isAssigner) {
            $this->handBack($task, $isAssignee ? 'assigner' : 'assignee');
        }

        $this->tell($task, $them, ($me->user?->name ?? 'Someone')
            . ' replied on "' . $task->title . '": ' . str($comment->body)->limit(80));

        ActivityLog::record($me, $org->id, 'task.comment', $task, [
            'title' => $task->title,
            'note' => str($comment->body)->limit(200)->toString(),
        ]);

        return response()->json([
            'message' => 'Posted.',
            'data' => [
                'uuid' => $comment->uuid,
                'body' => $comment->body,
                'by' => $me->user?->name ?? '-',
                'by_uuid' => $me->uuid,
                'at' => $comment->created_at?->toDateTimeString(),
            ],
        ], 201);
    }

    /**
     * "Not today."
     *
     * Put off by whoever is being reminded, and for them alone - the other
     * side's reminder is not this person's to silence. A day by default,
     * because that is what the button says.
     */
    public function snooze(Request $request, string $uuid): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $task = $this->scoped($request)->where('uuid', $uuid)->firstOrFail();

        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'until' => ['nullable', 'date'],
        ]);

        /*
         * How long it goes quiet for.
         *
         * A day when somebody presses the button, because the button says
         * tomorrow. But a task that named its own rhythm - every 7 days,
         * every 30 - keeps that rhythm when it is dismissed without one:
         * work that is due in three months should ask about itself monthly,
         * not every morning between now and then.
         */
        $until = isset($data['until'])
            ? Carbon::parse($data['until'])
            : (isset($data['hours'])
                ? now()->addHours($data['hours'])
                : now()->addDays($task->remind_every_days ?: 1));

        $mine = false;
        if ($task->assigned_member_id === $me->id) {
            $task->assignee_snoozed_until = $until;
            $mine = true;
        }
        if ($task->assigned_by === $me->id) {
            $task->assigner_snoozed_until = $until;
            $mine = true;
        }

        abort_unless($mine, 403, 'This reminder is not yours to put off.');
        $task->save();

        return response()->json([
            'message' => 'Reminder put off until ' . $until->format('d M Y, H:i') . '.',
            'data' => ['until' => $until->toDateTimeString()],
        ]);
    }

    /**
     * The tasks asking to be seen right now, for the person asking.
     *
     * The popup reads this. It is deliberately not the task list: a list is
     * something you go to, and the whole point of a pendency is that it
     * comes to you.
     */
    public function reminders(Request $request): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $tasks = Task::with(['assignee.user:id,name', 'assigner.user:id,name', 'invoice:id,uuid,number,kind'])
            ->where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('status', '!=', 'done')
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', now())
            ->where(fn ($q) => $q->where('assigned_member_id', $me->id)->orWhere('assigned_by', $me->id))
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('remind_at')
            ->get()
            ->filter(fn (Task $t) => $t->remindsNow($me))
            ->values();

        return response()->json([
            'data' => $tasks->map(fn (Task $t) => [
                'uuid' => $t->uuid,
                'title' => $t->title,
                'kind' => $t->kind,
                'priority' => $t->priority,
                'status' => $t->status,
                'due_at' => $t->due_at?->toDateTimeString(),
                'overdue' => $t->due_at !== null && $t->due_at->isPast(),
                // Which side of it this person is on, so the popup can say
                // "waiting on you" or "they have answered".
                'my_turn' => $t->awaiting === 'assignee' && $t->assigned_member_id === $me->id ? 'do' : 'review',
                'other_party' => $t->awaiting === 'assignee'
                    ? $t->assigner?->user?->name
                    : $t->assignee?->user?->name,
                'invoice' => $t->invoice
                    ? ['uuid' => $t->invoice->uuid, 'number' => $t->invoice->number, 'kind' => $t->invoice->kind]
                    : null,
            ]),
        ]);
    }

    /** The manager's verdict on a submitted task. */
    public function review(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $task = $this->scoped($request)->with('assignee.user:id,name')->where('uuid', $uuid)->firstOrFail();

        if ($task->status !== 'submitted') {
            abort(422, 'Only a submitted task can be reviewed.');
        }

        $data = $request->validate([
            'verdict' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $approved = $data['verdict'] === 'approve';

        $task->update([
            'status' => $approved ? 'done' : 'reopened',
            'reviewed_by' => $me->id,
            'reviewed_at' => now(),
            'review_note' => $data['note'] ?? null,
        ]);

        // Finished work stops asking; sent-back work is the assignee's again.
        if ($approved) {
            $task->update(['remind_at' => null, 'awaiting' => null]);
        } else {
            $this->handBack($task, 'assignee');
        }

        $this->tell($task, $task->assignee, '"' . $task->title . '" was '
            . ($approved ? 'approved' : 'sent back')
            . ' by ' . ($me->user?->name ?? 'a manager')
            . (($data['note'] ?? null) ? ' - "' . $data['note'] . '"' : '') . '.');

        ActivityLog::record($me, $org->id, 'task.reviewed', $task, array_filter([
            'title' => $task->title,
            'status' => $task->status,
            'note' => $data['note'] ?? null,
        ]));

        return response()->json([
            'message' => $approved ? 'Task approved and closed.' : 'Task sent back.',
            'data' => $this->serialize(
                $task->fresh()->load(['assignee.user:id,name', 'assigner.user:id,name', 'invoice.client:id,company_name']),
                $request,
            ),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $task = $this->scoped($request)->with('assignee.user:id,name')->where('uuid', $uuid)->firstOrFail();

        ActivityLog::record($me, $org->id, 'task.deleted', $task, array_filter([
            'title' => $task->title,
            'to' => $task->assignee?->user?->name,
        ]));

        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
    }

    /**
     * Find the document a task is about.
     *
     * By anything somebody would have in front of them: the number, the
     * company, who they spoke to, the address they wrote from, the number
     * they rang. Whoever is raising the pendency has one of those, and
     * asking them to know which one the search wants is asking them to do
     * the computer's job.
     */
    public function documents(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $search = trim((string) $request->query('search'));
        if (mb_strlen($search) < 2) {
            return response()->json(['data' => []]);
        }

        $query = Invoice::with(['client:id,company_name,contact_person,email,mobile', 'issuingCompany:id,name', 'member.user:id,name'])
            ->where('organization_id', $org->id)
            ->visibleTo($me)
            ->where(fn ($q) => $q->where('number', 'like', "%{$search}%")
                ->orWhereHas('client', fn ($c) => $c->where('company_name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")));

        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }

        return response()->json([
            'data' => $query->orderByDesc('id')->limit(20)->get()->map(fn (Invoice $i) => [
                'uuid' => $i->uuid,
                'number' => $i->number,
                'kind' => $i->kind,
                'date' => $i->invoice_date?->toDateString(),
                'total' => $i->total,
                'client' => $i->client?->company_name,
                'contact_person' => $i->client?->contact_person,
                'email' => $i->client?->email,
                'mobile' => $i->client?->mobile,
                'issuing_company' => $i->issuingCompany?->name,
                'salesperson' => $i->member?->user?->name,
            ]),
        ]);
    }

    // ---- Helpers -----------------------------------------------------------

    /**
     * Pass the turn, and set the next reminder from it.
     *
     * Timed from the answer rather than from the original date, so a task
     * answered today is not asked about again tomorrow simply because it was
     * raised a fortnight ago. The snooze on the side now being asked is
     * cleared: they put off the old question, not this one.
     */
    private function handBack(Task $task, string $side): void
    {
        $task->forceFill([
            'awaiting' => $side,
            'last_reply_at' => now(),
            // A task that never asked to be remembered still does not.
            'remind_at' => $task->remind_at === null ? null : now(),
            $side === 'assignee' ? 'assignee_snoozed_until' : 'assigner_snoozed_until' => null,
        ])->save();
    }

    /** One line to a person, if there is a person. */
    private function tell(Task $task, ?Member $whom, string $line): void
    {
        $user = $whom?->user;
        if (! $user) {
            return;
        }

        $user->notify(new CrmNotification('crm_task', $line, '/crm/tasks?task=' . $task->uuid));
    }

    private function scoped(Request $request): Builder
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        $query = Task::where('organization_id', $org->id);

        $seesAll = in_array($me->crm_role, ['admin', 'subadmin'], true) || $me->can('tasks', 'view');
        if (! $seesAll) {
            // Team Heads see their subtree's tasks, not just their own.
            $teamIds = $me->teamMemberIds();
            $query->where(fn ($q) => $q->whereIn('assigned_member_id', $teamIds)->orWhereIn('assigned_by', $teamIds));
        }

        return $query;
    }

    private function validateTask(Request $request, int $orgId): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assigned_member_uuid' => ['required', 'string'],
            'kind' => ['nullable', Rule::in(Task::KINDS)],
            // What it is about, if it is about a document at all.
            'invoice_uuid' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            // The window it is expected to run in, beside the deadline.
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            /*
             * When to start asking, and how often after that.
             *
             * Work due in three months should not be popping up today, which
             * is exactly what a reminder with no date of its own would do.
             */
            'remind_at' => ['nullable', 'date'],
            'remind_every_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $data['assigned_member_id'] = Member::where('organization_id', $orgId)
            ->where('uuid', $data['assigned_member_uuid'])
            ->firstOrFail()->id;
        unset($data['assigned_member_uuid']);

        if (array_key_exists('invoice_uuid', $data)) {
            $uuid = $data['invoice_uuid'];
            unset($data['invoice_uuid']);
            $data['invoice_id'] = $uuid
                ? Invoice::where('organization_id', $orgId)->where('uuid', $uuid)->firstOrFail()->id
                : null;
        }

        return $data;
    }

    private function serialize(Task $t, Request $request): array
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');

        return [
            'uuid' => $t->uuid,
            'title' => $t->title,
            'description' => $t->description,
            'kind' => $t->kind,
            'assignee' => $t->assignee ? ['uuid' => $t->assignee->uuid, 'name' => $t->assignee->user?->name] : null,
            'assigned_by' => $t->assigner?->user?->name,
            'assigned_by_uuid' => $t->assigner?->uuid,
            'due_at' => $t->due_at?->toDateTimeString(),
            'start_at' => $t->start_at?->toDateTimeString(),
            'end_at' => $t->end_at?->toDateTimeString(),
            'overdue' => $t->due_at !== null && $t->status !== 'done' && $t->due_at->isPast(),
            'priority' => $t->priority,
            'status' => $t->status,
            'progress_note' => $t->progress_note,
            'submitted_at' => $t->submitted_at?->toDateTimeString(),
            'reviewed_by' => $t->reviewer?->user?->name,
            'review_note' => $t->review_note,
            // Said out loud, so nobody wonders whether they misread it.
            'edited_at' => $t->edited_at?->toDateTimeString(),
            'edited_by' => $t->editor?->user?->name,
            'remind_at' => $t->remind_at?->toDateTimeString(),
            'remind_every_days' => $t->remind_every_days,
            'awaiting' => $t->awaiting,
            'last_reply_at' => $t->last_reply_at?->toDateTimeString(),
            'comments_count' => $t->comments_count ?? 0,
            'invoice' => $t->invoice ? [
                'uuid' => $t->invoice->uuid,
                'number' => $t->invoice->number,
                'kind' => $t->invoice->kind,
                'client' => $t->invoice->client?->company_name,
            ] : null,
            'is_mine' => $t->assigned_member_id === $me->id,
            'is_my_pendency' => $t->assigned_by === $me->id,
            'created_at' => $t->created_at?->toDateTimeString(),
        ];
    }
}
