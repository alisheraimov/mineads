<?php

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\PublisherAccount;
use App\Models\User;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receives_verification_email(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/register', [
            'email' => 'advertiser@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token'])
            ->assertJsonPath('user.role', 'advertiser')
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.email_verified_at', null)
            ->assertJsonPath('user.advertiser_account', null)
            ->assertJsonPath('user.publisher_account', null);

        $this->assertDatabaseHas('users', [
            'email' => 'advertiser@example.test',
            'role' => 'advertiser',
            'status' => 'active',
        ]);
        Notification::assertSentTo(User::query()->where('email', 'advertiser@example.test')->first(), VerifyEmail::class);
        $this->assertDatabaseCount('advertiser_accounts', 0);
        $this->assertDatabaseCount('publisher_accounts', 0);
    }

    public function test_verified_user_can_choose_publisher_path(): void
    {
        $user = User::factory()->create([
            'name' => 'Server Network',
            'email_verified_at' => now(),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/onboarding', [
                'role' => 'publisher',
            ])->assertOk()
            ->assertJsonPath('user.role', 'publisher')
            ->assertJsonPath('user.publisher_account.name', 'Server Network')
            ->assertJsonPath('user.advertiser_account', null);

        $this->assertDatabaseHas('publisher_accounts', [
            'name' => 'Server Network',
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('advertiser_accounts', 0);
    }

    public function test_suspended_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'suspended@example.test',
            'password' => 'password123',
            'status' => 'suspended',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'suspended@example.test',
            'password' => 'password123',
        ])->assertForbidden()
            ->assertJsonPath('message', 'This account has been suspended.');
    }

    public function test_onboarding_cannot_create_second_account_type(): void
    {
        $user = User::factory()->create(['role' => 'advertiser', 'email_verified_at' => now()]);
        AdvertiserAccount::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/onboarding', [
                'role' => 'publisher',
                'account_name' => 'Second Path',
            ])->assertUnprocessable()
            ->assertJsonPath('message', 'This user already has an account type.');

        $this->assertDatabaseCount('publisher_accounts', 0);
    }

    public function test_admin_permissions_are_seeded_and_returned_from_me(): void
    {
        $this->seed(AdminPermissionSeeder::class);

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('support');
        $token = $admin->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.permissions.0', 'admin.users.view');
    }
}
