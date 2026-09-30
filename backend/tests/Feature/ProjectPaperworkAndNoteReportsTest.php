<?php

namespace Tests\Feature;

use App\Mail\NoteDailyReport;
use App\Models\Note;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ItemPasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The paperwork behind a ledger line, and the ways back into a locked thing.
 *
 * An entry is a line - "cement, 50 bags, 18,400" - and the bill for it used
 * to live in somebody's phone. A note behind a forgotten password was simply
 * a lost note. Both are answered here.
 */
class ProjectPaperworkAndNoteReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->me = User::factory()->create(['email' => 'owner@test.test', 'email_verified_at' => now()]);
        $this->me->settings()->create([]);
        $this->me->profile()->create(['timezone' => 'UTC']);
    }

    private function project(array $extra = []): Project
    {
        return Project::create($extra + ['user_id' => $this->me->id, 'name' => 'PC05', 'base_currency' => 'INR']);
    }

    private function entry(Project $project): string
    {
        return $this->actingAs($this->me)->postJson("/api/v1/projects/{$project->uuid}/entries", [
            'entry_date' => now()->toDateString(),
            'description' => 'Cement 50 bags',
            'direction' => 'debit',
            'amount' => 18400,
        ])->assertCreated()->json('data.uuid');
    }

    public function test_a_ledger_line_carries_its_own_paperwork(): void
    {
        $project = $this->project();
        $entry = $this->entry($project);

        $file = $this->actingAs($this->me)
            ->postJson("/api/v1/projects/{$project->uuid}/entries/{$entry}/files", [
                'file' => UploadedFile::fake()->create('cement-bill.pdf', 20, 'application/pdf'),
            ])->assertCreated()->json('data');
        $this->assertSame('cement-bill.pdf', $file['name']);

        // The line carries it, so the list shows it without a second request.
        $row = collect($this->actingAs($this->me)
            ->getJson("/api/v1/projects/{$project->uuid}/entries")->assertOk()->json('data'))
            ->firstWhere('uuid', $entry);
        $this->assertSame(['cement-bill.pdf'], collect($row['files'])->pluck('name')->all());
        // Where it sits on disk is the server's business.
        $this->assertStringNotContainsString('path', json_encode($row['files']));

        $this->actingAs($this->me)
            ->get("/api/v1/projects/{$project->uuid}/entries/{$entry}/files/{$file['uuid']}")
            ->assertOk()->assertDownload('cement-bill.pdf');

        $this->actingAs($this->me)
            ->deleteJson("/api/v1/projects/{$project->uuid}/entries/{$entry}/files/{$file['uuid']}")
            ->assertOk();
        $this->assertSame(0, \App\Models\ProjectEntryFile::count());
    }

    public function test_somebody_elses_project_hands_out_nothing(): void
    {
        $stranger = User::factory()->create(['email' => 'nosey@test.test']);
        $stranger->settings()->create([]);
        $stranger->profile()->create(['timezone' => 'UTC']);

        $project = $this->project();
        $entry = $this->entry($project);

        $this->actingAs($stranger)
            ->postJson("/api/v1/projects/{$project->uuid}/entries/{$entry}/files", [
                'file' => UploadedFile::fake()->create('nosey.pdf', 4, 'application/pdf'),
            ])->assertForbidden();
    }

    public function test_a_forgotten_project_password_is_answered_by_the_owners_own_inbox(): void
    {
        Notification::fake();
        $project = $this->project(['password_hash' => Hash::make('secret1')]);

        $this->actingAs($this->me)
            ->postJson("/api/v1/projects/{$project->uuid}/request-password-reset")
            ->assertOk()->assertJsonPath('data.sent_to', 'ow***@test.test');

        // The code goes to the owner, and nobody has to wait on an admin.
        $code = null;
        Notification::assertSentTo($this->me, ItemPasswordResetNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return $n->kind === 'project';
        });

        $this->actingAs($this->me)->postJson("/api/v1/projects/{$project->uuid}/reset-password", [
            'code' => 'wrong0', 'new_password' => 'fresh1',
        ])->assertStatus(422);

        $this->actingAs($this->me)->postJson("/api/v1/projects/{$project->uuid}/reset-password", [
            'code' => $code, 'new_password' => 'fresh1',
        ])->assertOk();

        $this->assertTrue(Hash::check('fresh1', $project->fresh()->password_hash));
        // Spent, so the same code cannot be used twice.
        $this->assertNull($project->fresh()->reset_code_hash);
    }

    public function test_a_forgotten_note_password_can_be_taken_off_by_e_mail(): void
    {
        Notification::fake();
        $note = Note::create([
            'user_id' => $this->me->id, 'title' => 'Daily Work', 'body' => 'Secret',
            'type' => 'text', 'password_hash' => Hash::make('gone-forever'),
        ]);

        $this->actingAs($this->me)->postJson("/api/v1/notes/{$note->uuid}/request-password-reset")->assertOk();

        $code = null;
        Notification::assertSentTo($this->me, ItemPasswordResetNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return $n->kind === 'note';
        });

        // Blank is what somebody who has forgotten it usually wants: off.
        $this->actingAs($this->me)->postJson("/api/v1/notes/{$note->uuid}/reset-password", [
            'code' => $code,
        ])->assertOk();

        $this->assertNull($note->fresh()->password_hash);
        $this->assertSame('Secret', $this->actingAs($this->me)
            ->getJson("/api/v1/notes/{$note->uuid}")->assertOk()->json('data.body'));
    }

    public function test_only_the_notes_owner_may_ask_for_a_code(): void
    {
        $stranger = User::factory()->create(['email' => 'other@test.test', 'email_verified_at' => now()]);
        $stranger->settings()->create([]);
        $stranger->profile()->create(['timezone' => 'UTC']);

        $note = Note::create([
            'user_id' => $this->me->id, 'title' => 'Mine', 'type' => 'text',
            'password_hash' => Hash::make('secret1'),
        ]);

        $this->actingAs($stranger)
            ->postJson("/api/v1/notes/{$note->uuid}/request-password-reset")
            ->assertForbidden();
    }

    public function test_a_note_writes_on_the_days_it_changed_and_keeps_a_locked_one_to_itself(): void
    {
        Mail::fake();

        $open = Note::create([
            'user_id' => $this->me->id, 'title' => 'Open note', 'body' => 'Pay the electrician',
            'type' => 'text', 'daily_report' => true,
        ]);
        $locked = Note::create([
            'user_id' => $this->me->id, 'title' => 'Locked note', 'body' => 'Bank code 4821',
            'type' => 'text', 'daily_report' => true, 'password_hash' => Hash::make('secret1'),
        ]);
        Note::create([
            'user_id' => $this->me->id, 'title' => 'Quiet note', 'type' => 'text', 'daily_report' => false,
        ]);

        $this->artisan('mypa:note-daily-reports')->assertSuccessful();

        // The two that asked for a letter get one; the one that did not, does not.
        Mail::assertQueued(NoteDailyReport::class, 2);
        Mail::assertQueued(NoteDailyReport::class, fn ($m) => $m->note->is($open) && ! $m->locked);
        Mail::assertQueued(NoteDailyReport::class, fn ($m) => $m->note->is($locked) && $m->locked);

        // A locked note is named and never quoted: an inbox is the least
        // private place a note can end up.
        $body = (new NoteDailyReport($locked->fresh(), true))->render();
        $this->assertStringContainsString('Locked note', $body);
        $this->assertStringNotContainsString('4821', $body);

        // And nothing new tomorrow means nothing sent tomorrow.
        Mail::fake();
        $this->artisan('mypa:note-daily-reports')->assertSuccessful();
        Mail::assertNothingQueued();
    }
}
