<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\PublisherAccount;
use App\Models\PublisherLedgerEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PublisherPayoutsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'UTC'));
    }

    public function test_payout_summary_uses_owned_currency_scoped_records_and_does_not_move_balances(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $payable = PublisherLedgerEntry::factory()->create(['publisher_account_id' => $publisher->id, 'server_id' => null, 'type' => 'payable', 'amount_micros' => 120000000]);
        foreach ([['2026-10-01 00:00:00', 15000000], ['2026-09-30 23:59:59', 100000000], ['2026-10-16 12:00:01', 20000000]] as [$date, $amount]) {
            PublisherLedgerEntry::factory()->create(['publisher_account_id' => $publisher->id, 'server_id' => null, 'type' => 'pending_earning', 'created_at' => $date, 'amount_micros' => $amount]);
        }
        PublisherLedgerEntry::factory()->create(['publisher_account_id' => $publisher->id, 'type' => 'payable', 'currency' => 'EUR', 'amount_micros' => 999000000]);
        PublisherLedgerEntry::factory()->create(['publisher_account_id' => $publisher->id, 'type' => 'pending_earning', 'currency' => 'EUR', 'amount_micros' => 999000000]);
        PublisherLedgerEntry::factory()->create(['type' => 'payable', 'amount_micros' => 999000000]);
        foreach ([['paid', 20000000], ['pending', 10000000], ['processing', 5000000], ['failed', 4000000], ['cancelled', 3000000]] as [$status, $amount]) {
            Payout::factory()->create(['publisher_account_id' => $publisher->id, 'status' => $status, 'amount_micros' => $amount]);
        }
        Payout::factory()->create(['publisher_account_id' => $publisher->id, 'currency' => 'EUR', 'status' => 'paid', 'amount_micros' => 999000000]);
        Payout::factory()->create(['status' => 'paid', 'amount_micros' => 999000000]);

        $response = $this->actingAs($publisher->user)->getJson('/api/publisher/payouts');

        $response->assertOk()->assertJsonPath('data.metrics.available_payout_micros', 85000000)
            ->assertJsonPath('data.metrics.pending_earnings_micros', 15000000)
            ->assertJsonPath('data.metrics.lifetime_paid_micros', 20000000)
            ->assertJsonPath('data.policy.threshold_met', true)->assertJsonPath('data.policy.minimum_payout_micros', 50000000)
            ->assertJsonCount(5, 'data.history')->assertJsonPath('data.pagination.total', 5)
            ->assertJsonPath('data.period.timezone', 'UTC');
        $this->assertSame(120000000, $payable->fresh()->amount_micros);
        $this->assertDatabaseCount('payouts', 7);
        $this->assertDatabaseCount('publisher_ledger_entries', 7);
    }

    public function test_empty_state_reports_zero_balances_and_disabled_stripe_setup(): void
    {
        $publisher = PublisherAccount::factory()->create();

        $this->actingAs($publisher->user)->getJson('/api/publisher/payouts')->assertOk()
            ->assertJsonPath('data.metrics', ['available_payout_micros' => 0, 'pending_earnings_micros' => 0, 'lifetime_paid_micros' => 0])
            ->assertJsonCount(0, 'data.history')->assertJsonPath('data.filter_years', [])
            ->assertJsonPath('data.policy.threshold_met', false)
            ->assertJsonPath('data.payout_method.status', 'not_connected')
            ->assertJsonPath('data.payout_method.account', null)
            ->assertJsonPath('data.payout_method.integration_available', false);
    }

    public function test_threshold_is_configurable_and_inclusive(): void
    {
        config(['publisher.minimum_payout_micros' => 25000000]);
        $publisher = PublisherAccount::factory()->create();
        PublisherLedgerEntry::factory()->create(['publisher_account_id' => $publisher->id, 'type' => 'payable', 'amount_micros' => 25000000]);

        $this->actingAs($publisher->user)->getJson('/api/publisher/payouts')->assertOk()
            ->assertJsonPath('data.policy.minimum_payout_micros', 25000000)->assertJsonPath('data.policy.threshold_met', true);
    }

    #[TestWith(['2026-10-01 00:00:00', '2026-10-05', '2026-10-05'])]
    #[TestWith(['2026-10-05 23:59:59', '2026-10-05', '2026-10-05'])]
    #[TestWith(['2026-10-06 00:00:00', '2026-11-05', '2026-11-05'])]
    #[TestWith(['2026-12-31 23:59:59', '2027-01-05', '2027-01-05'])]
    #[TestWith(['2028-02-29 23:59:59', '2028-03-05', '2028-03-05'])]
    public function test_estimated_windows_apply_the_month_end_hold_in_utc(string $now, string $start, string $end): void
    {
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
        $publisher = PublisherAccount::factory()->create();

        $this->actingAs($publisher->user)->getJson('/api/publisher/payouts')->assertOk()
            ->assertJsonPath('data.policy.estimated_window_start', $start)->assertJsonPath('data.policy.estimated_window_end', $end);
    }

    public function test_history_filters_use_period_year_and_do_not_change_summary_totals(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $paid = Payout::factory()->create(['publisher_account_id' => $publisher->id, 'period_start' => '2025-12-01', 'period_end' => '2025-12-31', 'created_at' => '2026-01-01', 'status' => 'paid', 'amount_micros' => 60000000, 'paid_at' => '2026-01-05']);
        Payout::factory()->create(['publisher_account_id' => $publisher->id, 'period_start' => '2026-09-01', 'status' => 'pending']);

        $this->actingAs($publisher->user)->getJson('/api/publisher/payouts?year=2025&status=paid')->assertOk()
            ->assertJsonCount(1, 'data.history')->assertJsonPath('data.history.0.id', $paid->id)
            ->assertJsonPath('data.history.0.paid_at', '2026-01-05T00:00:00.000000Z')
            ->assertJsonPath('data.metrics.lifetime_paid_micros', 60000000)
            ->assertJsonPath('data.filter_years', [2026, 2025]);
    }

    public function test_history_is_paginated_with_stable_newest_first_order(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $payouts = Payout::factory()->count(13)->create(['publisher_account_id' => $publisher->id, 'created_at' => now()]);

        $this->actingAs($publisher->user)->getJson('/api/publisher/payouts?page=2')->assertOk()
            ->assertJsonCount(1, 'data.history')->assertJsonPath('data.history.0.id', $payouts->first()->id)
            ->assertJsonPath('data.pagination.total', 13)->assertJsonPath('data.pagination.last_page', 2);
    }

    public function test_unpaid_payouts_have_no_paid_date_even_if_a_stale_date_is_present(): void
    {
        $publisher = PublisherAccount::factory()->create();
        Payout::factory()->create(['publisher_account_id' => $publisher->id, 'status' => 'failed', 'paid_at' => now()]);

        $this->actingAs($publisher->user)->getJson('/api/publisher/payouts')->assertOk()->assertJsonPath('data.history.0.paid_at', null);
    }

    #[TestWith(['page=0'])]
    #[TestWith(['year=invalid'])]
    #[TestWith(['status=invalid'])]
    public function test_invalid_filters_return_422(string $query): void
    {
        $publisher = PublisherAccount::factory()->create();

        $this->actingAs($publisher->user)->getJson('/api/publisher/payouts?'.$query)->assertUnprocessable();
    }

    public function test_guest_receives_401(): void
    {
        $this->getJson('/api/publisher/payouts')->assertUnauthorized();
    }

    #[TestWith(['advertiser'])]
    #[TestWith(['admin'])]
    public function test_other_roles_receive_403(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->getJson('/api/publisher/payouts')->assertForbidden();
    }

    public function test_missing_publisher_account_receives_409(): void
    {
        $user = User::factory()->create(['role' => 'publisher']);

        $this->actingAs($user)->getJson('/api/publisher/payouts')->assertConflict();
    }

    public function test_suspended_publisher_receives_403(): void
    {
        $publisher = PublisherAccount::factory()->create(['status' => 'suspended']);

        $this->actingAs($publisher->user)->getJson('/api/publisher/payouts')->assertForbidden();
    }
}
