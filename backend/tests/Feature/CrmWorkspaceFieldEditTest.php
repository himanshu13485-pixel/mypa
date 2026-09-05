<?php

namespace Tests\Feature;

use App\Models\Crm\CustomField;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Changing a workspace field without losing it first.
 *
 * A company that had switched Plan name to a dropdown and wanted to add a
 * plan had one way through: delete the customisation and ask again. Deleting
 * took effect at once, so the column fell back to plain text on every live
 * document and stayed there until the Super Admin got round to the new
 * request. The cost of adding a plan was losing the list.
 *
 * So the thing these tests are really about is what the documents say while
 * somebody is waiting. An approved field must go on working exactly as it
 * did — approval is what makes a change real, and nothing else.
 */
class CrmWorkspaceFieldEditTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $admin;
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = $this->person();
        Member::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->admin->id,
            'crm_role' => 'admin',
        ]);

        $this->superAdmin = $this->person();
        $this->superAdmin->roles()->attach(Role::where('slug', 'super_admin')->first()->id);
    }

    private function person(): User
    {
        $user = User::factory()->create();
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    /** Plan name, live as a dropdown of two plans. */
    private function livePlanColumn(array $plans = ['Regular', 'Composition']): CustomField
    {
        return CustomField::create([
            'organization_id' => $this->org->id,
            'entity' => 'work_order',
            'key' => 'plan_name',
            'label' => 'Plan',
            'type' => 'select',
            'options' => $plans,
            'is_builtin' => true,
            'status' => 'approved',
        ]);
    }

    private function propose(CustomField $field, array $payload)
    {
        return $this->actingAs($this->admin)
            ->withHeader('X-Crm-Org', $this->org->slug)
            ->putJson('/api/v1/crm/workspace-fields/' . $field->uuid, $payload);
    }

    private function decide(CustomField $field, string $status)
    {
        return $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/admin/crm/field-requests/' . $field->uuid . '/decide', ['status' => $status]);
    }

    public function test_a_plan_can_be_added_without_the_list_going_anywhere(): void
    {
        $field = $this->livePlanColumn();

        $this->propose($field, [
            'label' => 'Plan',
            'type' => 'select',
            'options' => ['Regular', 'Composition', 'Premium'],
        ])->assertOk();

        $field->refresh();

        // The proposal is held, and the column is untouched.
        $this->assertSame(['Regular', 'Composition', 'Premium'], $field->pending['options']);
        $this->assertSame(['Regular', 'Composition'], $field->options);
        $this->assertSame('approved', $field->status);
    }

    public function test_the_documents_do_not_change_until_the_super_admin_says_so(): void
    {
        /*
         * The test that matters. Everything else is bookkeeping — this is the
         * promise: what a company puts on an invoice today is what was
         * approved, not what somebody asked for this morning.
         */
        $field = $this->livePlanColumn();

        $this->propose($field, [
            'label' => 'Scheme',
            'type' => 'select',
            'options' => ['Regular', 'Composition', 'Premium'],
        ])->assertOk();

        $live = collect(CustomField::workOrderMethod($this->org->id))->firstWhere('key', 'plan_name');
        $this->assertSame('Plan', $live['label']);
        $this->assertSame(['Regular', 'Composition'], $live['options']);

        $this->decide($field->fresh(), 'approved')->assertOk();

        $live = collect(CustomField::workOrderMethod($this->org->id))->firstWhere('key', 'plan_name');
        $this->assertSame('Scheme', $live['label']);
        $this->assertSame(['Regular', 'Composition', 'Premium'], $live['options']);
    }

    public function test_a_rejected_change_leaves_the_field_exactly_as_it_was(): void
    {
        $field = $this->livePlanColumn();

        $this->propose($field, [
            'label' => 'Scheme',
            'type' => 'select',
            'options' => ['Only This One', 'And This'],
        ])->assertOk();

        $this->decide($field->fresh(), 'rejected')->assertOk();

        $field->refresh();
        $this->assertNull($field->pending);
        $this->assertSame('Plan', $field->label);
        $this->assertSame(['Regular', 'Composition'], $field->options);
        // Still theirs — what was refused was the change, not the field.
        $this->assertSame('approved', $field->status);
    }

    public function test_a_request_nobody_has_decided_is_simply_edited(): void
    {
        // Nothing of it is on a document, so there is no live definition to
        // protect and no reason to make somebody wait twice.
        $field = CustomField::create([
            'organization_id' => $this->org->id,
            'entity' => 'work_order',
            'key' => 'plan_name',
            'label' => 'Plan',
            'type' => 'select',
            'options' => ['Regular', 'Composition'],
            'is_builtin' => true,
            'status' => 'pending',
        ]);

        $this->propose($field, [
            'label' => 'Plan',
            'type' => 'select',
            'options' => ['Regular', 'Composition', 'Premium'],
        ])->assertOk();

        $field->refresh();
        $this->assertNull($field->pending);
        $this->assertSame(['Regular', 'Composition', 'Premium'], $field->options);
        $this->assertSame('pending', $field->status);
    }

    public function test_editing_a_rejected_request_puts_it_back_in_the_queue(): void
    {
        $field = CustomField::create([
            'organization_id' => $this->org->id,
            'entity' => 'work_order',
            'key' => 'plan_name',
            'label' => 'Plan',
            'type' => 'select',
            'options' => ['A', 'B'],
            'is_builtin' => true,
            'status' => 'rejected',
            'decision_note' => 'Too many.',
        ]);

        $this->propose($field, ['label' => 'Plan', 'type' => 'select', 'options' => ['A', 'B', 'C']])
            ->assertOk();

        $field->refresh();
        $this->assertSame('pending', $field->status);
        // The old verdict is not left hanging off a request nobody has read.
        $this->assertNull($field->decision_note);
    }

    public function test_a_column_is_still_held_to_what_it_may_become(): void
    {
        /*
         * Editing must not be a way round the limits creating one has. Qty
         * only renames, because the line total is worked out from it — asking
         * for it as a dropdown is refused whichever door it comes through.
         */
        $qty = CustomField::create([
            'organization_id' => $this->org->id,
            'entity' => 'work_order',
            'key' => 'qty',
            'label' => 'Quantity',
            'type' => 'number',
            'is_builtin' => true,
            'status' => 'approved',
        ]);

        $this->propose($qty, ['label' => 'Quantity', 'type' => 'select', 'options' => ['1', '2']])
            ->assertStatus(422);

        $this->assertNull($qty->fresh()->pending);
    }

    public function test_a_dropdown_still_needs_something_to_choose_between(): void
    {
        $field = $this->livePlanColumn();

        $this->propose($field, ['label' => 'Plan', 'type' => 'select', 'options' => ['Only One']])
            ->assertStatus(422);

        $this->assertNull($field->fresh()->pending);
    }

    public function test_another_company_cannot_touch_this_one_s_fields(): void
    {
        $field = $this->livePlanColumn();

        $rival = Organization::create(['name' => 'Rival', 'code' => 'RIVAL']);
        $outsider = $this->person();
        Member::create(['organization_id' => $rival->id, 'user_id' => $outsider->id, 'crm_role' => 'admin']);

        $this->actingAs($outsider)
            ->withHeader('X-Crm-Org', $rival->slug)
            ->putJson('/api/v1/crm/workspace-fields/' . $field->uuid, [
                'label' => 'Theirs now', 'type' => 'text',
            ])->assertStatus(404);

        $this->assertSame('Plan', $field->fresh()->label);
    }

    public function test_a_waiting_change_is_work_the_super_admin_can_see(): void
    {
        // An approved row carrying an amendment is not "approved, nothing to
        // do" — it is a request, and a queue that hid it would be a queue
        // nobody's change ever came out of.
        $field = $this->livePlanColumn();
        $this->propose($field, ['label' => 'Plan', 'type' => 'select', 'options' => ['A', 'B', 'C']])->assertOk();

        $body = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/crm/field-requests?status=pending')
            ->assertOk()->json();

        $this->assertSame(1, $body['pending_count']);
        $this->assertCount(1, $body['data']);
        $this->assertSame(['A', 'B', 'C'], $body['data'][0]['pending']['options']);
    }

    public function test_a_second_customisation_of_one_column_is_still_refused(): void
    {
        // The rule that keeps the live Work Order from being two competing
        // definitions. Editing is not a second one; adding still is.
        $this->livePlanColumn();

        $this->actingAs($this->admin)
            ->withHeader('X-Crm-Org', $this->org->slug)
            ->postJson('/api/v1/crm/workspace-fields', [
                'entity' => 'work_order',
                'builtin_key' => 'plan_name',
                'label' => 'Plan again',
                'type' => 'text',
            ])->assertStatus(422);
    }

    public function test_reordering_still_reaches_the_reorder_endpoint(): void
    {
        /*
         * PUT /workspace-fields/order and PUT /workspace-fields/{uuid} live
         * one line apart, and the wrong order makes "order" a uuid — so
         * reordering would quietly become an edit of a field that does not
         * exist. Cheap to get wrong, silent when wrong.
         */
        $first = CustomField::create([
            'organization_id' => $this->org->id, 'entity' => 'client', 'key' => 'port',
            'label' => 'Port', 'type' => 'text', 'status' => 'approved', 'sort' => 0,
        ]);
        $second = CustomField::create([
            'organization_id' => $this->org->id, 'entity' => 'client', 'key' => 'berth',
            'label' => 'Berth', 'type' => 'text', 'status' => 'approved', 'sort' => 1,
        ]);

        $this->actingAs($this->admin)
            ->withHeader('X-Crm-Org', $this->org->slug)
            ->putJson('/api/v1/crm/workspace-fields/order', [
                'entity' => 'client',
                'uuids' => [$second->uuid, $first->uuid],
            ])->assertOk();

        $this->assertTrue($second->fresh()->sort < $first->fresh()->sort);
    }
}
