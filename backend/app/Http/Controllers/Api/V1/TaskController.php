<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\AppId;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Task::visibleTo($user)
            ->with(['category', 'user.appId', 'assignees', 'tags'])
            ->withCount('checklists');

        // Filters
        if ($status = $request->query('status')) {
            $query->whereIn('status', explode(',', $status));
        }
        if ($priority = $request->query('priority')) {
            $query->whereIn('priority', explode(',', $priority));
        }
        if ($categoryUuid = $request->query('category')) {
            $query->whereHas('category', fn ($q) => $q->where('uuid', $categoryUuid));
        }
        if ($request->boolean('important')) {
            $query->where('is_important', true);
        }
        if ($request->boolean('overdue')) {
            $query->overdue();
        }
        if ($request->boolean('assigned_to_me')) {
            $query->whereHas('assignees', fn ($q) => $q->where('users.id', $user->id));
        }
        if ($request->boolean('assigned_by_me')) {
            $query->where('user_id', $user->id)->has('assignees');
        }
        if ($from = $request->query('date_from')) {
            $query->where('due_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->where('due_at', '<=', $to . ' 23:59:59');
        }
        if ($q = $request->query('q')) {
            $query->where(fn ($w) => $w->where('title', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%"));
        }
        if ($tags = $request->query('tags')) {
            $query->whereHas('tags', fn ($t) => $t->whereIn('name', explode(',', $tags)));
        }
        if (! $request->boolean('include_archived')) {
            $query->where('status', '!=', 'archived');
        }
        // Subtasks stay nested under their parent unless explicitly requested.
        if ($parentUuid = $request->query('parent')) {
            $query->whereHas('parent', fn ($p) => $p->where('uuid', $parentUuid));
        } elseif (! $request->boolean('include_subtasks')) {
            $query->whereNull('parent_id');
        }

        // Sorting
        $sort = $request->query('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        if (in_array($column, ['created_at', 'due_at', 'title', 'priority', 'status', 'progress'])) {
            $query->orderBy('is_pinned', 'desc')->orderBy($column, $direction);
        }

        return TaskResource::collection($query->paginate(min((int) $request->query('per_page', 20), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $task = DB::transaction(function () use ($request, $data) {
            $task = $request->user()->tasks()->create($data['task']);

            foreach ($data['checklist'] as $i => $item) {
                $task->checklists()->create([
                    'title' => $item['title'],
                    'is_done' => $item['is_done'] ?? false,
                    'sort_order' => $i,
                ]);
            }

            foreach ($data['reminders'] as $reminder) {
                $task->reminders()->create([
                    'user_id' => $request->user()->id,
                    'remind_at' => $reminder['remind_at'] ?? $this->offsetRemindAt($task, $reminder),
                    'offset_minutes' => $reminder['offset_minutes'] ?? null,
                    'channels' => $reminder['channels'] ?? ['in_app'],
                    'repeat_until_acknowledged' => $reminder['repeat_until_acknowledged'] ?? false,
                ]);
            }

            $this->syncTags($request, $task, $data['tags']);
            $this->syncAssignees($request, $task, $data['assignees']);

            $task->logActivity($request->user(), 'created');

            return $task;
        });

        return response()->json([
            'message' => 'Task created.',
            'data' => new TaskResource($task->load(['category', 'checklists', 'reminders', 'assignees', 'tags'])),
        ], 201);
    }

    public function show(Request $request, Task $task): TaskResource
    {
        $this->authorizeView($request, $task);

        return new TaskResource($task->load([
            'category', 'user.appId', 'checklists', 'reminders', 'assignees', 'tags',
            'comments.user', 'comments.replies.user', 'subtasks.category', 'parent',
        ]));
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->authorizeEdit($request, $task);

        $data = $this->validated($request, $task);

        $original = $task->only(array_keys($data['task']));
        $task->update($data['task']);

        if ($request->has('checklist')) {
            $task->checklists()->delete();
            foreach ($data['checklist'] as $i => $item) {
                $task->checklists()->create([
                    'title' => $item['title'],
                    'is_done' => $item['is_done'] ?? false,
                    'sort_order' => $i,
                ]);
            }
        }

        if ($request->has('tags')) {
            $this->syncTags($request, $task, $data['tags']);
        }
        if ($request->has('assignees')) {
            $this->syncAssignees($request, $task, $data['assignees']);
        }

        $changed = array_diff_assoc(
            array_map(fn ($v) => is_bool($v) ? (int) $v : (string) $v, $data['task']),
            array_map(fn ($v) => is_bool($v) ? (int) $v : (string) $v, $original)
        );
        if ($changed) {
            $task->logActivity($request->user(), 'updated', array_keys($changed));
        }

        return response()->json([
            'message' => 'Task updated.',
            'data' => new TaskResource($task->fresh()->load(['category', 'checklists', 'reminders', 'assignees', 'tags'])),
        ]);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        abort_unless($task->user_id === $request->user()->id, 403);

        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
    }

    public function updateStatus(Request $request, Task $task): JsonResponse
    {
        $this->authorizeEdit($request, $task);

        $data = $request->validate([
            'status' => ['required', 'in:' . implode(',', config('mypa.task_statuses'))],
        ]);

        $update = ['status' => $data['status']];
        if ($data['status'] === 'completed') {
            $update['completed_at'] = now();
            $update['progress'] = 100;
        } elseif ($data['status'] === 'archived') {
            $update['archived_at'] = now();
        }

        $task->update($update);
        $task->logActivity($request->user(), 'status_changed', ['status' => $data['status']]);

        if ($data['status'] === 'completed') {
            $this->spawnNextOccurrence($task);
        }

        return response()->json([
            'message' => 'Status updated.',
            'data' => new TaskResource($task->fresh()->load(['category'])),
        ]);
    }

    public function updateProgress(Request $request, Task $task): JsonResponse
    {
        $this->authorizeEdit($request, $task);

        $data = $request->validate(['progress' => ['required', 'integer', 'between:0,100']]);

        $task->update([
            'progress' => $data['progress'],
            'status' => $data['progress'] === 100 ? 'completed' : $task->status,
            'completed_at' => $data['progress'] === 100 ? now() : $task->completed_at,
        ]);

        if ($data['progress'] === 100) {
            $this->spawnNextOccurrence($task);
        }

        return response()->json([
            'message' => 'Progress updated.',
            'data' => new TaskResource($task->fresh()),
        ]);
    }

    public function duplicate(Request $request, Task $task): JsonResponse
    {
        $this->authorizeView($request, $task);

        $copy = DB::transaction(function () use ($request, $task) {
            $copy = $request->user()->tasks()->create(array_merge(
                $task->only([
                    'category_id', 'description', 'priority', 'start_at', 'due_at',
                    'estimated_minutes', 'location', 'contact_person', 'color',
                    'is_important', 'is_confidential', 'repeat_config',
                ]),
                ['title' => $task->title . ' (copy)', 'status' => 'not_started', 'progress' => 0]
            ));

            foreach ($task->checklists as $item) {
                $copy->checklists()->create($item->only(['title', 'sort_order']));
            }

            $copy->logActivity($request->user(), 'duplicated_from', ['source' => $task->uuid]);

            return $copy;
        });

        return response()->json([
            'message' => 'Task duplicated.',
            'data' => new TaskResource($copy->load(['category', 'checklists'])),
        ], 201);
    }

    public function toggle(Request $request, Task $task, string $flag): JsonResponse
    {
        $this->authorizeEdit($request, $task);

        $map = ['pin' => 'is_pinned', 'favourite' => 'is_favourite', 'important' => 'is_important'];
        abort_unless(isset($map[$flag]), 404);

        $column = $map[$flag];
        $task->update([$column => ! $task->{$column}]);

        return response()->json([
            'message' => 'Updated.',
            'data' => new TaskResource($task->fresh()),
        ]);
    }

    public function assign(Request $request, Task $task): JsonResponse
    {
        abort_unless($task->user_id === $request->user()->id, 403, 'Only the task owner can assign it.');

        $data = $request->validate([
            'app_ids' => ['required', 'array', 'min:1'],
            'app_ids.*' => ['string', 'max:32'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $assigned = [];
        foreach ($data['app_ids'] as $appId) {
            $user = AppId::where('app_id', strtoupper(trim($appId)))->first()?->user;
            if ($user && $user->id !== $request->user()->id) {
                $task->assignees()->syncWithoutDetaching([
                    $user->id => ['assigned_by' => $request->user()->id, 'note' => $data['note'] ?? null],
                ]);
                $assigned[] = $user->appId->app_id;
            }
        }

        $task->logActivity($request->user(), 'assigned', ['to' => $assigned]);

        return response()->json([
            'message' => count($assigned) . ' user(s) assigned.',
            'data' => new TaskResource($task->fresh()->load('assignees')),
        ]);
    }

    public function activity(Request $request, Task $task): JsonResponse
    {
        $this->authorizeView($request, $task);

        return response()->json([
            'data' => $task->activityLogs()->with('user:id,uuid,name')->paginate(30),
        ]);
    }

    // --- Checklist ----------------------------------------------------------

    public function addChecklistItem(Request $request, Task $task): JsonResponse
    {
        $this->authorizeEdit($request, $task);

        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);

        $item = $task->checklists()->create([
            'title' => $data['title'],
            'sort_order' => ($task->checklists()->max('sort_order') ?? 0) + 1,
        ]);

        return response()->json(['message' => 'Checklist item added.', 'data' => $item], 201);
    }

    public function updateChecklistItem(Request $request, Task $task, int $itemId): JsonResponse
    {
        $this->authorizeEdit($request, $task);

        $item = $task->checklists()->findOrFail($itemId);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'is_done' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $item->update($data);

        return response()->json(['message' => 'Checklist item updated.', 'data' => $item->fresh()]);
    }

    public function deleteChecklistItem(Request $request, Task $task, int $itemId): JsonResponse
    {
        $this->authorizeEdit($request, $task);

        $task->checklists()->findOrFail($itemId)->delete();

        return response()->json(['message' => 'Checklist item removed.']);
    }

    // --- Comments -----------------------------------------------------------

    public function addComment(Request $request, Task $task): JsonResponse
    {
        $this->authorizeView($request, $task);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer', 'exists:task_comments,id'],
        ]);

        $comment = $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
            'parent_id' => $data['parent_id'] ?? null,
        ]);

        $task->logActivity($request->user(), 'commented');

        return response()->json([
            'message' => 'Comment added.',
            'data' => $comment->load('user:id,uuid,name'),
        ], 201);
    }

    // --- Helpers ------------------------------------------------------------

    protected function validated(Request $request, ?Task $task = null): array
    {
        $required = $task ? 'sometimes' : 'required';

        $data = $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'category_uuid' => ['nullable', 'uuid'],
            'parent_uuid' => ['nullable', 'uuid'],
            'priority' => ['sometimes', 'in:' . implode(',', config('mypa.task_priorities'))],
            'status' => ['sometimes', 'in:' . implode(',', config('mypa.task_statuses'))],
            'start_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'estimated_minutes' => ['nullable', 'integer', 'min:0'],
            'actual_minutes' => ['nullable', 'integer', 'min:0'],
            'progress' => ['sometimes', 'integer', 'between:0,100'],
            'location' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:16'],
            'is_important' => ['sometimes', 'boolean'],
            'is_confidential' => ['sometimes', 'boolean'],
            'is_favourite' => ['sometimes', 'boolean'],
            'is_pinned' => ['sometimes', 'boolean'],
            'repeat_config' => ['nullable', 'array'],
            'repeat_config.frequency' => ['required_with:repeat_config', 'in:daily,weekly,monthly,yearly,custom'],
            'repeat_config.interval' => ['sometimes', 'integer', 'min:1'],
            'repeat_config.until' => ['nullable', 'date'],
            'checklist' => ['sometimes', 'array'],
            'checklist.*.title' => ['required', 'string', 'max:255'],
            'checklist.*.is_done' => ['sometimes', 'boolean'],
            'reminders' => ['sometimes', 'array'],
            'reminders.*.remind_at' => ['nullable', 'date'],
            'reminders.*.offset_minutes' => ['nullable', 'integer', 'min:0'],
            'reminders.*.channels' => ['sometimes', 'array'],
            'reminders.*.repeat_until_acknowledged' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:64'],
            'assignees' => ['sometimes', 'array'],
            'assignees.*' => ['string', 'max:32'],
        ]);

        $taskData = array_diff_key($data, array_flip(['category_uuid', 'parent_uuid', 'checklist', 'reminders', 'tags', 'assignees']));

        // Datetimes arrive as wall-clock time in the user's timezone; store UTC.
        $tz = $request->user()->profile?->timezone ?? config('app.timezone');
        foreach (['start_at', 'due_at'] as $field) {
            if (! empty($taskData[$field])) {
                $taskData[$field] = \Illuminate\Support\Carbon::parse($taskData[$field], $tz)->utc();
            }
        }
        if (isset($data['reminders'])) {
            foreach ($data['reminders'] as $i => $reminder) {
                if (! empty($reminder['remind_at'])) {
                    $data['reminders'][$i]['remind_at'] = \Illuminate\Support\Carbon::parse($reminder['remind_at'], $tz)->utc();
                }
            }
        }

        if (array_key_exists('category_uuid', $data)) {
            $category = $data['category_uuid']
                ? Category::visibleTo($request->user())->where('uuid', $data['category_uuid'])->firstOrFail()
                : null;
            $taskData['category_id'] = $category?->id;
        }

        if (array_key_exists('parent_uuid', $data)) {
            $parent = $data['parent_uuid']
                ? Task::visibleTo($request->user())->where('uuid', $data['parent_uuid'])->firstOrFail()
                : null;
            // One level of nesting: a subtask cannot itself have subtasks.
            if ($parent?->parent_id) {
                abort(422, 'Subtasks cannot be nested further.');
            }
            $taskData['parent_id'] = $parent?->id;
        }

        return [
            'task' => $taskData,
            'checklist' => $data['checklist'] ?? [],
            'reminders' => $data['reminders'] ?? [],
            'tags' => $data['tags'] ?? [],
            'assignees' => $data['assignees'] ?? [],
        ];
    }

    /** Completed recurring tasks immediately spawn their next occurrence. */
    protected function spawnNextOccurrence(Task $task): void
    {
        if (! $task->repeat_config) {
            return;
        }

        $next = app(\App\Services\RecurringTaskService::class)
            ->generateNext($task->load(['checklists', 'reminders', 'assignees', 'tags']));

        if ($next) {
            // The finished occurrence leaves the series; the new one carries it on.
            $task->updateQuietly(['repeat_config' => null]);
        }
    }

    protected function offsetRemindAt(Task $task, array $reminder): ?\Illuminate\Support\Carbon
    {
        if (! isset($reminder['offset_minutes']) || ! $task->due_at) {
            return $task->due_at;
        }

        return $task->due_at->copy()->subMinutes((int) $reminder['offset_minutes']);
    }

    protected function syncTags(Request $request, Task $task, array $names): void
    {
        $ids = collect($names)
            ->filter()
            ->unique()
            ->map(fn ($name) => Tag::firstOrCreate([
                'user_id' => $request->user()->id,
                'name' => trim($name),
            ])->id);

        $task->tags()->sync($ids);
    }

    protected function syncAssignees(Request $request, Task $task, array $appIds): void
    {
        foreach ($appIds as $appId) {
            $user = AppId::where('app_id', strtoupper(trim($appId)))->first()?->user;
            if ($user && $user->id !== $request->user()->id) {
                $task->assignees()->syncWithoutDetaching([
                    $user->id => ['assigned_by' => $request->user()->id],
                ]);
            }
        }
    }

    protected function authorizeView(Request $request, Task $task): void
    {
        $user = $request->user();
        $visible = $task->user_id === $user->id
            || $task->assignees()->where('users.id', $user->id)->exists();

        abort_unless($visible, 403);
    }

    protected function authorizeEdit(Request $request, Task $task): void
    {
        $this->authorizeView($request, $task);
    }
}
