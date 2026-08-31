<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Projects: multi-purpose money ledgers (construction expenses, business
 * accounts, personal tracking). Entries are credits (money in) and debits
 * (money out) in any currency, via cash or a bank account, with date /
 * mode / direction / text filters, per-currency totals, and CSV export.
 */
class ProjectController extends Controller
{
    // ---- Projects ----------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        $projects = Project::with(['user:id,uuid,name', 'sharedWith:id,uuid,name,username'])
            ->withCount('entries')
            ->where(fn ($q) => $q->where('user_id', $me->id)
                ->orWhereHas('sharedWith', fn ($s) => $s->where('users.id', $me->id)))
            ->orderBy('is_archived')
            ->latest()
            ->get()
            ->map(fn ($p) => $this->serializeProject($p, $me));

        return response()->json(['data' => $projects]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateProject($request);

        if (array_key_exists('password', $data)) {
            $data['password_hash'] = $data['password'] ? \Illuminate\Support\Facades\Hash::make($data['password']) : null;
            unset($data['password']);
        }
        $project = Project::create($data + ['user_id' => $request->user()->id]);

        return response()->json(['message' => 'Project created.', 'data' => $this->serializeProject($project->load('user:id,uuid,name'), $request->user())], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwner($request, $project);
        $data = $this->validateProject($request, updating: true);
        if (array_key_exists('password', $data)) {
            $data['password_hash'] = $data['password'] ? \Illuminate\Support\Facades\Hash::make($data['password']) : null;
            unset($data['password']);
            \App\Models\AuditLog::record($request->user(), $data['password_hash'] ? 'project.password_set' : 'project.password_removed', $project);
        }
        $project->update($data);

        return response()->json(['message' => 'Project updated.', 'data' => $this->serializeProject($project->fresh()->load('user:id,uuid,name', 'sharedWith:id,uuid,name,username'), $request->user())]);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwner($request, $project);
        $project->delete();

        return response()->json(['message' => 'Project deleted (with all its entries).']);
    }

    // ---- Entries -----------------------------------------------------------

    public function entries(Request $request, Project $project): JsonResponse
    {
        $this->authorizeView($request, $project);
        $this->authorizeUnlocked($request, $project);

        $entries = $this->filteredEntries($request, $project)
            ->with(['creator:id,uuid,name', 'editor:id,uuid,name'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(25);

        $entries->getCollection()->transform(fn ($e) => $this->serializeEntry($e));

        return response()->json($entries);
    }

    public function storeEntry(Request $request, Project $project): JsonResponse
    {
        $this->authorizeEdit($request, $project);
        $this->authorizeUnlocked($request, $project);
        $data = $this->validateEntry($request);

        $entry = $project->entries()->create($data + ['created_by' => $request->user()->id]);

        $this->tellTheOthers($project, $request->user(), 'expense_added', $entry,
            'added', $entry->description);

        return response()->json(['message' => 'Entry added.', 'data' => $this->serializeEntry($entry->load(['creator:id,uuid,name', 'editor:id,uuid,name']))], 201);
    }

    public function updateEntry(Request $request, Project $project, ProjectEntry $entry): JsonResponse
    {
        $this->authorizeEdit($request, $project);
        $this->authorizeUnlocked($request, $project);
        abort_unless($entry->project_id === $project->id, 404);

        $data = $this->validateEntry($request, updating: true);
        $data['updated_by'] = $request->user()->id;
        if (array_key_exists('reminder_at', $data) && $data['reminder_at'] !== null) {
            $data['reminder_sent_at'] = null; // re-arm a rescheduled reminder
        }
        $entry->update($data);

        $this->tellTheOthers($project, $request->user(), 'expense_updated', $entry->fresh(),
            'edited', $entry->description);

        return response()->json(['message' => 'Entry updated.', 'data' => $this->serializeEntry($entry->fresh()->load(['creator:id,uuid,name', 'editor:id,uuid,name']))]);
    }

    public function destroyEntry(Request $request, Project $project, ProjectEntry $entry): JsonResponse
    {
        $this->authorizeOwner($request, $project);
        $this->authorizeUnlocked($request, $project);
        abort_unless($entry->project_id === $project->id, 404);
        // Read before it goes: a deleted model keeps its attributes in
        // memory, which is all the message needs.
        $description = $entry->description;
        $entry->delete();

        $this->tellTheOthers($project, $request->user(), 'expense_deleted', $entry,
            'deleted', $description);

        return response()->json(['message' => 'Entry deleted.']);
    }

    /**
     * Tell the rest of the project that the ledger moved.
     *
     * A shared project is the one place in the app where other people spend
     * your money, and until now it was the quietest: you were told once, when
     * the project was shared with you, and never again. Someone could add a
     * hundred thousand rupees of expenses to a ledger you co-own and the only
     * way to find out was to open it and read.
     *
     * Everyone with access hears, except whoever did it — being told about
     * your own typing is just noise. The owner counts as a member here: they
     * are not in sharedWith, and they are precisely the person who most wants
     * to know.
     */
    protected function tellTheOthers(
        Project $project,
        \App\Models\User $actor,
        string $kind,
        ProjectEntry $entry,
        string $verb,
        string $description,
    ): void {
        $recipients = $project->sharedWith()->where('users.id', '!=', $actor->id)->get();
        if ($project->user_id !== $actor->id && $project->user) {
            $recipients->push($project->user);
        }
        if ($recipients->isEmpty()) {
            return;
        }

        $money = $entry->currency . ' ' . $entry->amount;
        $flow = $entry->direction === 'credit' ? 'credit' : 'expense';
        $line = "{$actor->name} {$verb} a {$flow} of {$money} in " . $this->quoted($project->name)
            . ': ' . str($description)->limit(60);

        foreach ($recipients->unique('id') as $person) {
            $person->notify(new \App\Notifications\SocialNotification(
                $kind,
                $line,
                ['project_uuid' => $project->uuid, 'entry_uuid' => $entry->uuid],
                '/projects?open=' . $project->uuid,
                // Per entry, so a busy afternoon on one ledger arrives as
                // the several separate things it actually was.
                'entry-' . $entry->uuid,
            ));
        }
    }

    /** Curly quotes, kept in one place so every message uses the same pair. */
    protected function quoted(string $text): string
    {
        return "“" . $text . "”";
    }

    // ---- Summary & export --------------------------------------------------

    /** Per-currency totals (credit / debit / net) plus cash-vs-bank split, honouring the same filters. */
    public function summary(Request $request, Project $project): JsonResponse
    {
        $this->authorizeView($request, $project);
        $this->authorizeUnlocked($request, $project);

        $rows = $this->filteredEntries($request, $project)
            ->selectRaw('currency, direction, mode, COUNT(*) as n, SUM(amount) as total')
            ->groupBy('currency', 'direction', 'mode')
            ->get();

        $byCurrency = [];
        foreach ($rows as $row) {
            $c = &$byCurrency[$row->currency];
            $c['currency'] = $row->currency;
            $c['credit'] = ($c['credit'] ?? 0) + ($row->direction === 'credit' ? (float) $row->total : 0);
            $c['debit'] = ($c['debit'] ?? 0) + ($row->direction === 'debit' ? (float) $row->total : 0);
            $c['cash'] = ($c['cash'] ?? 0) + ($row->mode === 'cash' ? (float) $row->total * ($row->direction === 'credit' ? 1 : -1) : 0);
            $c['bank'] = ($c['bank'] ?? 0) + ($row->mode === 'bank' ? (float) $row->total * ($row->direction === 'credit' ? 1 : -1) : 0);
            $c['entries'] = ($c['entries'] ?? 0) + (int) $row->n;
            unset($c);
        }

        // The same money, split by whoever entered it. A shared project is
        // several people putting figures in, and the question "who put in
        // what" is the one the single total cannot answer. Summing every
        // person's credit gives the project's credit exactly — the two
        // boxes are one set of numbers cut two ways, so they must agree.
        $perPerson = $this->filteredEntries($request, $project)
            ->selectRaw('currency, direction, created_by, COUNT(*) as n, SUM(amount) as total')
            ->groupBy('currency', 'direction', 'created_by')
            ->get();
        $names = User::whereIn('id', $perPerson->pluck('created_by')->filter()->unique())
            ->get(['id', 'uuid', 'name'])->keyBy('id');

        $people = [];
        foreach ($perPerson as $row) {
            $key = $row->currency . '|' . ($row->created_by ?? 0);
            $p = &$people[$key];
            $p['currency'] = $row->currency;
            $p['uuid'] = $names[$row->created_by]?->uuid ?? null;
            $p['name'] = $names[$row->created_by]?->name ?? 'Removed account';
            $p['credit'] = ($p['credit'] ?? 0) + ($row->direction === 'credit' ? (float) $row->total : 0);
            $p['debit'] = ($p['debit'] ?? 0) + ($row->direction === 'debit' ? (float) $row->total : 0);
            $p['entries'] = ($p['entries'] ?? 0) + (int) $row->n;
            unset($p);
        }
        $byPerson = collect($people)
            ->map(fn ($p) => $p + ['net' => round($p['credit'] - $p['debit'], 2)])
            ->sortByDesc('entries')
            ->groupBy('currency');

        $summary = collect($byCurrency)->map(fn ($c) => $c + [
            'net' => round(($c['credit'] ?? 0) - ($c['debit'] ?? 0), 2),
            'people' => $byPerson->get($c['currency'], collect())->values(),
        ])->values();

        // Everyone who has ever entered in this project, whatever the filters
        // say — the person filter's own list, so choosing one person never
        // removes the others from the picker.
        $contributors = User::whereIn('id', $project->entries()->select('created_by'))
            ->orderBy('name')
            ->get(['uuid', 'name'])
            ->map(fn ($u) => ['uuid' => $u->uuid, 'name' => $u->name]);

        return response()->json(['data' => $summary, 'contributors' => $contributors]);
    }

    /** Excel-compatible CSV of the filtered entries. */
    public function export(Request $request, Project $project): StreamedResponse
    {
        $this->authorizeView($request, $project);
        $this->authorizeUnlocked($request, $project);

        $query = $this->filteredEntries($request, $project)->orderBy('entry_date')->orderBy('id');
        $filename = str($project->name)->slug() . '-ledger-' . now()->format('Ymd-Hi') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it cleanly
            fputcsv($out, ['Date', 'Description', 'Party', 'Type', 'Mode', 'Bank account', 'Currency', 'Credit (in)', 'Debit (out)'], ',', '"', '\\');

            $running = [];
            $query->chunk(500, function ($entries) use ($out, &$running) {
                foreach ($entries as $e) {
                    $running[$e->currency] = ($running[$e->currency] ?? 0)
                        + ((float) $e->amount) * ($e->direction === 'credit' ? 1 : -1);
                    fputcsv($out, [
                        $e->entry_date->toDateString(),
                        $e->description,
                        $e->counterparty,
                        $e->direction === 'credit' ? 'Credit (taken/received)' : 'Debit (given/spent)',
                        $e->mode,
                        $e->bank_account,
                        $e->currency,
                        $e->direction === 'credit' ? number_format((float) $e->amount, 2, '.', '') : '',
                        $e->direction === 'debit' ? number_format((float) $e->amount, 2, '.', '') : '',
                    ], ',', '"', '\\');
                }
            });

            fputcsv($out, [], ',', '"', '\\');
            foreach ($running as $currency => $net) {
                fputcsv($out, ['', '', '', '', '', 'NET TOTAL', $currency, $net >= 0 ? number_format($net, 2, '.', '') : '', $net < 0 ? number_format(-$net, 2, '.', '') : ''], ',', '"', '\\');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---- Sharing -----------------------------------------------------------

    /** Share with a connection by username/email. Creator only. */
    public function share(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwner($request, $project);

        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:255'],
            'permission' => ['sometimes', 'in:view,edit'],
        ]);

        $target = app(\App\Services\AppIdService::class)->findVisibleUser($data['app_id'], $request->user());
        if (! $target || $target->id === $request->user()->id) {
            return response()->json(['message' => 'No user found for that username, email, or App ID.'], 404);
        }

        $permission = $data['permission'] ?? 'view';
        $project->sharedWith()->syncWithoutDetaching([$target->id => ['permission' => $permission]]);

        $target->notify(new \App\Notifications\SocialNotification(
            'project_shared',
            "{$request->user()->name} shared the project \u{201C}{$project->name}\u{201D} with you ("
                . ($permission === 'edit' ? 'can add & edit' : 'view only') . ').',
            ['project_uuid' => $project->uuid],
            '/projects',
        ));

        return response()->json([
            'message' => "Shared with {$target->name} (" . ($permission === 'edit' ? 'can add & edit, cannot delete' : 'view only') . ').',
        ]);
    }

    /** Take back access. Creator only. */
    public function unshare(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwner($request, $project);
        $data = $request->validate(['user_uuid' => ['required', 'uuid']]);

        $target = \App\Models\User::where('uuid', $data['user_uuid'])->firstOrFail();
        $project->sharedWith()->detach($target->id);

        return response()->json(['message' => "Access removed for {$target->name}."]);
    }

    // ---- Password reset (admin-issued codes) --------------------------------

    /** Owner forgot the project password: ping the admins to send a reset code. */
    public function requestPasswordReset(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwner($request, $project);
        abort_unless($project->password_hash, 422, 'This project has no password.');

        $admins = \App\Models\User::whereHas('roles', fn ($r) => $r->whereIn('slug', ['admin', 'super_admin']))->get();
        foreach ($admins as $admin) {
            $admin->notify(new \App\Notifications\SocialNotification(
                'project_reset_request',
                "{$request->user()->name} forgot the password of project \u{201C}{$project->name}\u{201D} and needs a reset code.",
                ['project_uuid' => $project->uuid, 'owner' => $request->user()->name],
                '/admin',
            ));
        }

        return response()->json(['message' => 'The admins have been asked to send you a reset code by email.']);
    }

    /** Owner redeems the admin-issued code and sets a fresh password. */
    public function resetPassword(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwner($request, $project);

        $data = $request->validate([
            'code' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:4', 'max:100'],
        ]);

        abort_unless(
            $project->reset_code_hash
                && $project->reset_code_expires_at?->isFuture()
                && \Illuminate\Support\Facades\Hash::check($data['code'], $project->reset_code_hash),
            422,
            'That reset code is wrong or has expired - ask an admin for a new one.'
        );

        $project->forceFill([
            'password_hash' => \Illuminate\Support\Facades\Hash::make($data['new_password']),
            'reset_code_hash' => null,
            'reset_code_expires_at' => null,
        ])->save();
        \App\Models\AuditLog::record($request->user(), 'project.password_reset', $project);

        return response()->json(['message' => 'Password changed - the project now opens with your new password.']);
    }

    // ---- Helpers -----------------------------------------------------------

    protected function authorizeOwner(Request $request, Project $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 403, 'Only the project creator can do this.');
    }

    /** Owner or anyone the project is shared with. */
    protected function authorizeView(Request $request, Project $project): void
    {
        abort_unless($project->permissionFor($request->user()) !== null, 403);
    }

    /** Owner or a share with edit permission. Editors may add/change, never delete. */
    protected function authorizeEdit(Request $request, Project $project): void
    {
        abort_unless(in_array($project->permissionFor($request->user()), ['owner', 'edit'], true), 403,
            'You have view-only access to this project.');
    }

    /**
     * Password-locked ledgers require the X-Project-Password header on every
     * data request - for the owner AND anyone it is shared with.
     */
    protected function authorizeUnlocked(Request $request, Project $project): void
    {
        if (! $project->password_hash) {
            return;
        }

        $given = (string) $request->header('X-Project-Password', '');
        abort_unless(
            $given !== '' && \Illuminate\Support\Facades\Hash::check($given, $project->password_hash),
            423,
            'This project is password protected.'
        );
    }

    protected function validateProject(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'],
            'purpose' => ['sometimes', 'string', 'max:64'],
            'base_currency' => ['sometimes', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_archived' => ['sometimes', 'boolean'],
            'daily_report' => ['sometimes', 'boolean'],
            'report_format' => ['sometimes', 'in:excel,pdf'],
            'password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:100'],
        ]);
    }

    protected function validateEntry(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'entry_date' => [$updating ? 'sometimes' : 'required', 'date'],
            'description' => [$updating ? 'sometimes' : 'required', 'string', 'max:500'],
            'direction' => [$updating ? 'sometimes' : 'required', 'in:credit,debit'],
            'amount' => [$updating ? 'sometimes' : 'required', 'numeric', 'min:0.01', 'max:999999999999'],
            'currency' => ['sometimes', 'string', 'max:8'],
            'mode' => ['sometimes', 'in:cash,bank'],
            'bank_account' => ['nullable', 'string', 'max:255'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'reminder_at' => ['sometimes', 'nullable', 'date'],
        ]);
    }

    protected function filteredEntries(Request $request, Project $project)
    {
        $query = $project->entries();

        if ($from = $request->query('date_from')) {
            $query->whereDate('entry_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('entry_date', '<=', $to);
        }
        if (in_array($request->query('mode'), ['cash', 'bank'], true)) {
            $query->where('mode', $request->query('mode'));
        }
        if (in_array($request->query('direction'), ['credit', 'debit'], true)) {
            $query->where('direction', $request->query('direction'));
        }
        if ($currency = $request->query('currency')) {
            $query->where('currency', $currency);
        }
        if ($q = $request->query('q')) {
            $query->where(fn ($w) => $w->where('description', 'like', "%{$q}%")
                ->orWhere('counterparty', 'like', "%{$q}%")
                ->orWhere('bank_account', 'like', "%{$q}%"));
        }
        // Whose entries: one person, or several, by account uuid. Names are
        // not unique enough to filter on, so the list sends uuids back.
        $people = array_filter(explode(',', (string) $request->query('people', '')));
        if ($people !== []) {
            $query->whereIn('created_by', User::whereIn('uuid', $people)->select('id'));
        }

        return $query;
    }

    protected function serializeProject(Project $project, ?\App\Models\User $me = null): array
    {
        $isOwner = $me ? $project->user_id === $me->id : true;

        return [
            'uuid' => $project->uuid,
            'name' => $project->name,
            'purpose' => $project->purpose,
            'base_currency' => $project->base_currency,
            'notes' => $project->notes,
            'is_archived' => $project->is_archived,
            'daily_report' => $project->daily_report,
            'report_format' => $project->report_format,
            'has_password' => (bool) $project->password_hash,
            'entries_count' => $project->entries_count ?? null,
            'is_owner' => $isOwner,
            'permission' => $me ? $project->permissionFor($me) : 'owner',
            'owner' => $project->relationLoaded('user') && $project->user
                ? ['uuid' => $project->user->uuid, 'name' => $project->user->name]
                : null,
            'shared_with' => $isOwner && $project->relationLoaded('sharedWith')
                ? $project->sharedWith->map(fn ($u) => [
                    'uuid' => $u->uuid,
                    'name' => $u->name,
                    'username' => $u->username,
                    'permission' => $u->pivot->permission,
                ])->values()
                : [],
            'created_at' => $project->created_at,
        ];
    }

    protected function serializeEntry(ProjectEntry $entry): array
    {
        return [
            'uuid' => $entry->uuid,
            'entry_date' => $entry->entry_date->toDateString(),
            'description' => $entry->description,
            'direction' => $entry->direction,
            'amount' => (string) $entry->amount,
            'currency' => $entry->currency,
            'mode' => $entry->mode,
            'bank_account' => $entry->bank_account,
            'counterparty' => $entry->counterparty,
            'reminder_at' => $entry->reminder_at?->toIso8601String(),
            'created_by' => $entry->relationLoaded('creator') && $entry->creator ? $entry->creator->name : null,
            'updated_by' => $entry->relationLoaded('editor') && $entry->editor ? $entry->editor->name : null,
        ];
    }
}
