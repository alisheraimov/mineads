<?php

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\PublisherAccount;
use App\Models\User;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
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
            ->assertJsonPath('user.role', null)
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.email_verified_at', null)
            ->assertJsonPath('user.advertiser_account', null)
            ->assertJsonPath('user.publisher_account', null);

        $this->assertDatabaseHas('users', [
            'email' => 'advertiser@example.test',
            'role' => null,
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
                'currency' => 'EUR',
            ])->assertOk()
            ->assertJsonPath('user.role', 'publisher')
            ->assertJsonPath('user.publisher_account.name', 'Server Network')
            ->assertJsonMissingPath('user.publisher_account.currency')
            ->assertJsonPath('user.advertiser_account', null);

        $this->assertDatabaseHas('publisher_accounts', [
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseCount('advertiser_accounts', 0);
    }

    public function test_database_does_not_assign_a_role_when_omitted(): void
    {
        $attributes = User::factory()->raw();
        unset($attributes['role']);

        $user = User::query()->create($attributes);

        $this->assertNull($user->fresh()->role);
    }

    public function test_google_signup_has_no_role_or_commercial_account(): void
    {
        $this->mockGoogleUser();

        $this->get('/api/auth/google/callback')->assertRedirect();

        $user = User::query()->where('email', 'google@example.test')->firstOrFail();
        $this->assertNull($user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseCount('advertiser_accounts', 0);
        $this->assertDatabaseCount('publisher_accounts', 0);
    }

    public function test_google_signin_preserves_the_existing_selected_role(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $publisher->user->update(['email' => 'google@example.test']);
        $this->mockGoogleUser();

        $this->get('/api/auth/google/callback')->assertRedirect();

        $this->assertSame('publisher', $publisher->user->fresh()->role);
        $this->assertDatabaseCount('advertiser_accounts', 0);
        $this->assertDatabaseCount('publisher_accounts', 1);
    }

    #[TestWith(['advertiser'])]
    #[TestWith(['publisher'])]
    public function test_unassigned_user_receives_a_role_only_after_verified_onboarding(string $role): void
    {
        $user = User::factory()->unverified()->create();
        $this->assertNull($user->role);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/auth/onboarding', ['role' => $role])->assertConflict();
        $this->assertNull($user->fresh()->role);

        $user->markEmailAsVerified();
        $this->postJson('/api/auth/onboarding', ['role' => 'admin'])->assertUnprocessable();
        $this->assertNull($user->fresh()->role);
        $this->postJson('/api/auth/onboarding', ['role' => $role])->assertOk()
            ->assertJsonPath('user.role', $role);
        $this->assertSame($role, $user->fresh()->role);
        $this->assertDatabaseCount($role.'_accounts', 1);
        $this->assertDatabaseCount($role === 'advertiser' ? 'publisher_accounts' : 'advertiser_accounts', 0);
    }

    public function test_unassigned_user_can_sign_in_but_cannot_access_any_workspace(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()->assertJsonPath('user.role', null);
        $this->withToken($response->json('token'));

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.role', null);
        $this->getJson('/api/advertiser/dashboard')->assertForbidden();
        $this->getJson('/api/publisher/dashboard')->assertForbidden();
        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    private function mockGoogleUser(): void
    {
        $googleUser = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
        $googleUser->shouldReceive('getEmail')->andReturn('google@example.test');
        $googleUser->shouldReceive('getId')->andReturn('google-test-id');
        $googleUser->shouldReceive('getName')->andReturn('Google user');
        $googleUser->shouldReceive('getAvatar')->andReturn(null);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($googleUser);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_google_signin_does_not_replace_an_uploaded_picture(): void
    {
        $user = User::factory()->create(['email' => 'google@example.test']);
        $user->forceFill(['avatar_path' => 'avatars/custom.png'])->save();
        $this->mockGoogleUser();

        $this->get('/api/auth/google/callback')->assertRedirect();

        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('data.avatar_url', Storage::disk('public')->url('avatars/custom.png'))
            ->assertJsonPath('data.has_uploaded_avatar', true);
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
