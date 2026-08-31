<?php

namespace Tests\Feature;

use App\Models\Crm\InvoiceNote;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Internal notes on a document: office talk, never on the paper. Whoever can
 * see the invoice can read and add; a note is its author's word, so only
 * they — or an Admin — can remove it.
 */
class CrmInvoiceNoteTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $aliceUser;
    protected User $bobUser;
    protected Organization $org;
    protected Member $admin;
    protected Member $alice;
    protected int $issuingCompanyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->adminUser = $this->makeUser('boss@acme.test');
        $this->aliceUser = $this->makeUser('alice@acme.test');
        $this->bobUser = $this->makeUser('bob@acme.test');

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        $this->admin = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->adminUser->id, 'crm_role' => 'admin',
        ]);
        $rights = ['clients' => ['view', 'create'], 'invoices' => ['view', 'create']];
        $this->alice = Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->aliceUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);
        Member::create([
            'organization_id' => $this->org->id, 'user_id' => $this->bobUser->id, 'crm_role' => 'employee',
            'reporting_to' => $this->admin->id, 'rights' => $rights,
        ]);

        $this->issuingCompanyId = $this->actingAs($this->adminUser)
            ->postJson('/api/v1/crm/masters/issuing-companies', [
                'name' => 'Acme Billing Pvt Ltd', 'invoice_prefix' => 'INV-', 'proforma_prefix' => 'PI-',
            ])->assertCreated()->json('data.id');
    }

    private function makeUser(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->settings()->create([]);
        $user->profile()->create(['timezone' => 'UTC']);

        return $user;
    }

    private function document(User $who): string
    {
        $clientUuid = $this->actingAs($who)->postJson('/api/v1/crm/clients', [
            'company_name' => 'Bhavya Steel ' . fake()->unique()->numberBetween(1, 9999),
        ])->assertCreated()->json('data.uuid');

        return $this->actingAs($who)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice',
            'issuing_company_id' => $this->issuingCompanyId,
            'client_uuid' => $clientUuid,
            'invoice_date' => '2026-08-20',
            'items' => [['plan_name' => 'ARTIS - I', 'qty' => 1, 'unit_price' => 5000]],
        ])->assertCreated()->json('data.uuid');
    }

    public function test_whoever_can_see_the_invoice_can_speak_on_it(): void
    {
        $doc = $this->document($this->aliceUser);

        // Alice notes; the admin — who sees everything — answers.
        $this->actingAs($this->aliceUser)->postJson("/api/v1/crm/invoices/{$doc}/notes", [
            'body' => 'Client asked to hold dispatch till the 5th.',
        ])->assertCreated()->assertJsonPath('data.by', $this->aliceUser->name);

        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$doc}/notes", [
            'body' => 'Fine — but nothing ships before part payment.',
        ])->assertCreated();

        $notes = $this->actingAs($this->aliceUser)->getJson("/api/v1/crm/invoices/{$doc}/notes")
            ->assertOk()->json('data');

        $this->assertCount(2, $notes);
        $this->assertSame('Client asked to hold dispatch till the 5th.', $notes[0]['body']);
        $this->assertTrue($notes[0]['is_mine']);
        $this->assertNotNull($notes[0]['at']);
        $this->assertSame($this->adminUser->name, $notes[1]['by']);
    }

    public function test_the_notes_stay_inside_the_ledger_window(): void
    {
        $doc = $this->document($this->aliceUser);

        // Bob cannot see Alice's invoice, so he can neither read nor write.
        $this->actingAs($this->bobUser)->getJson("/api/v1/crm/invoices/{$doc}/notes")->assertNotFound();
        $this->actingAs($this->bobUser)->postJson("/api/v1/crm/invoices/{$doc}/notes", ['body' => 'Hi'])
            ->assertNotFound();
    }

    public function test_a_note_is_its_authors_word(): void
    {
        $doc = $this->document($this->adminUser);
        // Give the admin's document to Alice's window via her own note first:
        // she cannot see it, so the note is the admin's alone.
        $noteUuid = $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$doc}/notes", [
            'body' => 'Commission terms agreed on call.',
        ])->assertCreated()->json('data.uuid');

        $aliceDoc = $this->document($this->aliceUser);
        $aliceNote = $this->actingAs($this->aliceUser)->postJson("/api/v1/crm/invoices/{$aliceDoc}/notes", [
            'body' => 'Part payment promised after Diwali.',
        ])->assertCreated()->json('data.uuid');
        $adminOnAlice = $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$aliceDoc}/notes", [
            'body' => 'Noted.',
        ])->assertCreated()->json('data.uuid');

        // Alice cannot erase the admin's remark on her document…
        $this->actingAs($this->aliceUser)
            ->deleteJson("/api/v1/crm/invoices/{$aliceDoc}/notes/{$adminOnAlice}")
            ->assertForbidden();

        // …but her own is hers, and an Admin may remove anything.
        $this->actingAs($this->aliceUser)
            ->deleteJson("/api/v1/crm/invoices/{$aliceDoc}/notes/{$aliceNote}")
            ->assertOk();
        $this->actingAs($this->adminUser)
            ->deleteJson("/api/v1/crm/invoices/{$doc}/notes/{$noteUuid}")
            ->assertOk();

        $this->assertSame(1, InvoiceNote::count());   // only the admin's "Noted."
    }

    public function test_the_paper_never_carries_the_office_talk(): void
    {
        $doc = $this->document($this->adminUser);
        $this->actingAs($this->adminUser)->postJson("/api/v1/crm/invoices/{$doc}/notes", [
            'body' => 'SECRET-COMMISSION-REMARK',
        ])->assertCreated();

        // Not in the document payload the view renders and prints from…
        $data = $this->actingAs($this->adminUser)->getJson("/api/v1/crm/invoices/{$doc}")
            ->assertOk()->json('data');
        $this->assertStringNotContainsString('SECRET-COMMISSION-REMARK', json_encode($data));

        // …and not in the PDF handed to the client.
        $pdf = $this->actingAs($this->adminUser)->get("/api/v1/crm/invoices/{$doc}/pdf")
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRET-COMMISSION-REMARK', $pdf);
    }
}
