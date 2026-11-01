<?php

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\Campaign;
use App\Models\PublisherAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccountDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['advertiser'])]
    #[TestWith(['publisher'])]
    public function test_account_creation_uses_the_user_display_name_and_ignores_a_separate_account_name(string $role): void
    {
        $user = User::factory()->create(['name' => 'Profile name', 'email_verified_at' => now()]);
        $this->actingAs($user, 'sanctum')->postJson('/api/auth/onboarding', [
            'role' => $role, 'account_name' => 'Obsolete account name',
        ])->assertOk()->assertJsonPath("user.{$role}_account.name", 'Profile name');

        $this->assertFalse(Schema::hasColumn("{$role}_accounts", 'name'));
        $this->assertDatabaseHas("{$role}_accounts", ['user_id' => $user->id, 'status' => 'active']);
    }

    public function test_advertiser_profile_rename_updates_cached_dashboard_billing_and_campaign_labels(): void
    {
        Cache::flush();
        $user = User::factory()->create(['name' => 'Original name', 'role' => 'advertiser']);
        $account = AdvertiserAccount::factory()->create(['user_id' => $user->id]);
        $campaign = Campaign::factory()->create(['advertiser_account_id' => $account->id]);
        $this->actingAs($user, 'sanctum');
        $endpoints = [
            '/api/advertiser/dashboard' => 'data.account.name',
            '/api/advertiser/billing' => 'data.account.name',
            '/api/advertiser/campaigns' => 'data.account_name',
            '/api/advertiser/campaigns/'.$campaign->id => 'data.account_name',
        ];
        foreach ($endpoints as $url => $field) {
            $this->getJson($url)->assertOk()->assertJsonPath($field, 'Original name');
        }

        $this->patchJson('/api/auth/profile', ['name' => 'Updated name'])->assertOk()
            ->assertJsonPath('data.advertiser_account.name', 'Updated name');

        foreach ($endpoints as $url => $field) {
            $this->getJson($url)->assertOk()->assertJsonPath($field, 'Updated name');
        }
    }

    public function test_publisher_dashboard_and_payout_labels_follow_profile_renames(): void
    {
        $user = User::factory()->create(['name' => 'Original publisher', 'role' => 'publisher']);
        PublisherAccount::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user, 'sanctum');
        foreach (['/api/publisher/dashboard', '/api/publisher/payouts'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('data.account.name', 'Original publisher');
        }

        $this->patchJson('/api/auth/profile', ['name' => 'Updated publisher'])->assertOk()
            ->assertJsonPath('data.publisher_account.name', 'Updated publisher');

        foreach (['/api/publisher/dashboard', '/api/publisher/payouts'] as $url) {
            $this->getJson($url)->assertOk()->assertJsonPath('data.account.name', 'Updated publisher');
        }
    }

    public function test_migration_rollback_rebuilds_names_from_users_and_preserves_account_relationships(): void
    {
        $advertiser = AdvertiserAccount::factory()->create();
        $publisher = PublisherAccount::factory()->create();
        $campaign = Campaign::factory()->create(['advertiser_account_id' => $advertiser->id]);
        $migration = require database_path('migrations/2026_11_01_132310_remove_names_from_commercial_accounts.php');
        $migration->down();

        $this->assertDatabaseHas('advertiser_accounts', ['id' => $advertiser->id, 'name' => $advertiser->user->name]);
        $this->assertDatabaseHas('publisher_accounts', ['id' => $publisher->id, 'name' => $publisher->user->name]);

        $migration->up();

        $this->assertFalse(Schema::hasColumn('advertiser_accounts', 'name'));
        $this->assertFalse(Schema::hasColumn('publisher_accounts', 'name'));
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'advertiser_account_id' => $advertiser->id]);
    }
}
