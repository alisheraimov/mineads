<?php

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\PublisherAccount;
use App\Models\User;
use Tests\TestCase;

class UserRoleMigrationTest extends TestCase
{
    public function test_migration_clears_unfinished_roles_and_preserves_existing_accounts(): void
    {
        $this->artisan('migrate:fresh')->assertSuccessful();
        $migration = require database_path('migrations/2026_11_17_073900_make_user_role_nullable_until_onboarding.php');
        $migration->down();

        $unfinished = User::factory()->create(['role' => 'advertiser']);
        $advertiserAccount = AdvertiserAccount::factory()->create();
        $publisherAccount = PublisherAccount::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);

        $migration->up();

        $this->assertNull($unfinished->fresh()->role);
        $this->assertSame('advertiser', $advertiserAccount->user->fresh()->role);
        $this->assertSame('publisher', $publisherAccount->user->fresh()->role);
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertDatabaseHas('advertiser_accounts', ['id' => $advertiserAccount->id]);
        $this->assertDatabaseHas('publisher_accounts', ['id' => $publisherAccount->id]);

        $migration->down();
        $this->assertSame('advertiser', $unfinished->fresh()->role);
    }
}
