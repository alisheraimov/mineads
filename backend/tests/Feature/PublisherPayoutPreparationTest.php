<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\PublisherAccount;
use App\Models\PublisherLedgerEntry;
use App\Services\PublisherBalanceService;
use App\Services\PublisherPayoutService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class PublisherPayoutPreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 00:10:00', 'UTC'));
    }

    public function test_settles_previous_month_and_reserves_a_pending_payout_at_the_minimum(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $earning = $this->earning($publisher, 50000000, '2026-09-30 23:59:59', [
            'reference_type' => 'raw_impression_event', 'reference_id' => 123,
        ]);
        $currentMonth = $this->earning($publisher, 80000000, '2026-10-01 00:00:00');

        $this->artisan('publishers:prepare-payouts')
            ->expectsOutput('Settled 1 ledger entries; created 1 pending payouts.')->assertSuccessful();

        $this->assertDatabaseHas('publisher_ledger_entries', [
            'id' => $earning->id, 'type' => 'payable', 'amount_micros' => 50000000,
            'created_at' => '2026-09-30 23:59:59', 'settled_at' => '2026-10-05 00:10:00',
            'reference_type' => 'raw_impression_event', 'reference_id' => 123,
        ]);
        $this->assertDatabaseHas('publisher_ledger_entries', ['id' => $currentMonth->id, 'type' => 'pending_earning', 'settled_at' => null]);
        $this->assertDatabaseCount('publisher_ledger_entries', 2);
        $this->assertDatabaseCount('payouts', 1);
        $payout = Payout::sole();
        $this->assertSame($publisher->id, $payout->publisher_account_id);
        $this->assertSame(50000000, $payout->amount_micros);
        $this->assertSame('pending', $payout->status);
        $this->assertSame('USD', $payout->currency);
        $this->assertSame('2026-10-01', $payout->cycle_month);
        $this->assertSame('2026-09-01 00:00:00', $payout->period_start->toDateTimeString());
        $this->assertSame('2026-09-30 23:59:59', $payout->period_end->toDateTimeString());
        $this->assertNull($payout->paid_at);
        $this->assertNull($payout->provider_payout_id);
        $this->assertSame(0, app(PublisherBalanceService::class)->availableMicros($publisher));
    }

    public function test_previous_month_remains_on_hold_until_the_fifth(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 23:59:59', 'UTC'));
        $publisher = PublisherAccount::factory()->create();
        $earning = $this->earning($publisher, 60000000, '2026-09-01 00:00:00');

        $this->artisan('publishers:prepare-payouts')->assertSuccessful();

        $this->assertDatabaseHas('publisher_ledger_entries', ['id' => $earning->id, 'type' => 'pending_earning', 'settled_at' => null]);
        $this->assertDatabaseCount('payouts', 0);
    }

    #[TestWith(['2026-10-05 00:00:00'])]
    #[TestWith(['2026-10-19 12:00:00'])]
    #[TestWith(['2027-01-05 00:10:00', '2026-12-31 23:59:59', '2027-01-01'])]
    #[TestWith(['2028-03-05 00:10:00', '2028-02-29 23:59:59', '2028-03-01'])]
    public function test_prepares_payout_on_or_after_eligibility_including_year_and_leap_boundaries(
        string $now, string $earnedAt = '2026-09-30 23:59:59', string $cycleMonth = '2026-10-01'
    ): void {
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
        $publisher = PublisherAccount::factory()->create();
        $this->earning($publisher, 60000000, $earnedAt);

        $this->artisan('publishers:prepare-payouts')->assertSuccessful();

        $this->assertDatabaseHas('payouts', ['publisher_account_id' => $publisher->id, 'amount_micros' => 60000000, 'cycle_month' => $cycleMonth, 'period_end' => $earnedAt]);
    }

    public function test_missed_months_are_settled_even_before_the_current_months_fifth(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));
        $publisher = PublisherAccount::factory()->create();
        $this->earning($publisher, 30000000, '2026-07-01 00:00:00');
        $this->earning($publisher, 40000000, '2026-08-31 23:59:59');
        $onHold = $this->earning($publisher, 90000000, '2026-09-01 00:00:00');

        $this->artisan('publishers:prepare-payouts')->assertSuccessful();

        $this->assertDatabaseHas('payouts', ['publisher_account_id' => $publisher->id, 'amount_micros' => 70000000, 'cycle_month' => '2026-09-01']);
        $this->assertDatabaseHas('publisher_ledger_entries', ['id' => $onHold->id, 'type' => 'pending_earning']);
        $this->assertSame(2, PublisherLedgerEntry::where('type', 'payable')->count());
    }

    public function test_subthreshold_earnings_settle_and_carry_forward_to_the_next_month(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $september = $this->earning($publisher, 30000000, '2026-09-15 12:00:00');

        $this->artisan('publishers:prepare-payouts')->assertSuccessful();

        $this->assertDatabaseCount('payouts', 0);
        $this->assertDatabaseHas('publisher_ledger_entries', ['id' => $september->id, 'type' => 'payable']);
        $this->assertSame(30000000, app(PublisherBalanceService::class)->availableMicros($publisher));

        $this->earning($publisher, 40000000, '2026-10-15 12:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-05 00:10:00', 'UTC'));

        $this->artisan('publishers:prepare-payouts')->assertSuccessful();

        $this->assertDatabaseCount('payouts', 1);
        $this->assertDatabaseHas('payouts', ['amount_micros' => 70000000, 'cycle_month' => '2026-11-01']);
        $this->assertDatabaseHas('publisher_ledger_entries', ['id' => $september->id, 'settled_at' => '2026-10-05 00:10:00']);
    }

    public function test_repeated_runs_do_not_duplicate_settlement_or_payouts(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $earning = $this->earning($publisher, 60000000, '2026-09-01 00:00:00');
        $this->artisan('publishers:prepare-payouts')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 00:10:00', 'UTC'));

        $this->artisan('publishers:prepare-payouts')
            ->expectsOutput('Settled 0 ledger entries; created 0 pending payouts.')->assertSuccessful();

        $this->assertDatabaseCount('payouts', 1);
        $this->assertDatabaseCount('publisher_ledger_entries', 1);
        $this->assertDatabaseHas('publisher_ledger_entries', ['id' => $earning->id, 'settled_at' => '2026-10-05 00:10:00']);
    }

    #[TestWith(['failed'])]
    #[TestWith(['cancelled'])]
    public function test_failed_or_cancelled_payouts_are_not_recreated_in_the_same_cycle(string $status): void
    {
        $publisher = PublisherAccount::factory()->create();
        $this->earning($publisher, 60000000, '2026-09-01 00:00:00', ['type' => 'payable']);
        Payout::factory()->create([
            'publisher_account_id' => $publisher->id, 'cycle_month' => '2026-10-01',
            'amount_micros' => 60000000, 'status' => $status,
        ]);

        $this->artisan('publishers:prepare-payouts')->assertSuccessful();

        $this->assertDatabaseCount('payouts', 1);
        $this->assertSame(60000000, app(PublisherBalanceService::class)->availableMicros($publisher));

        $this->travelTo(CarbonImmutable::parse('2026-11-05 00:10:00', 'UTC'));
        $this->artisan('publishers:prepare-payouts')->assertSuccessful();

        $this->assertDatabaseCount('payouts', 2);
        $this->assertDatabaseHas('payouts', ['cycle_month' => '2026-11-01', 'amount_micros' => 60000000, 'status' => 'pending']);
    }

    public function test_committed_payouts_are_subtracted_and_failed_payouts_release_balance(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $this->earning($publisher, 100000000, '2026-09-01 00:00:00');
        foreach (['pending', 'processing', 'paid', 'failed', 'cancelled'] as $status) {
            Payout::factory()->create(['publisher_account_id' => $publisher->id, 'status' => $status, 'amount_micros' => 10000000]);
        }

        $this->artisan('publishers:prepare-payouts')->assertSuccessful();

        $this->assertDatabaseCount('payouts', 6);
        $this->assertDatabaseHas('payouts', ['cycle_month' => '2026-10-01', 'amount_micros' => 70000000]);
    }

    public function test_suspended_publishers_and_other_currencies_are_not_settled_or_paid(): void
    {
        $suspended = PublisherAccount::factory()->create(['status' => 'suspended']);
        $suspendedEarning = $this->earning($suspended, 90000000, '2026-09-01 00:00:00');
        $publisher = PublisherAccount::factory()->create(['currency' => 'EUR']);
        $foreignEarning = $this->earning($publisher, 90000000, '2026-09-01 00:00:00', ['currency' => 'USD']);
        $this->earning($publisher, 55000000, '2026-09-01 00:00:00', ['currency' => 'EUR']);

        $this->artisan('publishers:prepare-payouts')->assertSuccessful();

        $this->assertDatabaseHas('publisher_ledger_entries', ['id' => $suspendedEarning->id, 'type' => 'pending_earning', 'settled_at' => null]);
        $this->assertDatabaseHas('publisher_ledger_entries', ['id' => $foreignEarning->id, 'type' => 'pending_earning', 'settled_at' => null]);
        $this->assertDatabaseCount('payouts', 1);
        $this->assertDatabaseHas('payouts', ['publisher_account_id' => $publisher->id, 'currency' => 'EUR', 'amount_micros' => 55000000]);
    }

    #[TestWith([25000000, 25000000, 1])]
    #[TestWith([0, 0, 0])]
    #[TestWith([0, -1000000, 0])]
    public function test_configurable_minimum_never_creates_a_zero_or_negative_payout(int $minimum, int $amount, int $payoutCount): void
    {
        config(['publisher.minimum_payout_micros' => $minimum]);
        $publisher = PublisherAccount::factory()->create();
        $this->earning($publisher, $amount, '2026-09-01 00:00:00');

        $this->artisan('publishers:prepare-payouts')->assertSuccessful();

        $this->assertDatabaseCount('payouts', $payoutCount);
        $this->assertSame(1, PublisherLedgerEntry::where('type', 'payable')->count());
    }

    public function test_settlement_rolls_back_if_payout_creation_fails_and_can_be_retried(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $earning = $this->earning($publisher, 60000000, '2026-09-01 00:00:00');
        $event = 'eloquent.creating: '.Payout::class;
        Event::listen($event, function (): void {
            throw new RuntimeException('Payout write failed.');
        });

        try {
            app(PublisherPayoutService::class)->prepare();
            $this->fail('Expected the payout write to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payout write failed.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseCount('payouts', 0);
        $this->assertDatabaseHas('publisher_ledger_entries', ['id' => $earning->id, 'type' => 'pending_earning', 'settled_at' => null]);

        $this->artisan('publishers:prepare-payouts')->assertSuccessful();

        $this->assertDatabaseCount('payouts', 1);
        $this->assertDatabaseHas('publisher_ledger_entries', ['id' => $earning->id, 'type' => 'payable']);
    }

    public function test_preparation_is_scheduled_daily_in_utc_with_overlap_protection(): void
    {
        $events = collect(Schedule::events())->filter(fn (ScheduledEvent $event): bool => str_contains($event->command ?? '', 'publishers:prepare-payouts'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertTrue($event->isDue($this->app));
        $this->assertTrue($event->withoutOverlapping);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 00:10:00', 'UTC'));
        $this->assertTrue($event->isDue($this->app));
        $this->travelTo(CarbonImmutable::parse('2026-10-06 00:11:00', 'UTC'));
        $this->assertFalse($event->isDue($this->app));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function earning(PublisherAccount $publisher, int $amount, string $createdAt, array $attributes = []): PublisherLedgerEntry
    {
        return PublisherLedgerEntry::factory()->create(array_merge([
            'publisher_account_id' => $publisher->id,
            'server_id' => null,
            'currency' => $publisher->currency,
            'type' => 'pending_earning',
            'amount_micros' => $amount,
            'created_at' => $createdAt,
        ], $attributes));
    }
}
