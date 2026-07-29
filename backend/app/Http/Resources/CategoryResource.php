<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'icon' => $this->icon,
            'color' => $this->color,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'sort_order' => $this->sort_order,
            'is_system' => $this->isSystem(),
            'is_own' => $this->user_id === $request->user()?->id,
            'parent_uuid' => $this->whenLoaded('parent', fn () => $this->parent?->uuid),
            'children' => CategoryResource::collection($this->whenLoaded('children')),
            'tasks_count' => $this->whenCounted('tasks'),
        ];
    }
}
