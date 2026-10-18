<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\PublisherAccount;
use App\Models\PublisherLedgerEntry;
use App\Models\Server;
use App\Models\User;
use App\Models\ValidatedImpression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PublisherDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_aggregates_net_earnings_and_impressions_with_utc_boundaries(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(0, 30));
        $publisher = PublisherAccount::factory()->create();
        $server = Server::factory()->create(['publisher_account_id' => $publisher->id]);
        foreach ([
            ['2026-10-31 00:00:00', 3500],
            ['2026-10-30 23:59:59', 7000],
            ['2026-10-01 00:00:00', 10500],
            ['2026-09-30 23:59:59', 14000],
            ['2026-10-31 00:30:01', 21000],
        ] as [$date, $earnings]) {
            ValidatedImpression::factory()->create([
                'publisher_account_id' => $publisher->id,
                'server_id' => $server->id,
                'validated_at' => $date,
                'publisher_earning_micros' => $earnings,
                'billable_micros' => 30000,
            ]);
        }
        ValidatedImpression::factory()->create(['publisher_earning_micros' => 99000]);
        Server::factory()->create();

        $response = $this->actingAs($publisher->user)->getJson('/api/publisher/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.account.id', $publisher->id)
            ->assertJsonPath('data.server_count', 1)
            ->assertJsonPath('data.metrics.earnings_today_micros', 3500)
            ->assertJsonPath('data.metrics.earnings_month_micros', 21000)
            ->assertJsonPath('data.metrics.impressions_today', 1)
            ->assertJsonPath('data.metrics.impressions_month', 3)
            ->assertJsonPath('data.metrics.effective_cpm_micros', 7000000)
            ->assertJsonPath('data.period.timezone', 'UTC')
            ->assertJsonCount(30, 'data.performance_chart')
            ->assertJsonPath('data.performance_chart.0.date', '2026-10-02')
            ->assertJsonPath('data.performance_chart.0.earnings_micros', 0)
            ->assertJsonPath('data.performance_chart.0.impressions', 0)
            ->assertJsonPath('data.performance_chart.28.date', '2026-10-30')
            ->assertJsonPath('data.performance_chart.28.earnings_micros', 7000)
            ->assertJsonPath('data.performance_chart.29.date', '2026-10-31')
            ->assertJsonPath('data.performance_chart.29.earnings_micros', 3500)
            ->assertJsonPath('data.generated_at', '2026-10-31T00:30:00.000000Z');
    }

    public function test_chart_crosses_month_boundary_without_including_previous_month_in_monthly_metrics(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(12, 0));
        $publisher = PublisherAccount::factory()->create();
        ValidatedImpression::factory()->create([
            'publisher_account_id' => $publisher->id,
            'validated_at' => '2026-10-31 23:59:59',
            'publisher_earning_micros' => 700,
        ]);

        $response = $this->actingAs($publisher->user)->getJson('/api/publisher/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.metrics.earnings_month_micros', 0)
            ->assertJsonPath('data.metrics.impressions_month', 0)
            ->assertJsonPath('data.metrics.effective_cpm_micros', 0)
            ->assertJsonPath('data.performance_chart.28.earnings_micros', 700)
            ->assertJsonPath('data.performance_chart.28.impressions', 1);
    }

    public function test_available_payout_excludes_unsettled_earnings_and_committed_payouts(): void
    {
        $publisher = PublisherAccount::factory()->create();
        foreach ([['payable', 100000000], ['payable', -10000000], ['pending_earning', 90000000], ['adjustment', 80000000]] as [$type, $amount]) {
            PublisherLedgerEntry::factory()->create([
                'publisher_account_id' => $publisher->id,
                'server_id' => null,
                'type' => $type,
                'amount_micros' => $amount,
            ]);
        }
        PublisherLedgerEntry::factory()->create(['type' => 'payable', 'amount_micros' => 999000000]);
        PublisherLedgerEntry::factory()->create(['publisher_account_id' => $publisher->id, 'type' => 'payable', 'currency' => 'EUR', 'amount_micros' => 999000000]);
        foreach (['paid', 'processing', 'pending', 'failed', 'cancelled'] as $status) {
            Payout::factory()->create(['publisher_account_id' => $publisher->id, 'status' => $status, 'amount_micros' => 10000000]);
        }
        Payout::factory()->create(['status' => 'paid', 'amount_micros' => 999000000]);
        Payout::factory()->create(['publisher_account_id' => $publisher->id, 'currency' => 'EUR', 'status' => 'paid', 'amount_micros' => 999000000]);

        $response = $this->actingAs($publisher->user)->getJson('/api/publisher/dashboard');

        $response->assertOk()->assertJsonPath('data.metrics.available_payout_micros', 60000000);
    }

    public function test_available_payout_never_shows_a_negative_balance(): void
    {
        $publisher = PublisherAccount::factory()->create();
        Payout::factory()->create(['publisher_account_id' => $publisher->id, 'status' => 'paid']);

        $this->actingAs($publisher->user)->getJson('/api/publisher/dashboard')
            ->assertOk()->assertJsonPath('data.metrics.available_payout_micros', 0);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_zero_activity_reports_server_count_for_the_correct_empty_state(bool $hasServer): void
    {
        $publisher = PublisherAccount::factory()->create();
        if ($hasServer) {
            Server::factory()->create(['publisher_account_id' => $publisher->id]);
        }

        $response = $this->actingAs($publisher->user)->getJson('/api/publisher/dashboard');

        $response->assertOk()->assertJsonPath('data.server_count', $hasServer ? 1 : 0)
            ->assertJsonPath('data.metrics', [
                'earnings_today_micros' => 0,
                'earnings_month_micros' => 0,
                'impressions_today' => 0,
                'impressions_month' => 0,
                'effective_cpm_micros' => 0,
                'available_payout_micros' => 0,
            ])->assertJsonCount(30, 'data.performance_chart');
    }

    #[TestWith(['advertiser'])]
    #[TestWith(['admin'])]
    public function test_other_roles_receive_403(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->getJson('/api/publisher/dashboard')
            ->assertForbidden()->assertJsonPath('message', 'This endpoint is available to publishers only.');
    }

    public function test_guest_receives_401(): void
    {
        $this->getJson('/api/publisher/dashboard')->assertUnauthorized();
    }

    public function test_missing_publisher_account_receives_409(): void
    {
        $user = User::factory()->create(['role' => 'publisher']);

        $this->actingAs($user)->getJson('/api/publisher/dashboard')
            ->assertConflict()->assertJsonPath('message', 'Publisher account is not set up.');
    }

    public function test_suspended_user_receives_403(): void
    {
        $user = User::factory()->create(['role' => 'publisher', 'status' => 'suspended']);

        $this->actingAs($user)->getJson('/api/publisher/dashboard')
            ->assertForbidden()->assertJsonPath('message', 'This account has been suspended.');
    }

    public function test_suspended_publisher_account_receives_403(): void
    {
        $publisher = PublisherAccount::factory()->create(['status' => 'suspended']);

        $this->actingAs($publisher->user)->getJson('/api/publisher/dashboard')
            ->assertForbidden()->assertJsonPath('message', 'This publisher account has been suspended.');
    }
}
