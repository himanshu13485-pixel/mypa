<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The migration that puts a country in front of every number already filed.
 *
 * DialCodeTest covers the rule. This covers the part that only goes wrong in
 * a real database: that the migration walks both tables and all four columns,
 * writes the rows it should and leaves alone the rows it should not.
 *
 * It runs once, on a live deploy, over every client and lead a business owns,
 * and there is no undo worth having - so it is worth watching it work.
 */
class ExistingNumbersGetIndiaTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_20_100000_every_number_already_in_the_records_is_indian.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function organisation(): int
    {
        return DB::table('crm_organizations')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Acme',
            'code' => 'ACME',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Run the migration again, over whatever is in the tables now. */
    private function backfill(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    public function test_it_codes_both_numbers_on_a_client_and_a_lead(): void
    {
        $org = $this->organisation();

        $client = DB::table('crm_clients')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $org,
            'company_name' => 'Meridian Motors',
            'mobile' => '9310325393',
            'telephone' => '011-2345 6789',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $lead = DB::table('crm_leads')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $org,
            'company_name' => 'Kalyani Engineering',
            'lead_no' => 'L-1',
            'mobile' => '09812345678',
            'phone' => '2228001234',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->backfill();

        $this->assertSame('+919310325393', DB::table('crm_clients')->where('id', $client)->value('mobile'));
        $this->assertSame('+911123456789', DB::table('crm_clients')->where('id', $client)->value('telephone'));
        $this->assertSame('+919812345678', DB::table('crm_leads')->where('id', $lead)->value('mobile'));
        $this->assertSame('+912228001234', DB::table('crm_leads')->where('id', $lead)->value('phone'));
    }

    public function test_it_leaves_alone_what_it_should(): void
    {
        $org = $this->organisation();

        $id = DB::table('crm_clients')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $org,
            'company_name' => 'Gulf Trading',
            // Already says where it is.
            'mobile' => '+971501234567',
            // Too short to be a phone number.
            'telephone' => '1234',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->backfill();

        $this->assertSame('+971501234567', DB::table('crm_clients')->where('id', $id)->value('mobile'));
        $this->assertSame('1234', DB::table('crm_clients')->where('id', $id)->value('telephone'));
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        /*
         * Deploys are re-run, and a half-finished migration is re-run from
         * the start. A rule that added +91 to a number that already had one
         * would turn it into +91+919310325393 on the second pass.
         */
        $org = $this->organisation();

        $id = DB::table('crm_clients')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $org,
            'company_name' => 'Meridian Motors',
            'mobile' => '9310325393',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->backfill();
        $once = DB::table('crm_clients')->where('id', $id)->value('mobile');
        $this->backfill();

        $this->assertSame($once, DB::table('crm_clients')->where('id', $id)->value('mobile'));
        $this->assertSame('+919310325393', $once);
    }

    public function test_an_empty_number_stays_empty(): void
    {
        $org = $this->organisation();

        $id = DB::table('crm_clients')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'organization_id' => $org,
            'company_name' => 'No Numbers Ltd',
            'mobile' => null,
            'telephone' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->backfill();

        $this->assertNull(DB::table('crm_clients')->where('id', $id)->value('mobile'));
        $this->assertSame('', DB::table('crm_clients')->where('id', $id)->value('telephone'));
    }
}
