<?php

namespace App\Http\Controllers\Api\V1\Crm\Mail;

use App\Http\Controllers\Controller;
use App\Jobs\BackupMailAccount;
use App\Models\Crm\ActivityLog;
use App\Models\Crm\MailAccount;
use App\Models\Crm\MailBackupRun;
use App\Models\Crm\Member;
use App\Services\Mail\MailArchive;
use App\Services\Mail\MailVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Where a mailbox's mail is kept, besides the database.
 *
 * Three places, ticked on or off per mailbox, and the first is always on:
 *
 *   1. this server   a folder of .eml files named after the mailbox
 *   2. somewhere else an S3 bucket, a WebDAV share, or Google Drive
 *   3. the person's own computer, which the browser does - the server only
 *      remembers that they asked for it, since a folder on a laptop is not
 *      something a server can reach
 *
 * Also the way mail gets in and out by hand: export a mailbox as a zip of
 * .eml files, or import one back.
 */
class MailBackupController extends Controller
{
    public function __construct(private MailArchive $archive, private MailVault $vault)
    {
    }

    /** Every mailbox this person can back up, with what its archive holds. */
    public function index(Request $request): JsonResponse
    {
        $me = $this->member($request);

        $accounts = MailAccount::for($me)->with('organization')->orderBy('id')->get()
            ->map(function (MailAccount $account) use ($me) {
                $settings = (array) ($account->backup ?? []);
                $remote = (array) ($settings['remote'] ?? []);

                return [
                    'uuid' => $account->uuid,
                    'email' => $account->email,
                    'label' => $account->label,
                    'can_manage' => $account->member_id === $me->id || $me->crm_role === 'admin',
                    'archive' => $this->archive->status($account),
                    'destinations' => [
                        // Always on, and deliberately not switchable: a mailbox
                        // with no copy anywhere is how mail gets lost.
                        'server' => true,
                        'remote' => [
                            'enabled' => (bool) ($remote['enabled'] ?? false),
                            'driver' => $remote['driver'] ?? 's3',
                            'bucket' => $remote['bucket'] ?? null,
                            'region' => $remote['region'] ?? null,
                            'endpoint' => $remote['endpoint'] ?? null,
                            'key' => $remote['key'] ?? null,
                            'url' => $remote['url'] ?? null,
                            'username' => $remote['username'] ?? null,
                            'folder_id' => $remote['folder_id'] ?? null,
                            'path' => $remote['path'] ?? null,
                            'has_secret' => filled($remote['secret'] ?? null) || filled($remote['password'] ?? null) || filled($remote['service_account'] ?? null),
                            'last_run_at' => $settings['remote_last_run_at'] ?? null,
                            'last_error' => $settings['remote_last_error'] ?? null,
                        ],
                        'local' => [
                            'enabled' => (bool) ($settings['local']['enabled'] ?? false),
                            'hint' => $settings['local']['hint'] ?? null,
                        ],
                    ],
                    'last_error' => $settings['last_error'] ?? null,
                ];
            })->values();

        return response()->json([
            'data' => $accounts,
            'runs' => MailBackupRun::where('organization_id', $me->organization_id)
                ->whereIn('mail_account_id', MailAccount::for($me)->pluck('id'))
                ->with('account:id,email')->latest('id')->limit(20)->get()
                ->map(fn (MailBackupRun $r) => $r->serialize())->values(),
            'is_admin' => $me->crm_role === 'admin',
            'drivers' => MailVault::DRIVERS,
        ]);
    }

    /** Which copies this mailbox keeps, and the keys to reach them. */
    public function save(Request $request, MailAccount $account): JsonResponse
    {
        $me = $this->manageable($request, $account);

        $data = $request->validate([
            'remote' => ['nullable', 'array'],
            'remote.enabled' => ['boolean'],
            'remote.driver' => ['nullable', Rule::in(MailVault::DRIVERS)],
            'remote.bucket' => ['nullable', 'string', 'max:200'],
            'remote.region' => ['nullable', 'string', 'max:60'],
            'remote.endpoint' => ['nullable', 'string', 'max:300'],
            'remote.key' => ['nullable', 'string', 'max:300'],
            'remote.secret' => ['nullable', 'string', 'max:500'],
            'remote.url' => ['nullable', 'string', 'max:300'],
            'remote.username' => ['nullable', 'string', 'max:200'],
            'remote.password' => ['nullable', 'string', 'max:300'],
            'remote.folder_id' => ['nullable', 'string', 'max:200'],
            'remote.service_account' => ['nullable', 'string', 'max:8000'],
            'remote.path' => ['nullable', 'string', 'max:200'],
            'local' => ['nullable', 'array'],
            'local.enabled' => ['boolean'],
            'local.hint' => ['nullable', 'string', 'max:300'],
        ]);

        $settings = (array) ($account->backup ?? []);
        $remote = (array) ($settings['remote'] ?? []);

        foreach ((array) ($data['remote'] ?? []) as $key => $value) {
            // A secret left blank keeps the one on file, exactly as a mailbox
            // password does.
            if (in_array($key, ['secret', 'password', 'service_account'], true) && ($value === null || $value === '')) {
                continue;
            }
            $remote[$key] = $value;
        }

        $settings['remote'] = $remote;
        $settings['local'] = [
            'enabled' => (bool) ($data['local']['enabled'] ?? ($settings['local']['enabled'] ?? false)),
            'hint' => $data['local']['hint'] ?? ($settings['local']['hint'] ?? null),
        ];

        $account->forceFill(['backup' => $settings])->save();
        ActivityLog::record($me, $me->organization_id, 'mail_backup.settings', $account, [
            'remote' => $remote['enabled'] ?? false ? ($remote['driver'] ?? null) : 'off',
            'local' => $settings['local']['enabled'],
        ]);

        return response()->json(['message' => 'Backup settings saved.']);
    }

    /** Try the destination now, with one small file. */
    public function test(Request $request, MailAccount $account): JsonResponse
    {
        $this->manageable($request, $account);
        $remote = (array) (($account->backup ?? [])['remote'] ?? []);
        abort_if(empty($remote['driver']), 422, 'Choose and save a destination first.');

        $result = $this->vault->test($remote);

        // Same rule as the mailbox tests: the answer is the answer, not a
        // failed request.
        return response()->json(['data' => $result]);
    }

    /** Archive it now, rather than tonight. */
    public function run(Request $request, MailAccount $account): JsonResponse
    {
        $me = $this->manageable($request, $account);
        $full = $request->boolean('full');

        $run = MailBackupRun::create([
            'organization_id' => $account->organization_id,
            'mail_account_id' => $account->id,
            'member_id' => $me->id,
            'kind' => 'backup',
            'destination' => 'server',
            'status' => 'running',
            'started_at' => now(),
        ]);

        if ($request->boolean('now')) {
            app(BackupMailAccount::class, ['accountId' => $account->id, 'full' => $full, 'runId' => $run->id])
                ->handle($this->archive, $this->vault);

            return response()->json(['message' => 'Backup finished.', 'data' => $run->fresh()->serialize()]);
        }

        BackupMailAccount::dispatch($account->id, $full, $run->id);

        return response()->json(['message' => 'Backup started. It runs in the background.', 'data' => $run->serialize()]);
    }

    /** The whole archive as a zip of .eml files. */
    public function export(Request $request, MailAccount $account): BinaryFileResponse
    {
        $me = $this->manageable($request, $account);
        $folder = $request->input('folder');
        $path = $this->archive->exportZip($account, is_string($folder) && $folder !== '' ? $folder : null);

        ActivityLog::record($me, $me->organization_id, 'mail_backup.exported', $account, ['email' => $account->email]);

        return response()->download(Storage::disk('local')->path($path), 'mails-' . $account->email . '-' . now()->format('Ymd') . '.zip')
            ->deleteFileAfterSend();
    }

    /** Mail back in: .eml, .mbox, or a zip of either. */
    public function import(Request $request, MailAccount $account): JsonResponse
    {
        $me = $this->manageable($request, $account);

        $request->validate([
            'file' => ['required', 'file', 'max:512000'],
            'folder' => ['nullable', Rule::in(\App\Models\Crm\MailMessage::FOLDERS)],
        ]);

        $run = MailBackupRun::create([
            'organization_id' => $account->organization_id,
            'mail_account_id' => $account->id,
            'member_id' => $me->id,
            'kind' => 'import',
            'destination' => 'server',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $file = $request->file('file');
        $result = $this->archive->import($account, $file->getRealPath(), (string) $request->input('folder', 'archive'));

        $run->forceFill([
            'status' => 'done',
            'messages' => $result['added'],
            'bytes' => (int) $file->getSize(),
            'finished_at' => now(),
        ])->save();

        ActivityLog::record($me, $me->organization_id, 'mail_backup.imported', $account, $result);

        return response()->json([
            'message' => "{$result['added']} message(s) imported, {$result['skipped']} already here.",
            'data' => $result,
        ]);
    }

    private function member(Request $request): Member
    {
        return $request->attributes->get('crm_member');
    }

    /** Backups are the owner's business, and the Admin's. */
    private function manageable(Request $request, MailAccount $account): Member
    {
        $me = $this->member($request);
        $mine = $account->member_id === $me->id
            || ($me->crm_role === 'admin' && $account->organization_id === $me->organization_id);
        abort_unless($mine, 404);

        return $me;
    }
}
