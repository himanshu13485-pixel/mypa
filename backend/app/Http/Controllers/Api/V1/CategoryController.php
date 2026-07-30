<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\AppId;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Category::visibleTo($request->user())
            ->withCount('tasks')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->boolean('tree')) {
            $query->whereNull('parent_id')->with('children');
        }

        return CategoryResource::collection($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $category = $request->user()->categories()->create($data);

        return response()->json([
            'message' => 'Category created.',
            'data' => new CategoryResource($category),
        ], 201);
    }

    public function show(Request $request, Category $category): CategoryResource
    {
        $this->authorizeView($request, $category);

        return new CategoryResource($category->load('children')->loadCount('tasks'));
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        abort_if($category->isSystem(), 403, 'System categories cannot be edited.');
        abort_unless($category->user_id === $request->user()->id, 403);

        $category->update($this->validated($request, $category));

        return response()->json([
            'message' => 'Category updated.',
            'data' => new CategoryResource($category->fresh()),
        ]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        abort_if($category->isSystem(), 403, 'System categories cannot be deleted.');
        abort_unless($category->user_id === $request->user()->id, 403);

        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    public function share(Request $request, Category $category): JsonResponse
    {
        abort_unless($category->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:32'],
            'permission' => ['required', 'in:view,edit,manage'],
        ]);

        $target = app(\App\Services\AppIdService::class)->findVisibleUser($data['app_id'], $request->user());

        if (! $target || $target->id === $request->user()->id) {
            return response()->json(['message' => 'No user found for that username, email, or App ID.'], 404);
        }

        $category->update(['visibility' => 'shared']);
        $category->members()->syncWithoutDetaching([
            $target->id => ['permission' => $data['permission']],
        ]);

        return response()->json(['message' => 'Category shared.']);
    }

    protected function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:16'],
            'description' => ['nullable', 'string', 'max:500'],
            'visibility' => ['sometimes', 'in:private,shared'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'parent_uuid' => ['nullable', 'uuid'],
        ]);

        if (array_key_exists('parent_uuid', $data)) {
            $parent = $data['parent_uuid']
                ? Category::visibleTo($request->user())->where('uuid', $data['parent_uuid'])->firstOrFail()
                : null;
            $data['parent_id'] = $parent?->id;
            unset($data['parent_uuid']);
        }

        return $data;
    }

    protected function authorizeView(Request $request, Category $category): void
    {
        $user = $request->user();

        $visible = $category->isSystem()
            || $category->user_id === $user->id
            || $category->members()->where('users.id', $user->id)->exists();

        abort_unless($visible, 403);
    }
}
