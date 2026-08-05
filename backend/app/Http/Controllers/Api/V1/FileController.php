<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppId;
use App\Models\File;
use App\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        // Whole folders shared with me ride along in the same response.
        $folders = Folder::whereHas('sharedWith', fn ($s) => $s->where('users.id', $request->user()->id))
            ->with('user:id,uuid,name')
            ->withCount('files')
            ->get()
            ->map(fn ($folder) => [
                'uuid' => $folder->uuid,
                'name' => $folder->name,
                'files_count' => $folder->files_count,
                'owner' => ['uuid' => $folder->user->uuid, 'name' => $folder->user->name],
            ]);

        return response()->json(['shared_folders' => $folders] + $files->toArray());
    }

    /** Everything I have shared with others (files + folders), with recipients. */
    public function sharedByMe(Request $request): JsonResponse
    {
        $me = $request->user();

        $files = File::where('user_id', $me->id)
            ->whereHas('sharedWith')
            ->with('sharedWith:id,uuid,name,username')
            ->get()
            ->map(fn ($f) => [
                'kind' => 'file',
                'uuid' => $f->uuid,
                'name' => $f->name,
                'shared_with' => $f->sharedWith->map(fn ($u) => [
                    'uuid' => $u->uuid,
                    'name' => $u->name,
                    'username' => $u->username,
                    'permission' => $u->pivot->permission ?? 'view',
                ])->values(),
            ]);

        $folders = Folder::where('user_id', $me->id)
            ->whereHas('sharedWith')
            ->with('sharedWith:id,uuid,name,username')
            ->withCount('files')
            ->get()
            ->map(fn ($folder) => [
                'kind' => 'folder',
                'uuid' => $folder->uuid,
                'name' => $folder->name,
                'files_count' => $folder->files_count,
                'shared_with' => $folder->sharedWith->map(fn ($u) => [
                    'uuid' => $u->uuid,
                    'name' => $u->name,
                    'username' => $u->username,
                    'permission' => $u->pivot->permission ?? 'view',
                ])->values(),
            ]);

        return response()->json(['data' => $folders->concat($files)->values()]);
    }

    /** Take back access to one of my files from one person. */
    public function unshare(Request $request, File $file): JsonResponse
    {
        abort_unless($file->user_id === $request->user()->id, 403);
        $data = $request->validate(['user_uuid' => ['required', 'uuid']]);

        $target = \App\Models\User::where('uuid', $data['user_uuid'])->firstOrFail();
        $file->sharedWith()->detach($target->id);

        return response()->json(['message' => "Access removed for {$target->name}."]);
    }

    /** Take back access to one of my folders from one person. */
    public function unshareFolder(Request $request, Folder $folder): JsonResponse
    {
        abort_unless($folder->user_id === $request->user()->id, 403);
        $data = $request->validate(['user_uuid' => ['required', 'uuid']]);

        $target = \App\Models\User::where('uuid', $data['user_uuid'])->firstOrFail();
        $folder->sharedWith()->detach($target->id);

        return response()->json(['message' => "Access removed for {$target->name}."]);
    }

    /** Files inside a folder that was shared with me. */
    public function sharedFolderFiles(Request $request, Folder $folder): JsonResponse
    {
        $isShared = $folder->sharedWith()->where('users.id', $request->user()->id)->exists()
            || $folder->user_id === $request->user()->id;
        abort_unless($isShared, 403);

        return response()->json([
            'data' => [
                'folder' => ['uuid' => $folder->uuid, 'name' => $folder->name],
                'files' => $folder->files()->get()->map(fn ($f) => $this->serialize($f, $request)),
            ],
        ]);
    }

    public function shareFolder(Request $request, Folder $folder): JsonResponse
    {
        abort_unless($folder->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:255'],
            'permission' => ['sometimes', 'in:view,edit'],
        ]);

        $target = app(\App\Services\AppIdService::class)->findVisibleUser($data['app_id'], $request->user());

        if (! $target || $target->id === $request->user()->id) {
            return response()->json(['message' => 'No user found for that username, email, or App ID.'], 404);
        }

        $folder->sharedWith()->syncWithoutDetaching([
            $target->id => ['permission' => $data['permission'] ?? 'view'],
        ]);

        $target->notify(new \App\Notifications\SocialNotification(
            'file_shared',
            "{$request->user()->name} shared the folder “{$folder->name}” with you.",
            ['folder_uuid' => $folder->uuid],
            '/files',
        ));

        return response()->json(['message' => 'Folder shared with ' . $target->name . ' — every file inside is included.']);
    }

    public function usage(Request $request): JsonResponse
    {
        // Through the service, so chat attachments and meeting files are in
        // the figure the user sees — they occupy the same allowance.
        $entitlements = app(\App\Services\SubscriptionEntitlementService::class);
        $used = $entitlements->usedStorageBytes($request->user());
        $limit = (int) $entitlements->storageLimitBytes($request->user());

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

        $uploaded = [];

        foreach ($request->file('files') as $upload) {
            \App\Support\UploadGuard::assertSafe($upload);

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

    /**
     * Mint (or replace) a public link for a file.
     *
     * Sharing by app id needs the other person to have an account. A link does
     * not, which is the whole point — it is what you paste to a client.
     *
     * The token is the capability: anyone holding it can download the file, so
     * it is random, long, and returned only here. Re-issuing rotates it, which
     * is also how you revoke a link you have already sent and regret.
     */
    public function shareLink(Request $request, File $file): JsonResponse
    {
        abort_unless($file->user_id === $request->user()->id, 403, 'Only the owner can share this file.');

        $data = $request->validate([
            // Days, not a date, so the caller cannot backdate it. Null is a
            // link that does not lapse.
            'expires_in_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $file->update([
            'share_token' => Str::random(48),
            'share_expires_at' => isset($data['expires_in_days'])
                ? now()->addDays((int) $data['expires_in_days'])
                : null,
            'shared_at' => now(),
        ]);

        return response()->json(['data' => [
            'url' => url("/api/v1/f/{$file->share_token}"),
            'expires_at' => $file->share_expires_at,
            'downloads' => $file->share_downloads,
        ]]);
    }

    /** Withdraw a public link. Anyone already holding it gets a 404. */
    public function revokeShareLink(Request $request, File $file): JsonResponse
    {
        abort_unless($file->user_id === $request->user()->id, 403, 'Only the owner can revoke this link.');

        $file->update(['share_token' => null, 'share_expires_at' => null, 'shared_at' => null]);

        return response()->json(['message' => 'Link revoked.']);
    }

    /**
     * Download by link. No account, no session — the token is the whole check.
     *
     * Deliberately indistinguishable between "no such token", "revoked" and
     * "expired": all 404. Telling a stranger which one it is only helps them
     * guess.
     */
    public function downloadByLink(Request $request, string $token): StreamedResponse
    {
        $file = File::where('share_token', $token)->first();

        abort_if($file === null || ! $file->linkIsLive(), 404, 'That link is no longer available.');
        abort_unless(Storage::disk('local')->exists($file->path), 404, 'File data missing.');

        $file->increment('share_downloads');

        return Storage::disk('local')->download($file->path, $file->name, [
            'Content-Type' => $file->mime_type ?? 'application/octet-stream',
            // The file is attacker-supplied as far as a stranger's browser is
            // concerned; never let it be sniffed into something executable.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
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

        $target = app(\App\Services\AppIdService::class)->findVisibleUser($data['app_id'], $request->user());

        if (! $target || $target->id === $request->user()->id) {
            return response()->json(['message' => 'No user found for that username, email, or App ID.'], 404);
        }

        $file->sharedWith()->syncWithoutDetaching([
            $target->id => ['permission' => $data['permission'] ?? 'view'],
        ]);

        $target->notify(new \App\Notifications\SocialNotification(
            'file_shared',
            "{$request->user()->name} shared a file with you: “{$file->name}”.",
            ['file_uuid' => $file->uuid],
            '/files',
        ));

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
            || ($file->folder_id && $file->folder?->sharedWith()->where('users.id', $user->id)->exists())
            || ($file->group_id && $file->group->members()->where('users.id', $user->id)->exists());

        abort_unless($visible, 403);
    }
}
