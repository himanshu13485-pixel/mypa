<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppId;
use App\Models\File;
use App\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /** Browse a folder (or the root): subfolders + files + breadcrumb + usage. */
    public function browse(Request $request): JsonResponse
    {
        $user = $request->user();
        $folder = null;

        if ($folderUuid = $request->query('folder')) {
            $folder = Folder::where('uuid', $folderUuid)->where('user_id', $user->id)->firstOrFail();
        }

        $folders = Folder::where('user_id', $user->id)
            ->where('parent_id', $folder?->id)
            ->orderBy('name')
            ->withCount('files')
            ->get()
            ->map(fn ($f) => [
                'uuid' => $f->uuid,
                'name' => $f->name,
                'files_count' => $f->files_count,
                'created_at' => $f->created_at,
            ]);

        $files = File::where('user_id', $user->id)
            ->where('folder_id', $folder?->id)
            ->orderBy('name')
            ->get()
            ->map(fn ($f) => $this->serialize($f, $request));

        // Breadcrumb
        $crumbs = [];
        $cursor = $folder;
        while ($cursor) {
            array_unshift($crumbs, ['uuid' => $cursor->uuid, 'name' => $cursor->name]);
            $cursor = $cursor->parent;
        }

        return response()->json([
            'data' => [
                'folder' => $folder ? ['uuid' => $folder->uuid, 'name' => $folder->name] : null,
                'breadcrumb' => $crumbs,
                'folders' => $folders,
                'files' => $files,
                'usage' => $this->usage($request)->getData(true)['data'],
            ],
        ]);
    }

    public function sharedWithMe(Request $request): JsonResponse
    {
        $files = File::whereHas('sharedWith', fn ($s) => $s->where('users.id', $request->user()->id))
            ->with('user:id,uuid,name')
            ->latest()
            ->paginate(30);

        $files->getCollection()->transform(fn ($f) => $this->serialize($f, $request) + [
            'owner' => ['uuid' => $f->user->uuid, 'name' => $f->user->name],
        ]);

        return response()->json($files);
    }

    public function usage(Request $request): JsonResponse
    {
        $used = (int) File::where('user_id', $request->user()->id)->sum('size');
        $limit = (int) app(\App\Services\SubscriptionEntitlementService::class)
            ->storageLimitBytes($request->user());

        return response()->json([
            'data' => [
                'used_bytes' => $used,
                'limit_bytes' => $limit,
                'percent' => $limit > 0 ? round($used / $limit * 100, 1) : 0,
            ],
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $maxKb = (int) config('mypa.files.max_upload_kb');

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => ['file', "max:{$maxKb}"],
            'folder_uuid' => ['nullable', 'uuid'],
        ]);

        $user = $request->user();
        $folder = $request->input('folder_uuid')
            ? Folder::where('uuid', $request->input('folder_uuid'))->where('user_id', $user->id)->firstOrFail()
            : null;

        // Storage quota check (plan-driven)
        $entitlements = app(\App\Services\SubscriptionEntitlementService::class);
        $incoming = collect($request->file('files'))->sum(fn ($f) => $f->getSize());
        if (! $entitlements->canUploadBytes($user, (int) $incoming)) {
            $upgrade = $entitlements->planWithHigherLimit(
                'storage_bytes',
                (int) $entitlements->storageLimitBytes($user),
            );

            return response()->json([
                'message' => 'Storage limit reached. Delete some files'
                    . ($upgrade ? " or upgrade to {$upgrade->name} for more storage." : '.'),
                'upgrade_plan' => $upgrade?->slug,
            ], 422);
        }

        $blocked = config('mypa.files.blocked_extensions', []);
        $uploaded = [];

        foreach ($request->file('files') as $upload) {
            $ext = strtolower($upload->getClientOriginalExtension());
            if (in_array($ext, $blocked, true)) {
                return response()->json([
                    'message' => "Files of type .{$ext} are not allowed.",
                ], 422);
            }

            $path = $upload->store('user-files/' . $user->id, 'local');

            $uploaded[] = File::create([
                'user_id' => $user->id,
                'folder_id' => $folder?->id,
                'name' => $upload->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $upload->getMimeType(),
                'size' => $upload->getSize(),
            ]);
        }

        return response()->json([
            'message' => count($uploaded) . ' file(s) uploaded.',
            'data' => collect($uploaded)->map(fn ($f) => $this->serialize($f, $request)),
        ], 201);
    }

    public function download(Request $request, File $file): StreamedResponse
    {
        $this->authorizeView($request, $file);

        abort_unless(Storage::disk('local')->exists($file->path), 404, 'File data missing.');

        return Storage::disk('local')->download($file->path, $file->name, [
            'Content-Type' => $file->mime_type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, File $file): JsonResponse
    {
        abort_unless($file->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'folder_uuid' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if (array_key_exists('folder_uuid', $data)) {
            $folder = $data['folder_uuid']
                ? Folder::where('uuid', $data['folder_uuid'])->where('user_id', $request->user()->id)->firstOrFail()
                : null;
            $data['folder_id'] = $folder?->id;
            unset($data['folder_uuid']);
        }

        $file->update($data);

        return response()->json([
            'message' => 'File updated.',
            'data' => $this->serialize($file->fresh(), $request),
        ]);
    }

    public function destroy(Request $request, File $file): JsonResponse
    {
        abort_unless($file->user_id === $request->user()->id, 403);

        $file->delete(); // soft delete → trash

        return response()->json(['message' => 'File moved to trash.']);
    }

    public function trash(Request $request): JsonResponse
    {
        $files = File::onlyTrashed()
            ->where('user_id', $request->user()->id)
            ->latest('deleted_at')
            ->paginate(30);

        $files->getCollection()->transform(fn ($f) => $this->serialize($f, $request) + [
            'deleted_at' => $f->deleted_at,
        ]);

        return response()->json($files);
    }

    public function restore(Request $request, string $uuid): JsonResponse
    {
        $file = File::onlyTrashed()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $file->restore();

        return response()->json(['message' => 'File restored.']);
    }

    public function forceDelete(Request $request, string $uuid): JsonResponse
    {
        $file = File::onlyTrashed()
            ->where('uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        Storage::disk('local')->delete($file->path);
        $file->forceDelete();

        return response()->json(['message' => 'File permanently deleted.']);
    }

    public function share(Request $request, File $file): JsonResponse
    {
        abort_unless($file->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:32'],
            'permission' => ['sometimes', 'in:view,edit'],
        ]);

        $target = AppId::where('app_id', strtoupper(trim($data['app_id'])))->first()?->user;

        if (! $target || $target->id === $request->user()->id) {
            return response()->json(['message' => 'No user found for that App ID.'], 404);
        }

        $file->sharedWith()->syncWithoutDetaching([
            $target->id => ['permission' => $data['permission'] ?? 'view'],
        ]);

        return response()->json(['message' => 'File shared with ' . $target->name . '.']);
    }

    // --- Folders ------------------------------------------------------------

    public function storeFolder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_uuid' => ['nullable', 'uuid'],
        ]);

        $parent = $data['parent_uuid'] ?? null
            ? Folder::where('uuid', $data['parent_uuid'])->where('user_id', $request->user()->id)->firstOrFail()
            : null;

        $folder = $request->user()->folders()->create([
            'name' => $data['name'],
            'parent_id' => $parent?->id,
        ]);

        return response()->json([
            'message' => 'Folder created.',
            'data' => ['uuid' => $folder->uuid, 'name' => $folder->name],
        ], 201);
    }

    public function updateFolder(Request $request, Folder $folder): JsonResponse
    {
        abort_unless($folder->user_id === $request->user()->id, 403);

        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $folder->update($data);

        return response()->json(['message' => 'Folder renamed.']);
    }

    public function destroyFolder(Request $request, Folder $folder): JsonResponse
    {
        abort_unless($folder->user_id === $request->user()->id, 403);

        // Files inside go to trash; subfolders soft-delete via cascade of app logic.
        $folder->files()->get()->each->delete();
        $folder->children()->get()->each(function ($child) {
            $child->files()->get()->each->delete();
            $child->delete();
        });
        $folder->delete();

        return response()->json(['message' => 'Folder deleted. Files moved to trash.']);
    }

    // --- Helpers ------------------------------------------------------------

    protected function serialize(File $file, Request $request): array
    {
        return [
            'uuid' => $file->uuid,
            'name' => $file->name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'is_own' => $file->user_id === $request->user()->id,
            'folder_uuid' => $file->folder?->uuid,
            'created_at' => $file->created_at,
        ];
    }

    protected function authorizeView(Request $request, File $file): void
    {
        $user = $request->user();

        $visible = $file->user_id === $user->id
            || $file->sharedWith()->where('users.id', $user->id)->exists()
            || ($file->group_id && $file->group->members()->where('users.id', $user->id)->exists());

        abort_unless($visible, 403);
    }
}
