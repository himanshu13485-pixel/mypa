<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Member;
use App\Models\Crm\Task;
use App\Notifications\CrmNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tasks with the old CRM's Task Approval flow: a manager assigns, the
 * assignee works and submits, the manager approves (done) or rejects
 * (reopened). Employees see their own tasks; managers the whole board.
 */
class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->scoped($request)->with(['assignee.user:id,name', 'assigner.user:id,name']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($priority = $request->query('priority')) {
            $query->where('priority', $priority);
        }
        if ($member = $request->query('member')) {
            $query->whereHas('assignee', fn ($m) => $m->where('uuid', $member));
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }

        $all = (clone $query)->get(['id', 'status', 'assigned_member_id', 'due_at', 'priority']);
        $summary = [
            'by_status' => collect(Task::STATUSES)
                ->map(fn ($s) => ['status' => $s, 'count' => $all->where('status', $s)->count()])
                ->filter(fn ($s) => $s['count'] > 0)->values(),
            'overdue' => $all->whereNotIn('status', ['done'])
                ->filter(fn ($t) => $t->due_at !== null && $t->due_at->isPast())->count(),
            'awaiting_review' => $all->where('status', 'submitted')->count(),
        ];

        $tasks = $query->orderByRaw("case status when 'submitted' then 0 when 'reopened' then 1 when 'in_progress' then 2 when 'open' then 3 else 4 end")
            ->orderByDesc('id')
            ->paginate(25);
        $tasks->getCollection()->transform(fn ($t) => $this->serialize($t));

        return response()->json(['summary' => $summary] + $tasks->toArray());
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
        ]);

        return response()->json(['message' => 'Task assigned.', 'data' => $this->serialize($task->load(['assignee.user:id,name', 'assigner.user:id,name']))], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $task = $this->scoped($request)->where('uuid', $uuid)->firstOrFail();

        if ($task->status === 'done') {
            abort(422, 'A finished task cannot be edited.');
        }
        $task->update($this->validateTask($request, $org->id));

        return response()->json(['message' => 'Task updated.', 'data' => $this->serialize($task->fresh()->load(['assignee.user:id,name', 'assigner.user:id,name']))]);
    }

    /** The assignee moves their own task forward. */
    public function progress(Request $request, string $uuid): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $task = $this->scoped($request)->where('uuid', $uuid)->firstOrFail();

        if ($task->assigned_member_id !== $me->id) {
            abort(403, 'Only the assignee can update progress.');
        }
        if (in_array($task->status, ['done', 'submitted'], true)) {
            abort(422, 'This task is ' . $task->status . ' — nothing to progress.');
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

        return response()->json([
            'message' => $data['status'] === 'submitted' ? 'Submitted for approval.' : 'Marked in progress.',
            'data' => $this->serialize($task->fresh()->load(['assignee.user:id,name', 'assigner.user:id,name'])),
        ]);
    }

    /** The manager's verdict on a submitted task. */
    public function review(Request $request, string $uuid): JsonResponse
    {
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $task = $this->scoped($request)->where('uuid', $uuid)->firstOrFail();

        if ($task->status !== 'submitted') {
            abort(422, 'Only a submitted task can be reviewed.');
        }

        $data = $request->validate([
            'verdict' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:512'],
        ]);

        $task->update([
            'status' => $data['verdict'] === 'approve' ? 'done' : 'reopened',
            'reviewed_by' => $me->id,
            'reviewed_at' => now(),
            'review_note' => $data['note'] ?? null,
        ]);

        if ($task->assignee?->user) {
            $task->assignee->user->notify(new CrmNotification(
                'crm_task',
                '"' . $task->title . '" was ' . ($data['verdict'] === 'approve' ? 'approved' : 'sent back')
                    . ' by ' . ($me->user?->name ?? 'a manager')
                    . (($data['note'] ?? null) ? ' — "' . $data['note'] . '"' : '') . '.',
                '/crm/tasks',
            ));
        }

        return response()->json([
            'message' => $data['verdict'] === 'approve' ? 'Task approved and closed.' : 'Task sent back.',
            'data' => $this->serialize($task->fresh()->load(['assignee.user:id,name', 'assigner.user:id,name'])),
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $task = $this->scoped($request)->where('uuid', $uuid)->firstOrFail();
        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
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
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
        ]);

        $data['assigned_member_id'] = Member::where('organization_id', $orgId)
            ->where('uuid', $data['assigned_member_uuid'])
            ->firstOrFail()->id;
        unset($data['assigned_member_uuid']);

        return $data;
    }

    private function serialize(Task $t): array
    {
        return [
            'uuid' => $t->uuid,
            'title' => $t->title,
            'description' => $t->description,
            'assignee' => $t->assignee ? ['uuid' => $t->assignee->uuid, 'name' => $t->assignee->user?->name] : null,
            'assigned_by' => $t->assigner?->user?->name,
            'due_at' => $t->due_at?->toDateTimeString(),
            'overdue' => $t->due_at !== null && $t->status !== 'done' && $t->due_at->isPast(),
            'priority' => $t->priority,
            'status' => $t->status,
            'progress_note' => $t->progress_note,
            'submitted_at' => $t->submitted_at?->toDateTimeString(),
            'reviewed_by' => $t->reviewer?->user?->name,
            'review_note' => $t->review_note,
            'created_at' => $t->created_at?->toDateTimeString(),
        ];
    }
}
