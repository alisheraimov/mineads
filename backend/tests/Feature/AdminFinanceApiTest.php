<?php

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Payout;
use App\Models\PublisherAccount;
use App\Models\PublisherLedgerEntry;
use App\Models\Server;
use App\Models\User;
use App\Models\ValidatedImpression;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminFinanceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));
    }

    private function admin(string $role = 'finance'): User
    {
        $this->seed(AdminPermissionSeeder::class);
        $u = User::factory()->create(['role' => 'admin']);
        $u->assignRole($role);

        return $u;
    }

    #[TestWith(['/api/admin/finance'])]
    #[TestWith(['/api/admin/finance/ledgers/advertiser'])]
    #[TestWith(['/api/admin/finance/ledgers/publisher/export'])]
    #[TestWith(['/api/admin/finance/payouts'])]
    #[TestWith(['/api/admin/finance/payouts/export'])]
    #[TestWith(['/api/admin/finance/payouts/1'])]
    #[TestWith(['/api/admin/finance/options?kind=publisher'])]
    public function test_guests_are_rejected(string $url): void
    {
        $this->getJson($url)->assertUnauthorized();
    }

    #[TestWith(['support'])]
    #[TestWith(['ad-ops'])]
    public function test_non_finance_roles_cannot_read_or_export_money(string $role): void
    {
        $this->actingAs($this->admin($role), 'sanctum');
        foreach (['', '/ledgers/advertiser', '/ledgers/publisher/export', '/payouts', '/payouts/export', '/options?kind=publisher'] as $path) {
            $this->getJson('/api/admin/finance'.$path)->assertForbidden();
        }
    }

    public function test_advertiser_cannot_access_finance_even_with_a_direct_permission(): void
    {
        $this->seed(AdminPermissionSeeder::class);
        $u = User::factory()->create(['role' => 'advertiser']);
        $u->givePermissionTo('admin.finance.view');
        $this->actingAs($u, 'sanctum')->getJson('/api/admin/finance')->assertForbidden();
    }

    public function test_overview_uses_validation_and_actual_payment_dates_and_compares_exact_periods(): void
    {
        ValidatedImpression::factory()->create(['validated_at' => '2026-10-30 01:00:00', 'billable_micros' => 2000, 'publisher_earning_micros' => 1400, 'platform_fee_micros' => 600]);
        ValidatedImpression::factory()->create(['validated_at' => '2026-10-29 13:00:00', 'billable_micros' => 1000, 'publisher_earning_micros' => 700, 'platform_fee_micros' => 300]);
        ValidatedImpression::factory()->create(['validated_at' => '2026-10-30 12:00:01', 'billable_micros' => 9000]);
        Payout::factory()->create(['status' => 'paid', 'created_at' => '2026-09-01', 'paid_at' => '2026-10-30 00:30:00', 'amount_micros' => 60000000]);
        Payout::factory()->create(['status' => 'pending', 'paid_at' => '2026-10-30', 'amount_micros' => 90000000]);
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/finance?period=today&compare=1')
            ->assertOk()->assertJsonPath('data.metrics.spend_micros', 2000)->assertJsonPath('data.metrics.earnings_micros', 1400)
            ->assertJsonPath('data.metrics.revenue_micros', 600)->assertJsonPath('data.metrics.impressions', 1)->assertJsonPath('data.metrics.paid_micros', 60000000)
            ->assertJsonPath('data.points.0.spend_micros', 2000)->assertJsonPath('data.points.0.previous_spend_micros', 1000)
            ->assertJsonPath('data.points.0.previous_from', '2026-10-29T12:00:00.000000Z');
    }

    public function test_interval_totals_and_zero_days_preserve_the_period_total(): void
    {
        ValidatedImpression::factory()->create(['validated_at' => '2026-10-01 01:00', 'billable_micros' => 1000]);
        ValidatedImpression::factory()->create(['validated_at' => '2026-10-30 01:00', 'billable_micros' => 2000]);
        $this->actingAs($this->admin(), 'sanctum');
        $day = $this->getJson('/api/admin/finance')->assertOk()->assertJsonCount(30, 'data.points')->assertJsonPath('data.points.1.spend_micros', 0);
        $week = $this->getJson('/api/admin/finance?interval=week')->assertOk();
        $month = $this->getJson('/api/admin/finance?interval=month')->assertOk()->assertJsonCount(1, 'data.points')->assertJsonPath('data.points.0.spend_micros', 3000);
        $this->assertSame(3000, array_sum(array_column($day->json('data.points'), 'spend_micros')));
        $this->assertSame(3000, array_sum(array_column($week->json('data.points'), 'spend_micros')));
    }

    public function test_current_balances_keep_held_earnings_and_negative_publishers_separate(): void
    {
        $good = PublisherAccount::factory()->create();
        $bad = PublisherAccount::factory()->create();
        $server = Server::factory()->create(['publisher_account_id' => $good->id, 'status' => 'suspended']);
        $server->delete();
        PublisherLedgerEntry::factory()->create(['publisher_account_id' => $good->id, 'type' => 'payable', 'amount_micros' => 100000000]);
        PublisherLedgerEntry::factory()->create(['publisher_account_id' => $good->id, 'server_id' => $server->id, 'type' => 'pending_earning', 'amount_micros' => 12000000]);
        Payout::factory()->create(['publisher_account_id' => $good->id, 'amount_micros' => 30000000]);
        Payout::factory()->create(['publisher_account_id' => $good->id, 'status' => 'failed', 'amount_micros' => 90000000]);
        PublisherLedgerEntry::factory()->create(['publisher_account_id' => $bad->id, 'type' => 'payable', 'amount_micros' => 10000000]);
        Payout::factory()->create(['publisher_account_id' => $bad->id, 'status' => 'paid', 'amount_micros' => 30000000]);
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/finance?period=yesterday')->assertOk()
            ->assertJsonPath('data.balances.available_micros', 70000000)->assertJsonPath('data.balances.inconsistent_count', 1)
            ->assertJsonPath('data.balances.inconsistencies.0.available_micros', -20000000)
            ->assertJsonPath('data.balances.held_micros', 12000000)->assertJsonPath('data.balances.reserved_micros', 30000000);
    }

    public function test_ledgers_keep_settled_earnings_in_earned_totals_and_include_deleted_identity(): void
    {
        $p = PublisherAccount::factory()->create();
        $p->user->delete();
        PublisherLedgerEntry::factory()->create(['publisher_account_id' => $p->id, 'type' => 'pending_earning', 'amount_micros' => 1400, 'created_at' => '2026-09-29']);
        $released = PublisherLedgerEntry::factory()->create(['publisher_account_id' => $p->id, 'type' => 'payable', 'amount_micros' => 2800, 'created_at' => '2026-09-30', 'settled_at' => '2026-10-05', 'reference_type' => 'raw_impression_event', 'reference_id' => 123]);
        PublisherLedgerEntry::factory()->create(['type' => 'payable', 'amount_micros' => 900000]);
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/finance/ledgers/publisher?account='.$p->user_id.'&from=2026-09-01&to=2026-09-30')
            ->assertOk()->assertJsonPath('summary.earned_micros', 4200)->assertJsonPath('summary.released_micros', 2800)->assertJsonPath('summary.awaiting_micros', 1400)
            ->assertJsonPath('data.0.account.deleted', true)->assertJsonPath('data.0.settled_at', '2026-10-05T00:00:00.000000Z');
        $this->getJson('/api/admin/finance/ledgers/publisher?search=EVT-123')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $released->id);
        $this->getJson('/api/admin/finance/ledgers/publisher/'.$released->id)->assertNotFound();
    }

    public function test_advertiser_summary_includes_unknown_types_without_offering_mutation(): void
    {
        $a = AdvertiserAccount::factory()->create();
        foreach ([['deposit', 10000000], ['spend', -2000], ['spend', 1000], ['legacy_credit', 500]] as [$type, $amount]) {
            AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $a->id, 'type' => $type, 'amount_micros' => $amount]);
        }
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/finance/ledgers/advertiser?account='.$a->user_id)->assertOk()
            ->assertJsonPath('summary.deposits_micros', 10000000)->assertJsonPath('summary.spend_micros', 2000)->assertJsonPath('summary.net_micros', 9999500)->assertJsonCount(4, 'data');
        $this->postJson('/api/admin/finance/payouts', [])->assertStatus(405);
    }

    public function test_payout_paid_date_filter_and_totals_do_not_use_created_date(): void
    {
        $p = Payout::factory()->create(['status' => 'paid', 'created_at' => '2026-09-01', 'paid_at' => '2026-10-15', 'amount_micros' => 60000000]);
        Payout::factory()->create(['created_at' => '2026-10-15']);
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/finance/payouts?date_field=paid&from=2026-10-01&to=2026-10-30&status=paid')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $p->id)->assertJsonPath('totals.paid.amount_micros', 60000000)->assertJsonPath('totals.pending.count', 0);
    }

    public function test_csv_exports_all_filtered_rows_with_precise_signed_amounts_and_safe_text(): void
    {
        $a = AdvertiserAccount::factory()->create();
        $a->user->update(['name' => '=HYPERLINK("https://example.test")']);
        AdvertiserLedgerEntry::factory()->count(12)->create(['advertiser_account_id' => $a->id, 'type' => 'spend', 'amount_micros' => -1400]);
        AdvertiserLedgerEntry::factory()->create(['type' => 'spend', 'amount_micros' => -9999]);
        $r = $this->actingAs($this->admin(), 'sanctum')->get('/api/admin/finance/ledgers/advertiser/export?account='.$a->user_id.'&per_page=10')->assertOk()->assertDownload();
        $csv = $r->streamedContent();
        $this->assertSame(12, substr_count($csv, '-0.001400'));
        $this->assertStringNotContainsString('-0.009999', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    #[TestWith(['?period=custom&from=2020-01-01&to=2026-10-30'])]
    #[TestWith(['?interval=arbitrary_sql'])]
    #[TestWith(['?period=custom&from=2026-10-31&to=2026-11-01'])]
    public function test_invalid_reporting_controls_are_rejected(string $query): void
    {
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/finance'.$query)->assertUnprocessable();
    }
}
