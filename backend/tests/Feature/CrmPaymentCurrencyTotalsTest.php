<?php

namespace Tests\Feature;

use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\Crm\PaymentInboxEntry;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Payments summary in each currency: dollars are never added into a
 * rupee total, on the tiles or in the charts.
 */
class CrmPaymentCurrencyTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_summary_figure_is_kept_per_currency(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $org = Organization::create(['name' => 'Grapout', 'code' => 'GRAP']);
        $admin = User::factory()->create();
        $admin->settings()->create([]);
        $admin->profile()->create(['timezone' => 'Asia/Kolkata']);
        Member::create(['organization_id' => $org->id, 'user_id' => $admin->id, 'crm_role' => 'admin', 'status' => 'active']);

        $log = fn (string $on, float $amount, string $currency, string $mode, string $status) => PaymentInboxEntry::create([
            'organization_id' => $org->id, 'received_on' => $on, 'amount' => $amount, 'currency' => $currency,
            'payment_mode' => $mode, 'status' => $status, 'created_by' => $admin->id,
        ]);

        $log('2026-09-12', 28320, 'INR', 'NEFT', 'claimed');
        $log('2026-09-11', 573, 'USD', 'Payment Gateway', 'unclaimed');
        $log('2026-09-11', 764.5, 'USD', 'Payment Gateway', 'unclaimed');
        $log('2026-09-10', 1000, 'INR', 'Payment Gateway', 'unclaimed');
        $log('2026-08-20', 500, 'INR', 'NEFT', 'pending');

        $summary = $this->actingAs($admin)->getJson('/api/v1/crm/payments')->assertOk()->json('summary');

        $byCode = fn (array $rows) => collect($rows)->keyBy('currency')->map(fn ($r) => (float) $r['amount']);

        $this->assertEquals(['USD' => 1337.5, 'INR' => 1000.0], $byCode($summary['unclaimed_by_currency'])->all());
        $this->assertEquals(['INR' => 28320.0], $byCode($summary['claimed_by_currency'])->all());
        $this->assertEquals(['INR' => 500.0], $byCode($summary['pending_by_currency'])->all());
        $this->assertEquals(['INR' => 29820.0, 'USD' => 1337.5], $byCode($summary['by_currency'])->all());

        // The same mode in two currencies is two rows, not one sum.
        $gateway = collect($summary['by_mode'])->where('mode', 'Payment Gateway')->keyBy('currency');
        $this->assertEquals(1337.5, $gateway['USD']['amount']);
        $this->assertEquals(1000, $gateway['INR']['amount']);

        // And each month is split the same way.
        $september = collect($summary['by_month'])->where('month', '2026-09')->keyBy('currency');
        $this->assertEquals(29320, $september['INR']['amount']);
        $this->assertEquals(1337.5, $september['USD']['amount']);
    }
}
