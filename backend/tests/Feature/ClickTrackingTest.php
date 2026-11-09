<?php

namespace Tests\Feature;

use App\Models\AdCreative;
use App\Models\AdDelivery;
use App\Models\AdvertiserAccount;
use App\Models\Campaign;
use App\Models\ValidatedImpression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ClickTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirects_to_snapshot_and_deduplicates_visitors_without_charging(): void
    {
        $this->freezeTime();
        $delivery = $this->delivery();
        $delivery->update(['expires_at' => now()->subDay()]);

        $this->get('/c/'.str_repeat('a', 48).'?url=https://attacker.example', ['User-Agent' => 'Mozilla/5.0'])
            ->assertRedirect('https://example.com/original')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/c/'.str_repeat('a', 48), ['User-Agent' => 'Mozilla/5.0'])->assertRedirect();
        $this->get('/c/'.str_repeat('a', 48), ['User-Agent' => 'Mozilla/5.0 Other'])->assertRedirect();

        $this->assertDatabaseCount('raw_click_events', 3);
        $this->assertSame(2, DB::table('raw_click_events')->where('is_unique', true)->count());
        $this->assertDatabaseHas('raw_click_events', ['exclusion_reason' => 'duplicate', 'is_unique' => false]);
        $this->assertDatabaseCount('advertiser_ledger_entries', 0);
        $this->assertDatabaseCount('publisher_ledger_entries', 0);
        $this->assertDatabaseCount('validated_impressions', 0);
    }

    #[TestWith([['User-Agent' => 'Googlebot']])]
    #[TestWith([['User-Agent' => 'Discordbot link preview']])]
    #[TestWith([['User-Agent' => 'Mozilla/5.0', 'Sec-Purpose' => 'prefetch']])]
    #[TestWith([['User-Agent' => 'Mozilla/5.0', 'Purpose' => 'prefetch']])]
    #[TestWith([['User-Agent' => '']])]
    public function test_automated_opens_are_stored_but_excluded_from_unique_clicks(array $headers): void
    {
        $this->freezeTime();
        $this->delivery();

        $this->get('/c/'.str_repeat('a', 48), $headers)->assertRedirect('https://example.com/original');

        $this->assertDatabaseCount('raw_click_events', 1);
        $this->assertDatabaseHas('raw_click_events', ['is_unique' => false, 'exclusion_reason' => 'automated']);
    }

    public function test_head_redirects_without_recording_a_click(): void
    {
        $this->freezeTime();
        $this->delivery();

        $this->head('/c/'.str_repeat('a', 48), ['User-Agent' => 'Mozilla/5.0'])->assertRedirect('https://example.com/original');

        $this->assertDatabaseCount('raw_click_events', 0);
    }

    #[TestWith([['click_expires_at' => '2026-11-02 12:00:00'], 410])]
    #[TestWith([['click_expires_at' => '2026-11-03 12:00:00'], 410])]
    #[TestWith([['click_expires_at' => null], 410])]
    #[TestWith([['decision' => 'no_fill'], 404])]
    #[TestWith([['click_token_hash' => null], 404])]
    #[TestWith([['click_destination' => null], 404])]
    #[TestWith([['click_destination' => 'javascript:alert(1)'], 404])]
    public function test_invalid_links_do_not_redirect_or_record_clicks(array $attributes, int $status): void
    {
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(12, 0));
        $this->delivery()->update($attributes);

        $this->get('/c/'.str_repeat('a', 48), ['User-Agent' => 'Mozilla/5.0'])->assertStatus($status)->assertHeaderMissing('Location');

        $this->assertDatabaseCount('raw_click_events', 0);
    }

    public function test_unknown_and_malformed_tokens_return_404(): void
    {
        $this->get('/c/invalid')->assertNotFound();
        $this->get('/c/'.str_repeat('z', 48))->assertNotFound();

        $this->assertDatabaseCount('raw_click_events', 0);
    }

    public function test_public_redirect_is_rate_limited(): void
    {
        $this->freezeTime();
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $this->get('/c/'.str_repeat('z', 48))->assertNotFound();
        }

        $this->get('/c/'.str_repeat('z', 48))->assertTooManyRequests();

        $this->assertDatabaseCount('raw_click_events', 0);
    }

    public function test_advertiser_reports_scope_clicks_to_account_and_utc_period(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(12, 0));
        $delivery = $this->delivery();
        $campaign = $delivery->campaign;
        $account = AdvertiserAccount::findOrFail($campaign->advertiser_account_id);
        $account->user->update(['role' => 'advertiser']);
        ValidatedImpression::factory()->count(4)->create([
            'advertiser_account_id' => $account->id, 'campaign_id' => $campaign->id,
            'creative_id' => $delivery->creative_id, 'validated_at' => now(),
        ]);
        $this->get('/c/'.str_repeat('a', 48), ['User-Agent' => 'October visitor'])->assertRedirect();
        DB::table('raw_click_events')->update(['received_at' => '2026-10-31 23:59:59']);
        $this->get('/c/'.str_repeat('a', 48), ['User-Agent' => 'November visitor'])->assertRedirect();
        $this->get('/c/'.str_repeat('a', 48), ['User-Agent' => 'November visitor'])->assertRedirect();
        $this->delivery('b');
        $this->get('/c/'.str_repeat('b', 48), ['User-Agent' => 'Other account visitor'])->assertRedirect();
        $this->actingAs($account->user, 'sanctum');

        $this->getJson('/api/advertiser/dashboard')->assertOk()->assertJsonPath('data.metrics.clicks_month', 1)
            ->assertJsonPath('data.metrics.raw_clicks_month', 2)->assertJsonPath('data.metrics.ctr_month', 25);
        $range = '?from=2026-11-03&to=2026-11-03';
        $this->getJson('/api/advertiser/campaigns/'.$campaign->id.$range)->assertOk()
            ->assertJsonPath('data.metrics.clicks', 1)->assertJsonPath('data.metrics.raw_clicks', 2)->assertJsonPath('data.metrics.ctr', 25);
        $this->getJson('/api/advertiser/creatives/'.$delivery->creative_id.$range)->assertOk()
            ->assertJsonPath('data.metrics.clicks', 1)->assertJsonPath('data.metrics.raw_clicks', 2)->assertJsonPath('data.metrics.ctr', 25);
        $this->getJson('/api/advertiser/campaigns')->assertOk()->assertJsonPath('data.campaigns.0.clicks', 2)->assertJsonPath('data.campaigns.0.ctr', 50);
        $this->getJson('/api/advertiser/creatives')->assertOk()->assertJsonPath('data.creatives.0.clicks', 2)->assertJsonPath('data.creatives.0.ctr', 50);
    }

    public function test_ctr_is_unavailable_without_validated_impressions(): void
    {
        $this->freezeTime();
        $delivery = $this->delivery();
        $user = $delivery->campaign->advertiserAccount->user;
        $user->update(['role' => 'advertiser']);
        $this->get('/c/'.str_repeat('a', 48), ['User-Agent' => 'Mozilla/5.0'])->assertRedirect();
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/advertiser/campaigns/'.$delivery->campaign_id)->assertOk()
            ->assertJsonPath('data.metrics.clicks', 1)->assertJsonPath('data.metrics.ctr', null);
    }

    private function delivery(string $character = 'a'): AdDelivery
    {
        $campaign = Campaign::factory()->create();
        $creative = AdCreative::factory()->create(['campaign_id' => $campaign->id]);

        return AdDelivery::factory()->create([
            'campaign_id' => $campaign->id, 'creative_id' => $creative->id,
            'click_token_hash' => hash('sha256', str_repeat($character, 48)),
            'click_destination' => 'https://example.com/original', 'click_expires_at' => now()->addDays(30),
        ]);
    }
}
