<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\CmsPost;
use App\Models\Crm\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CMS, reinterpreted for an internal CRM: the company notice board.
 * Announcements, policies, holidays — pinned posts float, expired posts
 * sink, drafts are visible only to editors.
 */
class CmsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        /** @var Member $me */
        $me = $request->attributes->get('crm_member');
        $edits = $me->can('cms', 'edit') || $me->can('cms', 'create');

        $query = CmsPost::with('creator:id,name')->where('organization_id', $org->id);

        if (! $edits) {
            $query->where('status', 'published')
                ->where(fn ($q) => $q->whereNull('publish_on')->orWhereDate('publish_on', '<=', now()))
                ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', now()));
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }

        $posts = $query->orderByDesc('is_pinned')->orderByDesc('id')->paginate(20);
        $posts->getCollection()->transform(fn ($p) => $this->serialize($p));

        return response()->json(['manages' => $edits] + $posts->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $org = $request->attributes->get('crm_org');
        $post = CmsPost::create($this->validatePost($request) + [
            'organization_id' => $org->id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Post created.', 'data' => $this->serialize($post)], 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $post = $this->find($request, $uuid);
        $post->update($this->validatePost($request));

        return response()->json(['message' => 'Post updated.', 'data' => $this->serialize($post->fresh())]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->find($request, $uuid)->delete();

        return response()->json(['message' => 'Post deleted.']);
    }

    private function find(Request $request, string $uuid): CmsPost
    {
        return CmsPost::where('organization_id', $request->attributes->get('crm_org')->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function validatePost(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:50000'],
            'kind' => ['required', Rule::in(CmsPost::KINDS)],
            'is_pinned' => ['nullable', 'boolean'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'publish_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:publish_on'],
        ]);
    }

    private function serialize(CmsPost $p): array
    {
        return [
            'uuid' => $p->uuid,
            'title' => $p->title,
            'body' => $p->body,
            'kind' => $p->kind,
            'is_pinned' => $p->is_pinned,
            'status' => $p->status,
            'publish_on' => $p->publish_on?->toDateString(),
            'expires_on' => $p->expires_on?->toDateString(),
            'created_by' => $p->creator?->name,
            'created_at' => $p->created_at?->toDateTimeString(),
        ];
    }
}
