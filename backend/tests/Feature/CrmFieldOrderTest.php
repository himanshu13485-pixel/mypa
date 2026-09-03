<?php

namespace Tests\Feature;

use App\Models\Crm\CustomField;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a company's own fields sit, on the form and on the document.
 *
 * `sort` has driven this since the fields existed — the form, the printed
 * proforma and invoice, and the validator all read CustomField::methodFor(),
 * so there is one order rather than three that drift. What was missing was
 * any way to set it: a new field took the number after the last one and
 * stayed there for good.
 */
class CrmFieldOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = User::factory()->create(['email' => 'boss@acme.test', 'email_verified_at' => now()]);
        $this->admin->settings()->create([]);
        $this->admin->profile()->create(['timezone' => 'Asia/Kolkata']);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->admin->id, 'crm_role' => 'admin',
        ]);
    }

    private function field(string $key, string $entity = 'work_order', int $sort = 0): CustomField
    {
        return CustomField::create([
            'organization_id' => $this->org->id,
            'entity' => $entity,
            'key' => $key,
            'label' => ucfirst($key),
            'type' => 'text',
            'status' => 'approved',
            'sort' => $sort,
        ]);
    }

    public function test_the_order_saved_is_the_order_the_document_prints(): void
    {
        $a = $this->field('alpha', 'work_order', 0);
        $b = $this->field('bravo', 'work_order', 1);
        $c = $this->field('charlie', 'work_order', 2);

        $keys = fn () => collect(CustomField::workOrderMethod($this->org->id))
            ->where('source', 'custom')->pluck('key')->all();

        $this->assertSame(['alpha', 'bravo', 'charlie'], $keys());

        $this->actingAs($this->admin)->putJson('/api/v1/crm/workspace-fields/order', [
            'entity' => 'work_order',
            'uuids' => [$c->uuid, $a->uuid, $b->uuid],
        ])->assertOk();

        // methodFor() is what the form, the PDF and the validator all read,
        // so this one assertion is all three of them.
        $this->assertSame(['charlie', 'alpha', 'bravo'], $keys());
    }

    public function test_it_renumbers_from_zero_so_the_sequence_cannot_drift(): void
    {
        $a = $this->field('alpha', 'work_order', 5);
        $b = $this->field('bravo', 'work_order', 5);   // a tie, from before this existed

        $this->actingAs($this->admin)->putJson('/api/v1/crm/workspace-fields/order', [
            'entity' => 'work_order',
            'uuids' => [$b->uuid, $a->uuid],
        ])->assertOk();

        $this->assertSame(0, $b->fresh()->sort);
        $this->assertSame(1, $a->fresh()->sort);
    }

    public function test_one_company_cannot_reorder_by_another_companys_field(): void
    {
        $mine = $this->field('alpha');

        $rival = Organization::create(['name' => 'Rival Corp', 'code' => 'RIVAL']);
        $theirs = CustomField::create([
            'organization_id' => $rival->id, 'entity' => 'work_order', 'key' => 'theirs',
            'label' => 'Theirs', 'type' => 'text', 'status' => 'approved', 'sort' => 0,
        ]);

        // Refused rather than quietly ignored: a list that half applied would
        // leave an order nobody asked for and no message saying so.
        $this->actingAs($this->admin)->putJson('/api/v1/crm/workspace-fields/order', [
            'entity' => 'work_order',
            'uuids' => [$theirs->uuid, $mine->uuid],
        ])->assertStatus(422);

        $this->assertSame(0, $theirs->fresh()->sort);
    }

    public function test_each_entity_is_ordered_among_its_own(): void
    {
        // A Work Order column and a document field are not in one list to be
        // ordered against each other; the arrows step within an entity.
        $line = $this->field('alpha', 'work_order', 0);
        $head = $this->field('beta', 'invoice', 0);

        $this->actingAs($this->admin)->putJson('/api/v1/crm/workspace-fields/order', [
            'entity' => 'work_order',
            'uuids' => [$line->uuid, $head->uuid],
        ])->assertStatus(422);
    }
}
