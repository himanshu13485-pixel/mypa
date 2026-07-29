<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'start_at' => $this->start_at,
            'due_at' => $this->due_at,
            'estimated_minutes' => $this->estimated_minutes,
            'actual_minutes' => $this->actual_minutes,
            'progress' => $this->progress,
            'location' => $this->location,
            'contact_person' => $this->contact_person,
            'color' => $this->color,
            'is_important' => $this->is_important,
            'is_confidential' => $this->is_confidential,
            'is_favourite' => $this->is_favourite,
            'is_pinned' => $this->is_pinned,
            'repeat_config' => $this->repeat_config,
            'completed_at' => $this->completed_at,
            'archived_at' => $this->archived_at,
            'is_overdue' => $this->due_at !== null
                && $this->due_at->isPast()
                && ! in_array($this->status, ['completed', 'cancelled', 'archived']),
            'owner' => $this->whenLoaded('user', fn () => [
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
                'app_id' => $this->user->appId?->app_id,
            ]),
            'group' => $this->whenLoaded('group', fn () => $this->group ? [
                'uuid' => $this->group->uuid,
                'name' => $this->group->name,
            ] : null),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'uuid' => $this->category->uuid,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
                'color' => $this->category->color,
            ] : null),
            'assignees' => $this->whenLoaded('assignees', fn () => $this->assignees->map(fn ($u) => [
                'uuid' => $u->uuid,
                'name' => $u->name,
                'status' => $u->pivot->status,
            ])),
            'checklists' => $this->whenLoaded('checklists', fn () => $this->checklists->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'is_done' => $c->is_done,
                'sort_order' => $c->sort_order,
            ])),
            'reminders' => $this->whenLoaded('reminders', fn () => $this->reminders->map(fn ($r) => [
                'id' => $r->id,
                'remind_at' => $r->remind_at,
                'offset_minutes' => $r->offset_minutes,
                'channels' => $r->channels,
            ])),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'uuid' => $this->parent->uuid,
                'title' => $this->parent->title,
            ] : null),
            'subtasks' => TaskResource::collection($this->whenLoaded('subtasks')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
