<?php

namespace Tests\Feature;

use App\Models\AdCreative;
use App\Models\AdDelivery;
use App\Models\Campaign;
use App\Models\Payout;
use App\Models\RawImpressionEvent;
use App\Models\Server;
use App\Models\ServerHeartbeat;
use App\Models\User;
use App\Models\ValidatedImpression;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 10:42:00', 'UTC'));
    }

    public static function protectedEndpoints(): array
    {
        return [['/api/admin/dashboard'], ['/api/admin/dashboard/performance']];
    }

    public function test_ad_operations_receives_impressions_without_financial_data(): void
    {
        $this->seed(AdminPermissionSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('ad-ops');

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/dashboard')
            ->assertOk()->assertJsonMissingPath('data.pending_payouts')->assertJsonMissingPath('data.pending_payout_amounts');
        $this->getJson('/api/admin/dashboard/performance')
            ->assertOk()->assertJsonPath('data.metrics.validated_impressions', 0)
            ->assertJsonMissingPath('data.metrics.spend_micros')->assertJsonMissingPath('data.chart.0.earnings_micros');
    }

    public function test_support_cannot_access_marketplace_analytics(): void
    {
        $this->seed(AdminPermissionSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('support');

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/dashboard')->assertForbidden();
        $this->getJson('/api/admin/dashboard/performance')->assertForbidden();
    }

    #[DataProvider('protectedEndpoints')]
    public function test_guests_cannot_read_admin_data(string $endpoint): void
    {
        $this->getJson($endpoint)->assertUnauthorized();
    }

    public static function nonAdminRequests(): array
    {
        return [
            ['advertiser', '/api/admin/dashboard'], ['publisher', '/api/admin/dashboard'],
            ['advertiser', '/api/admin/dashboard/performance'], ['publisher', '/api/admin/dashboard/performance'],
        ];
    }

    #[DataProvider('nonAdminRequests')]
    public function test_non_admins_are_forbidden_from_reading_marketplace_data(string $role, string $endpoint): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user, 'sanctum')->getJson($endpoint)
            ->assertForbidden()->assertJsonPath('message', 'This endpoint is available to admins only.');
    }

    #[DataProvider('protectedEndpoints')]
    public function test_suspended_admins_are_forbidden(string $endpoint): void
    {
        $user = User::factory()->create(['role' => 'admin', 'status' => 'suspended']);

        $this->actingAs($user, 'sanctum')->getJson($endpoint)->assertForbidden()
            ->assertJsonPath('message', 'This account has been suspended.');
    }

    #[DataProvider('protectedEndpoints')]
    public function test_unverified_admins_must_verify_their_email(string $endpoint): void
    {
        $user = User::factory()->unverified()->create(['role' => 'admin']);

        $this->actingAs($user, 'sanctum')->getJson($endpoint)->assertForbidden()
            ->assertJsonPath('message', 'Your email address is not verified.');
    }

    public function test_snapshot_counts_current_records_and_deduplicates_unhealthy_servers(): void
    {
        $this->seed(AdminPermissionSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('admin-owner');
        $campaign = Campaign::factory()->create(['status' => 'active']);
        Campaign::factory()->create(['status' => 'paused']);
        $creative = AdCreative::factory()->create(['campaign_id' => $campaign->id, 'status' => 'pending_review', 'created_at' => now()->subHours(3)]);
        AdCreative::factory()->create(['campaign_id' => $campaign->id, 'status' => 'approved']);
        $deleted = AdCreative::factory()->create(['campaign_id' => $campaign->id, 'status' => 'pending_review']);
        $deleted->delete();
        $online = Server::factory()->create();
        ServerHeartbeat::factory()->create(['server_id' => $online->id, 'received_at' => now()->subSeconds(299)]);
        $stale = Server::factory()->create();
        ServerHeartbeat::factory()->create(['server_id' => $stale->id, 'received_at' => now()->subSeconds(300)]);
        $overlap = Server::factory()->create();
        $delivery = AdDelivery::factory()->create(['server_id' => $overlap->id, 'campaign_id' => $campaign->id, 'creative_id' => $creative->id]);
        $sample = RawImpressionEvent::factory()->create(['ad_delivery_id' => $delivery->id, 'player_session_id' => null, 'server_id' => $overlap->id, 'validation_status' => 'invalid', 'validated_at' => now()->subHour()]);
        RawImpressionEvent::factory()->count(10)->create(['ad_delivery_id' => $sample->ad_delivery_id, 'server_id' => $overlap->id, 'player_session_id' => null, 'validation_status' => 'invalid', 'validated_at' => now()->subHour()]);
        RawImpressionEvent::factory()->count(89)->create(['ad_delivery_id' => $sample->ad_delivery_id, 'server_id' => $overlap->id, 'player_session_id' => null, 'validation_status' => 'valid', 'validated_at' => now()->subHour()]);
        Server::factory()->create(['status' => 'suspended']);
        $deletedServer = Server::factory()->create();
        $deletedServer->delete();
        Payout::factory()->create(['status' => 'pending', 'currency' => 'USD', 'amount_micros' => 12000000]);
        Payout::factory()->create(['status' => 'pending', 'currency' => 'EUR', 'amount_micros' => 5000000]);
        Payout::factory()->create(['status' => 'processing', 'amount_micros' => 90000000]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/dashboard?from=2000-01-01')
            ->assertOk()
            ->assertJsonPath('data.active_campaigns', 1)
            ->assertJsonPath('data.pending_creatives', 1)
            ->assertJsonPath('data.active_servers', 3)
            ->assertJsonPath('data.online_servers', 1)
            ->assertJsonPath('data.offline_servers', 2)
            ->assertJsonPath('data.high_invalid_rate_servers', 1)
            ->assertJsonPath('data.unhealthy_servers', 2)
            ->assertJsonPath('data.pending_payouts', 2)
            ->assertJsonPath('data.pending_payout_amounts.0.currency', 'EUR')
            ->assertJsonPath('data.pending_payout_amounts.0.amount_micros', 5000000)
            ->assertJsonPath('data.pending_payout_amounts.1.amount_micros', 12000000);
    }

    public static function healthSamples(): array
    {
        return ['below sample minimum' => [99, 99, 0], 'exactly ten percent' => [100, 10, 0], 'above ten percent' => [100, 11, 1]];
    }

    #[DataProvider('healthSamples')]
    public function test_invalid_rate_health_threshold_requires_sufficient_recent_processed_impressions(int $total, int $invalid, int $expected): void
    {
        $this->seed(AdminPermissionSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('admin-owner');
        $server = Server::factory()->create();
        ServerHeartbeat::factory()->create(['server_id' => $server->id, 'received_at' => now()->subMinute()]);
        $delivery = AdDelivery::factory()->create(['server_id' => $server->id]);
        $base = RawImpressionEvent::factory()->create(['ad_delivery_id' => $delivery->id, 'player_session_id' => null, 'server_id' => $server->id, 'validation_status' => 'invalid', 'validated_at' => now()->subHours(25)]);
        $attributes = ['ad_delivery_id' => $base->ad_delivery_id, 'server_id' => $server->id, 'player_session_id' => null, 'validated_at' => now()->subHour()];
        RawImpressionEvent::factory()->count($invalid)->create([...$attributes, 'validation_status' => 'invalid']);
        if ($total > $invalid) {
            RawImpressionEvent::factory()->count($total - $invalid)->create([...$attributes, 'validation_status' => 'valid']);
        }
        RawImpressionEvent::factory()->count(2)->create([...$attributes, 'validation_status' => 'pending', 'validated_at' => null]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/dashboard')
            ->assertOk()->assertJsonPath('data.high_invalid_rate_servers', $expected)
            ->assertJsonPath('data.unhealthy_servers', $expected);
    }

    public function test_performance_uses_validation_time_for_finance_and_received_time_for_reports(): void
    {
        $this->seed(AdminPermissionSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('admin-owner');
        $raw = RawImpressionEvent::factory()->create([
            'received_at' => '2026-10-23 23:59:00', 'validation_status' => 'valid', 'validated_at' => '2026-10-24 00:00:00',
        ]);
        ValidatedImpression::factory()->create([
            'raw_impression_event_id' => $raw->id, 'validated_at' => '2026-10-24 00:00:00',
            'billable_micros' => 2000, 'publisher_earning_micros' => 1600, 'platform_fee_micros' => 400,
        ]);
        RawImpressionEvent::factory()->create(['received_at' => '2026-10-24 02:10:00', 'validation_status' => 'invalid', 'validated_at' => '2026-10-24 02:15:00']);
        RawImpressionEvent::factory()->create(['received_at' => '2026-10-24 03:00:00', 'validation_status' => 'pending']);
        foreach (['2026-10-23 23:59:59', '2026-10-25 00:00:00', '2026-10-24 11:00:00'] as $timestamp) {
            ValidatedImpression::factory()->create([
                'validated_at' => $timestamp,
                'raw_impression_event_id' => RawImpressionEvent::factory()->state(['received_at' => '2026-10-20 00:00:00', 'validation_status' => 'valid']),
            ]);
        }

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/dashboard/performance')
            ->assertOk()->assertJsonPath('data.period.timezone', 'UTC')
            ->assertJsonPath('data.metrics.validated_impressions', 1)
            ->assertJsonPath('data.metrics.reported_impressions', 2)
            ->assertJsonPath('data.metrics.invalid_impressions', 1)
            ->assertJsonPath('data.metrics.invalid_rate', 50)
            ->assertJsonPath('data.metrics.pending_impressions', 1)
            ->assertJsonPath('data.metrics.spend_micros', 2000)
            ->assertJsonPath('data.metrics.earnings_micros', 1600)
            ->assertJsonPath('data.metrics.fee_micros', 400)
            ->assertJsonPath('data.chart.0.validated', 1)
            ->assertJsonPath('data.chart.1.validated', 0)
            ->assertJsonPath('data.chart.2.invalid', 1)
            ->assertJsonCount(11, 'data.chart');
    }

    public function test_custom_period_excludes_next_midnight_and_zero_fills_daily_buckets(): void
    {
        $this->seed(AdminPermissionSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('admin-owner');
        ValidatedImpression::factory()->create(['validated_at' => '2026-10-22 23:59:59', 'billable_micros' => 3000]);
        ValidatedImpression::factory()->create(['validated_at' => '2026-10-23 00:00:00', 'billable_micros' => 9000]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/dashboard/performance?period=custom&from=2026-10-21&to=2026-10-22')
            ->assertOk()->assertJsonPath('data.period.interval', 'day')
            ->assertJsonPath('data.metrics.spend_micros', 3000)
            ->assertJsonCount(2, 'data.chart')
            ->assertJsonPath('data.chart.0.validated', 0)->assertJsonPath('data.chart.1.validated', 1);
    }

    public function test_empty_marketplace_returns_real_zero_totals(): void
    {
        $this->seed(AdminPermissionSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('admin-owner');

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/dashboard/performance?period=yesterday')
            ->assertOk()->assertJsonPath('data.metrics.spend_micros', 0)
            ->assertJsonPath('data.metrics.invalid_rate', 0)->assertJsonCount(24, 'data.chart');
    }

    public static function invalidRanges(): array
    {
        return [
            'unknown period' => ['period=unsupported', 'period'],
            'missing custom dates' => ['period=custom', 'from'],
            'reversed range' => ['period=custom&from=2026-10-23&to=2026-10-22', 'to'],
            'future range' => ['period=custom&from=2026-10-25&to=2026-10-26', 'from'],
            'range too long' => ['period=custom&from=2020-01-01&to=2026-10-24', 'to'],
            'malformed date' => ['period=custom&from=broken&to=2026-10-24', 'from'],
        ];
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_date_ranges_return_validation_errors(string $query, string $field): void
    {
        $this->seed(AdminPermissionSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('admin-owner');

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/dashboard/performance?'.$query)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
}
