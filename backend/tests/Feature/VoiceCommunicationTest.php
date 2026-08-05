<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The communication side of the voice assistant: calls, messages, meetings,
 * screen sharing, and navigation — including name resolution against the
 * speaker's connections.
 */
class VoiceCommunicationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $rahul;

    protected User $priya;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);

        $this->user = User::factory()->create(['name' => 'Himanshu Sachdeva']);
        $this->user->profile()->create(['timezone' => 'UTC']);

        $this->rahul = User::factory()->create(['name' => 'Rahul Sharma']);
        $this->priya = User::factory()->create(['name' => 'Priya Verma']);

        foreach ([$this->rahul, $this->priya] as $friend) {
            Connection::create([
                'requester_id' => $this->user->id,
                'addressee_id' => $friend->id,
                'status' => 'accepted',
            ]);
        }
    }

    protected function interpret(string $transcript, string $language = 'en'): array
    {
        return $this->actingAs($this->user)
            ->postJson('/api/v1/voice/interpret', ['transcript' => $transcript, 'language' => $language])
            ->assertOk()
            ->json('data');
    }

    public function test_call_by_first_name_resolves_the_connection(): void
    {
        $result = $this->interpret('Call Rahul');

        $this->assertEquals('call_person', $result['intent']);
        $this->assertEquals('audio', $result['data']['call_type']);
        $this->assertCount(1, $result['data']['candidates']);
        $this->assertEquals('Rahul Sharma', $result['data']['candidates'][0]['name']);
        $this->assertEquals($this->rahul->uuid, $result['data']['candidates'][0]['uuid']);
    }

    public function test_video_call_is_detected(): void
    {
        $result = $this->interpret('Video call Rahul Sharma');

        $this->assertEquals('call_person', $result['intent']);
        $this->assertEquals('video', $result['data']['call_type']);
        $this->assertEquals('Rahul Sharma', $result['data']['candidates'][0]['name']);
    }

    public function test_connect_a_call_with_phrasing(): void
    {
        $result = $this->interpret('Connect a call with Rahul');

        $this->assertEquals('call_person', $result['intent']);
        $this->assertEquals('Rahul Sharma', $result['data']['candidates'][0]['name']);
    }

    public function test_message_with_dictated_text(): void
    {
        $result = $this->interpret("Message Rahul saying I'll be 10 minutes late");

        $this->assertEquals('message_person', $result['intent']);
        $this->assertEquals('Rahul Sharma', $result['data']['candidates'][0]['name']);
        $this->assertEquals("i'll be 10 minutes late", $result['data']['text']);
    }

    public function test_tell_someone_something_is_a_message(): void
    {
        $result = $this->interpret('Tell Priya that the report is ready');

        $this->assertEquals('message_person', $result['intent']);
        $this->assertEquals('Priya Verma', $result['data']['candidates'][0]['name']);
        $this->assertEquals('the report is ready', $result['data']['text']);
    }

    public function test_ambiguous_name_returns_every_candidate(): void
    {
        $other = User::factory()->create(['name' => 'Rahul Gupta']);
        Connection::create([
            'requester_id' => $other->id,
            'addressee_id' => $this->user->id,
            'status' => 'accepted',
        ]);

        $result = $this->interpret('Call Rahul');

        $this->assertEquals('call_person', $result['intent']);
        $this->assertCount(2, $result['data']['candidates']);
    }

    public function test_non_connections_are_never_offered(): void
    {
        User::factory()->create(['name' => 'Stranger Danger']);

        $result = $this->interpret('Call Stranger');

        $this->assertEquals('call_person', $result['intent']);
        $this->assertEmpty($result['data']['candidates']);
        $this->assertStringContainsStringIgnoringCase("couldn't find", $result['speech']);
    }

    public function test_meeting_with_multiple_people(): void
    {
        $result = $this->interpret('Start a meeting with Rahul and Priya');

        $this->assertEquals('start_meeting', $result['intent']);
        $this->assertCount(2, $result['data']['people']);
        $this->assertEquals('Rahul Sharma', $result['data']['people'][0]['candidates'][0]['name']);
        $this->assertEquals('Priya Verma', $result['data']['people'][1]['candidates'][0]['name']);
    }

    public function test_plain_meeting_start(): void
    {
        $result = $this->interpret('Start a meeting');

        $this->assertEquals('start_meeting', $result['intent']);
        $this->assertEmpty($result['data']['people']);
    }

    public function test_screen_share_with_person(): void
    {
        $result = $this->interpret('Share my screen with Rahul');

        $this->assertEquals('share_screen', $result['intent']);
        $this->assertEquals('Rahul Sharma', $result['data']['people'][0]['candidates'][0]['name']);
    }

    public function test_open_page_navigates(): void
    {
        $result = $this->interpret('Open messages');

        $this->assertEquals('navigate', $result['intent']);
        $this->assertEquals('messages', $result['data']['page']);
    }

    public function test_every_sidebar_page_is_reachable(): void
    {
        foreach ([
            'Open family & teams' => 'groups',
            'Open projects' => 'projects',
            'Open my subscription' => 'subscription',
            'Open categories' => 'categories',
            'Open admin panel' => 'admin',
            'Open reports' => 'reports',
        ] as $command => $page) {
            $result = $this->interpret($command);
            $this->assertEquals('navigate', $result['intent'], "for: {$command}");
            $this->assertEquals($page, $result['data']['page'], "for: {$command}");
        }
    }

    public function test_subpage_navigation_carries_a_path(): void
    {
        $this->assertEquals('/bills?status=paid', $this->interpret('Show paid bills')['data']['path']);
        $this->assertEquals('/admin?tab=users', $this->interpret('Open admin users')['data']['path']);
        $this->assertEquals('/admin?tab=internal', $this->interpret('Open internal work')['data']['path']);
    }

    public function test_help_lists_capabilities(): void
    {
        $result = $this->interpret('What can you do?');

        $this->assertEquals('help', $result['intent']);
    }

    public function test_hindi_page_before_verb(): void
    {
        $result = $this->interpret('बिल खोलो', 'hi');

        $this->assertEquals('navigate', $result['intent']);
        $this->assertEquals('bills', $result['data']['page']);
    }

    public function test_task_intents_are_untouched(): void
    {
        // These historically resolved to task intents and must stay that way.
        $this->assertEquals('query_tasks', $this->interpret('Show my pending important tasks')['intent']);
        $this->assertEquals('create_task', $this->interpret('Remind me to call Rahul tomorrow at 3 PM')['intent']);
        $this->assertEquals('create_task', $this->interpret('Schedule a meeting with Amit next Monday at 11 AM')['intent']);
    }

    public function test_hindi_call_command(): void
    {
        $result = $this->interpret('Rahul को कॉल करो', 'hi');

        $this->assertEquals('call_person', $result['intent']);
        $this->assertEquals('Rahul Sharma', $result['data']['candidates'][0]['name']);
    }

    public function test_tell_me_is_not_a_message_to_me(): void
    {
        $result = $this->interpret('Tell me what to do today');

        $this->assertNotEquals('message_person', $result['intent']);
    }

    public function test_many_ways_to_ask_for_a_call(): void
    {
        foreach ([
            'Call Rahul',
            'Call to Rahul',
            'Please call Rahul',
            'Can you please call Rahul',
            'Connect Rahul',
            'Connect me to Rahul',
            'Rahul ko call karo',
            'Rahul ko call laga do',
            'Rahul se baat karao',
            'Ring Rahul yaar',
        ] as $phrase) {
            $result = $this->interpret($phrase);
            $this->assertEquals('call_person', $result['intent'], "for: {$phrase}");
            $this->assertEquals('Rahul Sharma', $result['data']['candidates'][0]['name'] ?? null, "for: {$phrase}");
        }
    }

    public function test_many_ways_to_send_a_message(): void
    {
        foreach ([
            'Message Rahul saying running late' => 'running late',
            'Rahul ko message bhejo ki main late hoon' => 'main late hoon',
            'Rahul ko bolo main aa raha hoon' => 'main aa raha hoon',
            'Rahul ko bata do meeting cancel hai' => 'meeting cancel hai',
        ] as $phrase => $text) {
            $result = $this->interpret($phrase);
            $this->assertEquals('message_person', $result['intent'], "for: {$phrase}");
            $this->assertEquals('Rahul Sharma', $result['data']['candidates'][0]['name'] ?? null, "for: {$phrase}");
            $this->assertEquals($text, $result['data']['text'], "for: {$phrase}");
        }
    }

    public function test_many_ways_to_start_a_meeting(): void
    {
        foreach ([
            'Start a meeting with Rahul and Priya',
            'Meeting with Rahul and Priya',
            'Rahul, Priya ke sath meeting karo',
            'Rahul aur Priya ke sath meeting shuru karo',
        ] as $phrase) {
            $result = $this->interpret($phrase);
            $this->assertEquals('start_meeting', $result['intent'], "for: {$phrase}");
            $this->assertCount(2, $result['data']['people'], "for: {$phrase}");
        }
    }

    public function test_romanised_screen_share(): void
    {
        $result = $this->interpret('Rahul ke sath screen share karo');

        $this->assertEquals('share_screen', $result['intent']);
        $this->assertEquals('Rahul Sharma', $result['data']['people'][0]['candidates'][0]['name']);
    }

    public function test_smalltalk_replies_like_a_person(): void
    {
        $this->assertEquals('smalltalk', $this->interpret('Hello')['intent']);
        $this->assertEquals('smalltalk', $this->interpret('Thank you')['intent']);
        $this->assertEquals('smalltalk', $this->interpret('Who are you?')['intent']);
        $this->assertEquals('smalltalk', $this->interpret('कैसे हो', 'hi')['intent']);
    }

    public function test_fillers_are_stripped_everywhere(): void
    {
        $this->assertEquals('navigate', $this->interpret('Please open bills yaar')['intent']);
        $this->assertEquals('create_task', $this->interpret('Kindly remind me to call the bank tomorrow at 3 PM')['intent']);
    }
}
