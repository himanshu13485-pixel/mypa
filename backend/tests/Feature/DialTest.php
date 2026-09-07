<?php

namespace Tests\Feature;

use App\Events\DialRequested;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Click a number on a laptop, dial it on your own phone.
 *
 * The one rule that matters is in the last test: this reaches the caller's own
 * devices and nobody else's. Everything else is about the number arriving in
 * the same shape the tappable link on screen would have used, so the two ways
 * of ringing one lead cannot reach two different people.
 */
class DialTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DialRequested::class]);

        $this->me = User::factory()->create();
        $this->me->profile()->create(['timezone' => 'UTC']);
        $this->me->settings()->create([]);
    }

    private function dial(string $number, ?string $label = null)
    {
        return $this->actingAs($this->me)->postJson('/api/v1/dial', array_filter([
            'number' => $number,
            'label' => $label,
        ]));
    }

    public function test_it_sends_the_number_to_this_persons_own_devices(): void
    {
        $this->dial('+91 98765 43210', 'Bhavya Steel')->assertOk();

        Event::assertDispatched(DialRequested::class, function (DialRequested $e) {
            return $e->userUuid === $this->me->uuid
                && $e->number === '+919876543210'
                && $e->label === 'Bhavya Steel';
        });
    }

    public function test_the_punctuation_people_type_is_stripped(): void
    {
        // Numbers typed into a CRM by hand over years arrive in every shape.
        $this->dial('(022) 2758 1234')->assertOk()->assertJsonPath('data.number', '02227581234');
        $this->dial('098765-43210')->assertOk()->assertJsonPath('data.number', '09876543210');
    }

    public function test_a_leading_plus_survives_because_it_is_meaning(): void
    {
        $this->dial('+91 98765 43210')->assertOk()->assertJsonPath('data.number', '+919876543210');
    }

    public function test_something_that_is_not_a_number_is_refused(): void
    {
        // These fields get used for extensions and placeholders. A phone
        // opened on "12" helps nobody.
        $this->dial('-')->assertStatus(422);
        $this->dial('ext 12')->assertStatus(422);

        Event::assertNotDispatched(DialRequested::class);
    }

    public function test_it_carries_no_target_user_at_all(): void
    {
        /*
         * The guard is the absence of a parameter rather than a check on one:
         * there is nothing in the request that names whose phone should ring,
         * so naming somebody else's is not a thing that can be attempted.
         */
        $someoneElse = User::factory()->create();

        $this->actingAs($this->me)->postJson('/api/v1/dial', [
            'number' => '9876543210',
            'user_uuid' => $someoneElse->uuid,
            'label' => 'Not theirs to ring',
        ])->assertOk();

        Event::assertDispatched(
            DialRequested::class,
            fn (DialRequested $e) => $e->userUuid === $this->me->uuid,
        );
    }

    public function test_a_stranger_cannot_dial_at_all(): void
    {
        $this->postJson('/api/v1/dial', ['number' => '9876543210'])->assertUnauthorized();
    }
}
