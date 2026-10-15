<?php

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\User;
use App\Models\ValidatedImpression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdvertiserBillingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_history_reconciles_deposits_spend_and_signed_adjustments(): void
    {
        $account = $this->signIn();
        foreach ([['2026-08-01', 'deposit', 200000000], ['2026-09-01', 'deposit', 1000000000], ['2026-09-30', 'spend', -600000000], ['2026-09-30', 'adjustment', -5000000], ['2026-10-01', 'deposit', 158400000], ['2026-10-02', 'spend', -750000000], ['2026-10-03', 'refund', 10000000]] as [$date, $type, $amount]) {
            $this->entry($account, $date, $type, $amount);
        }
        ValidatedImpression::factory()->create(['advertiser_account_id' => $account->id, 'validated_at' => '2026-10-01 00:00:00']);
        ValidatedImpression::factory()->create(['advertiser_account_id' => $account->id, 'validated_at' => '2026-09-30 23:59:59']);
        $this->getJson('/api/advertiser/billing')->assertOk()
            ->assertJsonPath('data.metrics.available_balance_micros', 13400000)
            ->assertJsonPath('data.metrics.funds_added_month_micros', 158400000)
            ->assertJsonPath('data.metrics.spend_month_micros', 750000000)
            ->assertJsonPath('data.metrics.impressions_month', 1)
            ->assertJsonPath('data.months.0.month', '2026-10')->assertJsonPath('data.months.0.is_current', true)
            ->assertJsonPath('data.months.0.starting_balance_micros', 595000000)
            ->assertJsonPath('data.months.0.adjustments_micros', 10000000)
            ->assertJsonPath('data.months.0.ending_balance_micros', 13400000)
            ->assertJsonPath('data.months.1.starting_balance_micros', 200000000)
            ->assertJsonPath('data.months.1.adjustments_micros', -5000000)
            ->assertJsonPath('data.months.2.starting_balance_micros', 0)
            ->assertJsonPath('data.low_balance', false)->assertJsonPath('data.funding_available', false);
    }

    public function test_quiet_months_keep_the_balance_and_history_paginates_without_resetting_opening_balance(): void
    {
        $account = $this->signIn();
        $this->entry($account, '2025-08-01', 'deposit', 20000000);
        $this->entry($account, '2026-10-01', 'spend', -1000000);
        $this->getJson('/api/advertiser/billing')->assertOk()->assertJsonCount(12, 'data.months')->assertJsonPath('data.pagination.total', 15)
            ->assertJsonPath('data.months.0.ending_balance_micros', 19000000)
            ->assertJsonPath('data.months.1.starting_balance_micros', 20000000)->assertJsonPath('data.months.1.spend_micros', 0);
        $this->getJson('/api/advertiser/billing?page=2')->assertOk()->assertJsonCount(3, 'data.months')
            ->assertJsonPath('data.months.0.month', '2025-10')->assertJsonPath('data.months.0.starting_balance_micros', 20000000)
            ->assertJsonPath('data.months.2.month', '2025-08')->assertJsonPath('data.months.2.starting_balance_micros', 0);
        $this->getJson('/api/advertiser/billing?page=3')->assertOk()->assertJsonCount(0, 'data.months');
    }

    #[TestWith([9999999, true])]
    #[TestWith([10000000, false])]
    #[TestWith([-1000000, true])]
    public function test_low_balance_threshold_is_strictly_below_ten_dollars(int $balance, bool $low): void
    {
        $account = $this->signIn();
        $this->entry($account, '2026-10-01', 'adjustment', $balance);
        $this->getJson('/api/advertiser/billing')->assertOk()->assertJsonPath('data.low_balance', $low)->assertJsonPath('data.metrics.available_balance_micros', $balance);
    }

    public function test_empty_account_shows_zero_current_month_without_offering_funding(): void
    {
        $this->signIn();
        $this->getJson('/api/advertiser/billing')->assertOk()->assertJsonCount(1, 'data.months')
            ->assertJsonPath('data.months.0.month', '2026-10')->assertJsonPath('data.months.0.ending_balance_micros', 0)
            ->assertJsonPath('data.low_balance', true)->assertJsonPath('data.funding_available', false);
    }

    public function test_balance_uses_all_posted_entries_but_excludes_other_accounts_and_currencies(): void
    {
        $account = $this->signIn();
        $this->entry($account, '2026-10-01', 'deposit', 50000000);
        $this->entry(AdvertiserAccount::factory()->create(), '2026-10-01', 'deposit', 900000000);
        $this->entry($account, '2026-10-15', 'deposit', 10000000)->update(['created_at' => '2026-10-15 23:00:00']);
        $foreignCurrency = $this->entry($account, '2026-10-01', 'deposit', 900000000);
        $foreignCurrency->update(['currency' => 'EUR']);
        $this->getJson('/api/advertiser/billing?advertiser_account_id=999')->assertOk()->assertJsonPath('data.metrics.available_balance_micros', 60000000)->assertJsonPath('data.pagination.total', 1);
    }

    public function test_snapshot_cache_expires_and_contains_only_serializable_plain_data(): void
    {
        $account = $this->signIn();
        $this->entry($account, '2026-10-01', 'deposit', 20000000);
        $first = $this->getJson('/api/advertiser/billing')->assertOk()->json('data');
        $key = 'advertiser_billing:v1:'.$account->id;
        Cache::put($key, unserialize(serialize(Cache::get($key)), ['allowed_classes' => false]), 30);
        $this->entry($account, '2026-10-02', 'deposit', 10000000);
        $this->getJson('/api/advertiser/billing')->assertOk()->assertJsonPath('data.months', $first['months'])->assertJsonPath('data.metrics.available_balance_micros', 20000000);
        $this->travel(31)->seconds();
        $this->getJson('/api/advertiser/billing')->assertOk()->assertJsonPath('data.metrics.available_balance_micros', 30000000);
    }

    public function test_access_requires_advertiser_and_commercial_account_but_allows_suspended_account_reads(): void
    {
        $this->getJson('/api/advertiser/billing')->assertUnauthorized();
        foreach (['publisher', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
            $this->getJson('/api/advertiser/billing')->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'advertiser']), 'sanctum');
        $this->getJson('/api/advertiser/billing')->assertConflict();
        $account = $this->signIn();
        $account->update(['status' => 'suspended']);
        $this->getJson('/api/advertiser/billing')->assertOk()->assertJsonPath('data.account.status', 'suspended');
        $this->postJson('/api/advertiser/billing', ['amount_micros' => 100000000])->assertStatus(405);
        $this->assertDatabaseCount('advertiser_ledger_entries', 0);
    }

    #[TestWith(['0'])]
    #[TestWith(['-1'])]
    #[TestWith(['abc'])]
    public function test_invalid_page_is_rejected(string $page): void
    {
        $this->signIn();
        $this->getJson('/api/advertiser/billing?page='.$page)->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    private function signIn(): AdvertiserAccount
    {
        Cache::flush();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
        $user = User::factory()->create(['role' => 'advertiser']);
        $this->actingAs($user, 'sanctum');

        return AdvertiserAccount::factory()->create(['user_id' => $user->id, 'status' => 'active', 'currency' => 'USD']);
    }

    private function entry(AdvertiserAccount $account, string $date, string $type, int $amount): AdvertiserLedgerEntry
    {
        return AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $account->id, 'campaign_id' => null,
            'type' => $type, 'amount_micros' => $amount, 'currency' => 'USD', 'created_at' => $date.' 00:00:00']);
    }
}
