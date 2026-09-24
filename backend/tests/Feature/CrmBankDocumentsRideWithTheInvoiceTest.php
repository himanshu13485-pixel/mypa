<?php

namespace Tests\Feature;

use App\Models\Crm\BankAccount;
use App\Models\Crm\Client;
use App\Models\Crm\IssuingCompany;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The bank's own paperwork lives with the bank account.
 *
 * A cancelled cheque or a bank letter is filed once in Billing setup and
 * offered - unticked - whenever an invoice goes out by mail. Nothing is
 * uploaded at send time, so what a client receives is always a paper
 * somebody deliberately put on the account.
 */
class CrmBankDocumentsRideWithTheInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $boss;
    private Organization $org;
    private IssuingCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->boss = User::factory()->create(['email' => 'boss@acme.test']);
        $this->boss->settings()->create([]);
        $this->boss->profile()->create(['timezone' => 'UTC']);

        $this->org = Organization::create(['name' => 'Acme Pvt Ltd', 'code' => 'ACME']);
        Member::create(['organization_id' => $this->org->id, 'user_id' => $this->boss->id, 'crm_role' => 'admin']);
        $this->company = IssuingCompany::create(['organization_id' => $this->org->id, 'name' => 'Acme Billing']);
    }

    /** @return list<\Symfony\Component\Mime\Email> */
    private function sent(): array
    {
        return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
            ->map(fn ($m) => $m->getOriginalMessage())
            ->all();
    }

    private function invoice(): string
    {
        $client = Client::create([
            'organization_id' => $this->org->id, 'company_name' => 'Buyer Ltd',
            'email' => 'buyer@client.test', 'created_by' => $this->boss->id,
        ]);

        return $this->actingAs($this->boss)->postJson('/api/v1/crm/invoices', [
            'kind' => 'invoice', 'issuing_company_id' => $this->company->id, 'client_uuid' => $client->uuid,
            'invoice_date' => now()->toDateString(), 'due_date' => '2026-12-31',
            'client_category' => 'new', 'pricing_tier' => 'regular', 'terms_of_payment' => '100% advance',
            'subscription_type' => 'online', 'dispatch_status' => 'pending',
            'items' => [['membership' => 'Standard', 'validity_from' => '2026-01-01', 'validity_to' => '2026-12-31',
                'plan_name' => 'Plan A', 'qty' => 1, 'unit_price' => 1000]],
        ])->assertCreated()->json('data.uuid');
    }

    public function test_a_paper_filed_on_the_account_is_offered_and_only_goes_when_ticked(): void
    {
        config(['mail.default' => 'array']);

        $bank = BankAccount::create([
            'organization_id' => $this->org->id, 'issuing_company_id' => $this->company->id,
            'label' => 'Mercury', 'bank_name' => 'Column N.A.', 'account_no' => '478613819089937', 'is_active' => true,
        ]);

        $letter = $this->actingAs($this->boss)->postJson('/api/v1/crm/masters/bank-accounts/' . $bank->id . '/documents', [
            'file' => UploadedFile::fake()->create('bank-letter.pdf', 12, 'application/pdf'),
        ])->assertCreated()->json('data');
        $this->assertSame('bank-letter.pdf', $letter['name']);

        // Billing setup shows what the account holds, so it can be removed.
        $listed = collect($this->actingAs($this->boss)->getJson('/api/v1/crm/masters')->assertOk()->json('data.bank_accounts'))
            ->firstWhere('label', 'Mercury');
        $this->assertSame(['bank-letter.pdf'], collect($listed['documents'])->pluck('name')->all());

        // The document offers itself on the invoice, unticked until asked for.
        $uuid = $this->invoice();
        $offered = $this->actingAs($this->boss)->getJson('/api/v1/crm/invoices/' . $uuid)
            ->assertOk()->json('data.bank.documents');
        $this->assertSame([$letter['uuid']], collect($offered)->pluck('uuid')->all());

        // Left unticked, only the invoice goes.
        $this->actingAs($this->boss)->postJson('/api/v1/crm/invoices/' . $uuid . '/email', [])->assertOk();
        $this->assertCount(1, $this->sent()[0]->getAttachments());

        // Ticked, it rides along - after the invoice, not in place of it.
        $this->actingAs($this->boss)->postJson('/api/v1/crm/invoices/' . $uuid . '/email', [
            'documents' => [$letter['uuid']],
        ])->assertOk();
        $names = collect($this->sent()[1]->getAttachments())
            ->map(fn ($part) => $part->getPreparedHeaders()->getHeaderParameter('content-disposition', 'filename'))
            ->all();
        $this->assertCount(2, $names);
        $this->assertSame('bank-letter.pdf', $names[1]);
    }

    public function test_a_paper_from_somebody_elses_account_cannot_be_posted_in(): void
    {
        config(['mail.default' => 'array']);

        // Another organisation entirely, with its own bank and its own paper.
        $stranger = User::factory()->create(['email' => 'boss@other.test']);
        $stranger->settings()->create([]);
        $stranger->profile()->create(['timezone' => 'UTC']);
        $otherOrg = Organization::create(['name' => 'Other Ltd', 'code' => 'OTHR']);
        Member::create(['organization_id' => $otherOrg->id, 'user_id' => $stranger->id, 'crm_role' => 'admin']);
        $otherBank = BankAccount::create([
            'organization_id' => $otherOrg->id, 'label' => 'Theirs', 'bank_name' => 'Their Bank', 'is_active' => true,
        ]);
        $theirs = $this->actingAs($stranger)->postJson('/api/v1/crm/masters/bank-accounts/' . $otherBank->id . '/documents', [
            'file' => UploadedFile::fake()->create('their-secret.pdf', 8, 'application/pdf'),
        ])->assertCreated()->json('data.uuid');

        BankAccount::create([
            'organization_id' => $this->org->id, 'issuing_company_id' => $this->company->id,
            'label' => 'Ours', 'bank_name' => 'Our Bank', 'is_active' => true,
        ]);

        // Naming their document does not fetch it; the mail carries the PDF alone.
        $uuid = $this->invoice();
        $this->actingAs($this->boss)->postJson('/api/v1/crm/invoices/' . $uuid . '/email', [
            'documents' => [$theirs],
        ])->assertOk();
        $this->assertCount(1, $this->sent()[0]->getAttachments());

        // Nor can the stranger's account be reached from here to begin with.
        $this->actingAs($this->boss)
            ->postJson('/api/v1/crm/masters/bank-accounts/' . $otherBank->id . '/documents', [
                'file' => UploadedFile::fake()->create('nosey.pdf', 4, 'application/pdf'),
            ])->assertNotFound();
    }

    public function test_a_document_can_be_read_back_and_taken_off_the_account(): void
    {
        $bank = BankAccount::create([
            'organization_id' => $this->org->id, 'label' => 'Mercury', 'bank_name' => 'Column N.A.', 'is_active' => true,
        ]);

        $doc = $this->actingAs($this->boss)->postJson('/api/v1/crm/masters/bank-accounts/' . $bank->id . '/documents', [
            'file' => UploadedFile::fake()->create('cancelled-cheque.pdf', 6, 'application/pdf'),
        ])->assertCreated()->json('data.uuid');

        $this->actingAs($this->boss)
            ->get('/api/v1/crm/masters/bank-accounts/' . $bank->id . '/documents/' . $doc)
            ->assertOk()
            ->assertDownload('cancelled-cheque.pdf');

        $path = $bank->documents()->where('uuid', $doc)->value('path');
        $this->actingAs($this->boss)
            ->deleteJson('/api/v1/crm/masters/bank-accounts/' . $bank->id . '/documents/' . $doc)
            ->assertOk();

        // Gone from the account and gone from the disk, not just unlisted.
        $this->assertSame(0, $bank->documents()->count());
        Storage::disk('local')->assertMissing($path);
    }
}
