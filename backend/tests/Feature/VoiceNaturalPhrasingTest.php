<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\User;
use App\Services\Voice\TranscriptNormalizer;
use Database\Seeders\DefaultCategorySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The phrasings people actually speak, rather than the tidy ones the patterns
 * were first written against: politeness in front of the command, words the
 * recogniser mis-heard, and the many verbs that mean "call" or "meet".
 */
class VoiceNaturalPhrasingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, DefaultCategorySeeder::class]);

        $this->user = User::factory()->create(['name' => 'Himanshu Sachdeva']);
        $this->user->profile()->create(['timezone' => 'UTC']);

        foreach (['Rahul Sharma', 'Priya Verma'] as $name) {
            $friend = User::factory()->create(['name' => $name]);
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

    // --- Normaliser ---------------------------------------------------------

    public function test_politeness_and_mishearings_are_cleaned_before_matching(): void
    {
        $n = new TranscriptNormalizer;

        $this->assertEquals('call rahul', $n->normalize('can you please call rahul now'));
        $this->assertEquals('book a meeting with priya', $n->normalize('ok so um book a meating with priya'));
        $this->assertEquals('open my calendar', $n->normalize('hey i want to open my calender'));
    }

    public function test_normaliser_leaves_the_meaning_of_a_sentence_alone(): void
    {
        $n = new TranscriptNormalizer;

        // "i want to" is only filler at the front. Inside a message body it is
        // what the speaker is actually saying.
        $this->assertEquals(
            'tell rahul i want to leave early',
            $n->normalize('tell rahul i want to leave early'),
        );

        // "मुझे" opens reminder commands, so it must survive.
        $this->assertEquals('मुझे कल याद दिलाना', $n->normalize('मुझे कल याद दिलाना'));

        // A transcript that is nothing but filler must not be emptied out.
        $this->assertNotEquals('', $n->normalize('please'));
    }

    public function test_a_polite_command_still_reaches_the_right_intent(): void
    {
        $result = $this->interpret('Can you please call Rahul now');

        $this->assertEquals('call_person', $result['intent']);
        $this->assertEquals('Rahul Sharma', $result['data']['candidates'][0]['name']);
    }

    // --- Wider verbs --------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function meetingPhrasings(): array
    {
        return [
            'book' => ['Book a meeting with Rahul'],
            'schedule' => ['Schedule a meeting with Rahul'],
            'arrange' => ['Arrange a meeting with Rahul'],
            'set up' => ['Set up a meeting with Rahul'],
            'fix' => ['Fix a meeting with Rahul'],
            'call a meeting' => ['Call a meeting with Rahul'],
            'huddle' => ['Start a huddle with Rahul'],
            'noun first' => ['Meeting with Rahul'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('meetingPhrasings')]
    public function test_meeting_phrasings(string $transcript): void
    {
        $this->assertEquals('start_meeting', $this->interpret($transcript)['intent'], $transcript);
    }

    /** @return array<string, array{string}> */
    public static function callPhrasings(): array
    {
        return [
            'ring' => ['Ring Rahul'],
            'dial' => ['Dial Rahul'],
            'buzz' => ['Buzz Rahul'],
            'give a call' => ['Give Rahul a call'],
            'get on the line' => ['Get Rahul on the line'],
            'talk to' => ['Talk to Rahul'],
            'speak with' => ['Speak with Rahul'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('callPhrasings')]
    public function test_call_phrasings(string $transcript): void
    {
        $this->assertEquals('call_person', $this->interpret($transcript)['intent'], $transcript);
    }

    /** @return array<string, array{string}> */
    public static function messagePhrasings(): array
    {
        return [
            'ping' => ['Ping Rahul'],
            'text' => ['Text Rahul'],
            'drop a line' => ['Drop Rahul a message'],
            'let know' => ['Let Rahul know that the invoice is ready'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('messagePhrasings')]
    public function test_message_phrasings(string $transcript): void
    {
        $this->assertEquals('message_person', $this->interpret($transcript)['intent'], $transcript);
    }

    // --- Boundaries ---------------------------------------------------------

    public function test_a_meeting_at_a_future_time_is_a_reminder_not_an_instant_meeting(): void
    {
        // Meetings start immediately, so anything with a time on it is a
        // reminder. Guards the phrasings the wider verbs newly reach.
        foreach ([
            'Book a meeting with Rahul tomorrow',
            'Set up a meeting with Rahul on Monday',
            'Arrange a meeting with Rahul at 4 pm',
        ] as $transcript) {
            $this->assertEquals('create_task', $this->interpret($transcript)['intent'], $transcript);
        }
    }

    public function test_calling_a_meeting_is_not_calling_a_person(): void
    {
        // Calls are matched before meetings, so "call a meeting" would
        // otherwise be read as a contact named "a meeting with rahul".
        $result = $this->interpret('Call a meeting with Rahul');

        $this->assertEquals('start_meeting', $result['intent']);
        $this->assertEquals('Rahul Sharma', $result['data']['people'][0]['candidates'][0]['name']);
    }

    public function test_message_body_survives_the_wider_patterns(): void
    {
        $result = $this->interpret('Let Rahul know that the invoice is ready');

        $this->assertEquals('message_person', $result['intent']);
        $this->assertStringContainsStringIgnoringCase('invoice is ready', $result['data']['text']);
    }
}
