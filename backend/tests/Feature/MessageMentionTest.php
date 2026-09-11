<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Group;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Naming somebody in a room, and saying something alongside a file.
 *
 * Being named cuts through a mute: muting a room says "do not tell me what
 * is said in here", not "do not tell me when somebody asks me a question".
 */
class MessageMentionTest extends TestCase
{
    use RefreshDatabase;

    private function room(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $appIds = app(\App\Services\AppIdService::class);

        $me = User::factory()->create(['name' => 'GrapOut CRM', 'username' => 'grapoutcrm']);
        $priyanshu = User::factory()->create(['name' => 'Priyanshu Yadav', 'username' => 'priyanshu']);
        $quiet = User::factory()->create(['name' => 'Bala', 'username' => 'bala']);

        foreach ([$me, $priyanshu, $quiet] as $u) {
            $u->settings()->create([]);
            $u->profile()->create(['timezone' => 'Asia/Kolkata']);
            $appIds->generateFor($u);
        }

        $group = Group::create(['name' => 'GrapOut Payment Update', 'owner_id' => $me->id, 'type' => 'team']);
        $group->members()->attach([$me->id, $priyanshu->id, $quiet->id]);

        $conversation = Conversation::firstOrCreate(
            ['type' => 'group', 'group_id' => $group->id],
            ['name' => $group->name, 'created_by' => $me->id],
        );
        $conversation->members()->sync([$me->id, $priyanshu->id, $quiet->id]);

        return [$me, $priyanshu, $quiet, $conversation];
    }

    public function test_being_named_reaches_somebody_who_muted_the_room(): void
    {
        [$me, $priyanshu, $quiet, $conversation] = $this->room();

        // Both of them have had enough of this group.
        foreach ([$priyanshu, $quiet] as $member) {
            $this->actingAs($member)->postJson("/api/v1/conversations/{$conversation->uuid}/mute")->assertOk();
        }

        Notification::fake();

        $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'body' => '@priyanshu can you confirm the payment on INV-200616?',
        ])->assertCreated();

        // Priyanshu was asked, so Priyanshu is told - by name.
        Notification::assertSentTo($priyanshu, \App\Notifications\SocialNotification::class,
            fn ($notification) => str_contains($notification->message, 'mentioned you'));

        // Bala, who was not asked anything, keeps the quiet they chose.
        Notification::assertNotSentTo($quiet, \App\Notifications\SocialNotification::class);
    }

    public function test_a_handle_nobody_in_the_room_holds_names_nobody(): void
    {
        [$me, $priyanshu, $quiet, $conversation] = $this->room();

        $this->actingAs($quiet)->postJson("/api/v1/conversations/{$conversation->uuid}/mute")->assertOk();

        Notification::fake();

        $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            // An address, and a handle belonging to nobody here.
            'body' => 'Write to accounts@grapout.com, or ask @somebodyelse.',
        ])->assertCreated();

        Notification::assertNotSentTo($quiet, \App\Notifications\SocialNotification::class);
        // Priyanshu never muted anything, so gets the ordinary line.
        Notification::assertSentTo($priyanshu, \App\Notifications\SocialNotification::class,
            fn ($notification) => ! str_contains($notification->message, 'mentioned you'));
    }

    public function test_words_typed_beside_a_file_travel_with_it(): void
    {
        [$me, , , $conversation] = $this->room();
        Storage::fake('local');

        $sent = $this->actingAs($me)->postJson("/api/v1/conversations/{$conversation->uuid}/messages", [
            'type' => 'file',
            'body' => 'Bank details attached - please confirm.',
            'attachments' => [UploadedFile::fake()->create('Corpcio_Global_details.pdf', 12, 'application/pdf')],
        ])->assertCreated();

        // One message, carrying both - not a file with the words dropped.
        $sent->assertJsonPath('data.body', 'Bank details attached - please confirm.');
        $sent->assertJsonPath('data.attachments.0.name', 'Corpcio_Global_details.pdf');

        $this->assertSame(1, $conversation->messages()->count());
    }
}
