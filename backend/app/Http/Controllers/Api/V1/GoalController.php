<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Goal;
use App\Models\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $goals = Goal::visibleTo($request->user())
            ->with(['milestones', 'group:id,uuid,name'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByRaw("status = 'active' DESC")
            ->orderBy('target_date')
            ->get()
            ->map(fn ($goal) => $this->serialize($goal, $request));

        return response()->json(['data' => $goals]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $milestones = $data['milestones'] ?? [];
        unset($data['milestones']);

        $goal = Goal::create($data + ['user_id' => $request->user()->id]);

        foreach ($milestones as $i => $milestone) {
            $goal->milestones()->create([
                'title' => $milestone['title'],
                'due_on' => $milestone['due_on'] ?? null,
                'sort_order' => $i,
            ]);
        }

        return response()->json([
            'message' => 'Goal created.',
            'data' => $this->serialize($goal->load(['milestones', 'group:id,uuid,name']), $request),
        ], 201);
    }

    public function update(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $data = $this->validated($request, $goal);
        unset($data['milestones']);

        if (($data['status'] ?? null) === 'completed' && $goal->status !== 'completed') {
            $data['completed_at'] = now();
            $data['progress'] = 100;
        }

        $goal->update($data);

        return response()->json([
            'message' => 'Goal updated.',
            'data' => $this->serialize($goal->fresh()->load(['milestones', 'group:id,uuid,name']), $request),
        ]);
    }

    public function destroy(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $goal->delete();

        return response()->json(['message' => 'Goal deleted.']);
    }

    // --- Milestones ---------------------------------------------------------

    public function addMilestone(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'due_on' => ['nullable', 'date'],
        ]);

        $goal->milestones()->create($data + [
            'sort_order' => ($goal->milestones()->max('sort_order') ?? 0) + 1,
        ]);

        return response()->json([
            'message' => 'Milestone added.',
            'data' => $this->serialize($goal->fresh()->load(['milestones', 'group:id,uuid,name']), $request),
        ], 201);
    }

    public function toggleMilestone(Request $request, Goal $goal, int $milestoneId): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $milestone = $goal->milestones()->findOrFail($milestoneId);
        $milestone->update(['is_done' => ! $milestone->is_done]);

        // Auto-complete the goal when every milestone is done.
        $goal->refresh()->load('milestones');
        if ($goal->milestones->count() > 0 && $goal->milestones->every->is_done && $goal->status === 'active') {
            $goal->update(['status' => 'completed', 'completed_at' => now(), 'progress' => 100]);
        }

        return response()->json([
            'message' => 'Milestone updated.',
            'data' => $this->serialize($goal->fresh()->load(['milestones', 'group:id,uuid,name']), $request),
        ]);
    }

    public function deleteMilestone(Request $request, Goal $goal, int $milestoneId): JsonResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $goal->milestones()->findOrFail($milestoneId)->delete();

        return response()->json(['message' => 'Milestone removed.']);
    }

    protected function validated(Request $request, ?Goal $goal = null): array
    {
        $data = $request->validate([
            'title' => [$goal ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['sometimes', 'in:' . implode(',', Goal::TYPES)],
            'target_date' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:active,completed,abandoned'],
            'progress' => ['sometimes', 'integer', 'between:0,100'],
            'motivation' => ['nullable', 'string', 'max:255'],
            'group_uuid' => ['sometimes', 'nullable', 'uuid'],
            'milestones' => ['sometimes', 'array'],
            'milestones.*.title' => ['required', 'string', 'max:255'],
            'milestones.*.due_on' => ['nullable', 'date'],
        ]);

        if (array_key_exists('group_uuid', $data)) {
            $group = $data['group_uuid']
                ? Group::withMember($request->user())->where('uuid', $data['group_uuid'])->firstOrFail()
                : null;
            $data['group_id'] = $group?->id;
            unset($data['group_uuid']);
        }

        return $data;
    }

    protected function serialize(Goal $goal, Request $request): array
    {
        return [
            'uuid' => $goal->uuid,
            'title' => $goal->title,
            'description' => $goal->description,
            'type' => $goal->type,
            'target_date' => $goal->target_date?->toDateString(),
            'status' => $goal->status,
            'progress' => $goal->computedProgress(),
            'motivation' => $goal->motivation,
            'is_own' => $goal->user_id === $request->user()->id,
            'group' => $goal->group ? ['uuid' => $goal->group->uuid, 'name' => $goal->group->name] : null,
            'milestones' => $goal->milestones->map(fn ($m) => [
                'id' => $m->id,
                'title' => $m->title,
                'due_on' => $m->due_on?->toDateString(),
                'is_done' => $m->is_done,
            ]),
            'completed_at' => $goal->completed_at,
            'created_at' => $goal->created_at,
        ];
    }
}
