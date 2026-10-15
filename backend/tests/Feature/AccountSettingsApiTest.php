<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AccountSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['advertiser'])]
    #[TestWith(['publisher'])]
    #[TestWith(['admin'])]
    public function test_all_account_types_can_update_only_their_own_display_name(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $other = User::factory()->create();
        $token = $user->createToken('current')->plainTextToken;

        $this->withToken($token)->patchJson('/api/auth/profile', [
            'name' => 'Updated name',
            'id' => $other->id,
            'email' => 'changed@example.test',
            'role' => 'admin',
            'status' => 'suspended',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Updated name')
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', $role)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated name', 'email' => $user->email, 'role' => $role]);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'name' => $other->name]);
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith([null])]
    public function test_returns_422_when_display_name_is_empty(?string $name): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('current')->plainTextToken)
            ->patchJson('/api/auth/profile', ['name' => $name])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name]);
    }

    public function test_returns_422_when_display_name_exceeds_255_characters(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('current')->plainTextToken)
            ->patchJson('/api/auth/profile', ['name' => str_repeat('a', 256)])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name]);
    }

    #[TestWith(['advertiser'])]
    #[TestWith(['publisher'])]
    #[TestWith(['admin'])]
    public function test_password_change_preserves_current_token_and_revokes_only_other_tokens_for_this_user(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'password' => 'old-password']);
        $current = $user->createToken('current');
        $otherSession = $user->createToken('other-session');
        $otherUserToken = User::factory()->create()->createToken('other-user');

        $this->withToken($current->plainTextToken)->putJson('/api/auth/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()->assertJsonPath('message', 'Password updated. Other sessions have been signed out.');

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertFalse(Hash::check('old-password', $user->fresh()->password));
        $this->assertModelExists($current->accessToken);
        $this->assertModelMissing($otherSession->accessToken);
        $this->assertModelExists($otherUserToken->accessToken);
        $this->assertNotSame($user->remember_token, $user->fresh()->remember_token);
        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherSession->plainTextToken)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public static function invalidPasswords(): array
    {
        return [
            'wrong current password' => [['current_password' => 'wrong-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'], 'current_password'],
            'short password' => [['current_password' => 'old-password', 'password' => 'short', 'password_confirmation' => 'short'], 'password'],
            'confirmation mismatch' => [['current_password' => 'old-password', 'password' => 'new-password', 'password_confirmation' => 'different-password'], 'password'],
            'same password' => [['current_password' => 'old-password', 'password' => 'old-password', 'password_confirmation' => 'old-password'], 'password'],
            'missing fields' => [[], 'current_password'],
        ];
    }

    #[DataProvider('invalidPasswords')]
    public function test_returns_422_without_changing_password_or_tokens_when_validation_fails(array $payload, string $field): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $token = $user->createToken('current');
        $otherToken = $user->createToken('other-session');

        $this->withToken($token->plainTextToken)->putJson('/api/auth/password', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'password' => $user->password]);
        $this->assertModelExists($token->accessToken);
        $this->assertModelExists($otherToken->accessToken);
    }

    #[TestWith(['PATCH', '/api/auth/profile'])]
    #[TestWith(['PUT', '/api/auth/password'])]
    public function test_returns_401_without_authentication(string $method, string $path): void
    {
        $this->json($method, $path, [])->assertUnauthorized();
    }

    #[TestWith(['PATCH', '/api/auth/profile'])]
    #[TestWith(['PUT', '/api/auth/password'])]
    public function test_returns_403_for_suspended_accounts(string $method, string $path): void
    {
        $user = User::factory()->create(['status' => 'suspended']);

        $this->withToken($user->createToken('current')->plainTextToken)
            ->json($method, $path, [])->assertForbidden();
    }

    public function test_unverified_account_can_save_profile_and_request_verification(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('current')->plainTextToken;

        $this->withToken($token)->patchJson('/api/auth/profile', ['name' => 'New name'])
            ->assertOk()->assertJsonPath('data.email_verified_at', null);
        $this->withToken($token)->postJson('/api/auth/email/verification-notification')->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New name', 'email_verified_at' => null]);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_google_connection_is_exposed_without_provider_id_and_password_reset_email_is_available(): void
    {
        Notification::fake();
        $user = User::factory()->create(['google_id' => 'private-google-id']);

        $this->withToken($user->createToken('current')->plainTextToken)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.google_connected', true)->assertJsonMissingPath('data.google_id');
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }
}
