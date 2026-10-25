<?php

namespace Tests\Feature;

use App\Models\AdminUserEvent;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\PublisherAccount;
use App\Models\Server;
use App\Models\User;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUsersApiTest extends TestCase
{
    use RefreshDatabase;

    public static function endpoints(): array
    {
        return [['GET', '/api/admin/users'], ['GET', '/api/admin/users/1'], ['POST', '/api/admin/users'], ['POST', '/api/admin/users/1/suspend'], ['POST', '/api/admin/users/1/reactivate'], ['PATCH', '/api/admin/users/1/role'], ['DELETE', '/api/admin/users/1']];
    }

    #[DataProvider('endpoints')]
    public function test_guests_cannot_access_user_endpoints(string $method, string $url): void
    {
        $this->json($method, $url)->assertUnauthorized();
    }

    public static function restrictedRoles(): array
    {
        return [['advertiser'], ['publisher'], ['ad-ops'], ['finance']];
    }

    #[DataProvider('restrictedRoles')]
    public function test_roles_without_user_access_are_forbidden(string $role): void
    {
        $user = in_array($role, ['advertiser', 'publisher']) ? User::factory()->create(['role' => $role]) : $this->admin($role);

        $this->actingAs($user, 'sanctum')->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_support_can_read_identity_but_not_finance_or_management_controls(): void
    {
        $support = $this->admin('support');
        $account = AdvertiserAccount::factory()->create();
        AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $account->id, 'type' => 'deposit', 'amount_micros' => 420000000]);

        $this->actingAs($support, 'sanctum')->getJson('/api/admin/users/'.$account->user_id)
            ->assertOk()->assertJsonPath('data.email', $account->user->email)->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.can_view_finance', false)->assertJsonMissingPath('data.account.available_micros')->assertJsonMissingPath('data.account.lifetime_spend_micros');
    }

    public function test_owner_can_search_filter_paginate_and_view_deleted_users(): void
    {
        $owner = $this->admin();
        $deleted = User::factory()->create(['name' => 'Historical Person', 'role' => 'publisher']);
        $deleted->delete();
        User::factory()->create(['name' => 'Other Person', 'role' => 'advertiser']);

        $this->actingAs($owner, 'sanctum')->getJson('/api/admin/users?status=deleted&role=publisher&search=U-'.$deleted->id.'&per_page=10')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $deleted->id)->assertJsonPath('data.0.status', 'deleted')->assertJsonMissingPath('data.0.password')->assertJsonPath('can_create_admin', true);
    }

    public function test_query_sort_injection_and_invalid_page_size_are_rejected(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/users?sort=password&direction=bad&per_page=10000')
            ->assertUnprocessable()->assertJsonValidationErrors(['sort', 'direction', 'per_page']);
    }

    public function test_owner_creates_verified_admin_with_hashed_password_and_no_commercial_account(): void
    {
        $owner = $this->admin();
        Notification::fake();

        $response = $this->actingAs($owner, 'sanctum')->postJson('/api/admin/users', $this->adminPayload() + ['role' => 'advertiser', 'status' => 'suspended', 'permissions' => ['admin.finance.manage']])
            ->assertCreated()->assertJsonPath('data.role', 'admin')->assertJsonPath('data.status', 'active')->assertJsonPath('data.admin_role', 'support')->assertJsonMissingPath('data.password');

        $user = User::query()->findOrFail($response->json('data.id'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('initial-password', $user->password));
        $this->assertNull($user->advertiserAccount);
        $this->assertNull($user->publisherAccount);
        $this->assertDatabaseHas('admin_user_events', ['user_id' => $user->id, 'actor_id' => $owner->id, 'action' => 'created']);
        $this->assertStringNotContainsString('initial-password', AdminUserEvent::query()->first()->toJson());
        Notification::assertNothingSent();
    }

    public function test_admin_creation_requires_valid_fields_and_matching_password(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/users', ['name' => '', 'email' => 'not-email', 'password' => 'short', 'password_confirmation' => 'different', 'admin_role' => 'super-user'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password', 'admin_role']);

        $this->assertDatabaseCount('admin_user_events', 0);
    }

    public function test_deleted_user_email_cannot_be_reused_for_admin_creation(): void
    {
        $owner = $this->admin();
        $user = User::factory()->create(['email' => 'new-admin@mineads.test']);
        $user->delete();

        $this->actingAs($owner, 'sanctum')->postJson('/api/admin/users', $this->adminPayload())->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSoftDeleted($user);
    }

    public function test_support_cannot_create_admins_or_manage_commercial_users(): void
    {
        $support = $this->admin('support');
        $user = User::factory()->create();

        $this->actingAs($support, 'sanctum')->postJson('/api/admin/users', $this->adminPayload())->assertForbidden();
        $this->postJson('/api/admin/users/'.$user->id.'/suspend', ['reason' => 'Not allowed'])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'active']);
        $this->assertDatabaseCount('admin_user_events', 0);
    }

    public function test_owner_suspends_user_revokes_sessions_and_preserves_account_settings(): void
    {
        $owner = $this->admin();
        $account = PublisherAccount::factory()->create();
        $server = Server::factory()->create(['publisher_account_id' => $account->id, 'status' => 'active']);
        $user = $account->user;
        $user->createToken('web');

        $this->actingAs($owner, 'sanctum')->postJson('/api/admin/users/'.$user->id.'/suspend', ['reason' => 'Abusive traffic'])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'suspended', 'suspension_reason' => 'Abusive traffic', 'suspended_by' => $owner->id]);
        $this->assertDatabaseHas('servers', ['id' => $server->id, 'status' => 'active']);
        $this->assertDatabaseHas('publisher_accounts', ['id' => $account->id, 'status' => 'active']);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('admin_user_events', ['user_id' => $user->id, 'action' => 'suspended']);
    }

    public function test_suspension_requires_reason_and_reactivation_preserves_separate_account_restrictions(): void
    {
        $owner = $this->admin();
        $account = AdvertiserAccount::factory()->create(['status' => 'suspended']);
        $user = $account->user;

        $this->actingAs($owner, 'sanctum')->postJson('/api/admin/users/'.$user->id.'/suspend', ['reason' => ' '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $user->forceFill(['status' => 'suspended', 'suspension_reason' => 'Review'])->save();
        $this->postJson('/api/admin/users/'.$user->id.'/reactivate')->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'active', 'suspension_reason' => null]);
        $this->assertDatabaseHas('advertiser_accounts', ['id' => $account->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('admin_user_events', ['user_id' => $user->id, 'action' => 'reactivated']);
    }

    public function test_delete_requires_email_confirmation_and_soft_deletes_only_the_user(): void
    {
        $owner = $this->admin();
        $account = AdvertiserAccount::factory()->create();
        $campaign = Campaign::factory()->create(['advertiser_account_id' => $account->id]);
        $entry = AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $account->id, 'campaign_id' => $campaign->id]);
        $user = $account->user;
        $user->createToken('web');

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/admin/users/'.$user->id, ['email' => 'wrong@example.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->deleteJson('/api/admin/users/'.$user->id, ['email' => $user->email])->assertOk();

        $this->assertSoftDeleted($user);
        $this->assertModelExists($account);
        $this->assertModelExists($campaign);
        $this->assertModelExists($entry);
        $this->assertSame(0, $user->tokens()->count());
        $this->getJson('/api/admin/users/'.$user->id)->assertOk()->assertJsonPath('data.can_manage', false)->assertJsonPath('data.status', 'deleted');
        $this->postJson('/api/admin/users/'.$user->id.'/reactivate')->assertForbidden();
    }

    public function test_owner_cannot_suspend_delete_or_demote_themselves_even_as_last_owner(): void
    {
        $owner = $this->admin();

        $this->actingAs($owner, 'sanctum')->postJson('/api/admin/users/'.$owner->id.'/suspend', ['reason' => 'Self'])->assertForbidden();
        $this->deleteJson('/api/admin/users/'.$owner->id, ['email' => $owner->email])->assertForbidden();
        $this->patchJson('/api/admin/users/'.$owner->id.'/role', ['admin_role' => 'support'])->assertForbidden();

        $this->assertTrue($owner->fresh()->hasRole('admin-owner'));
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'status' => 'active', 'deleted_at' => null]);
    }

    public function test_role_changes_replace_permissions_and_revoke_old_tokens(): void
    {
        $owner = $this->admin();
        $target = $this->admin('support');
        $target->givePermissionTo('admin.users.manage');
        $token = $target->createToken('web')->plainTextToken;

        $this->actingAs($owner, 'sanctum')->patchJson('/api/admin/users/'.$target->id.'/role', ['admin_role' => 'finance'])->assertOk();

        $this->assertTrue($target->fresh()->hasRole('finance'));
        $this->assertFalse($target->fresh()->adminCan('admin.users.view'));
        $this->assertFalse($target->fresh()->adminCan('admin.users.manage'));
        $this->assertTrue($target->fresh()->adminCan('admin.finance.manage'));
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('admin_user_events', ['user_id' => $target->id, 'actor_id' => $owner->id, 'action' => 'role_changed']);
    }

    public function test_commercial_users_cannot_be_promoted_and_admins_cannot_onboard_as_commercial_users(): void
    {
        $owner = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($owner, 'sanctum')->patchJson('/api/admin/users/'.$user->id.'/role', ['admin_role' => 'support'])->assertUnprocessable();
        $this->postJson('/api/auth/onboarding', ['role' => 'advertiser'])->assertForbidden();

        $this->assertDatabaseMissing('advertiser_accounts', ['user_id' => $owner->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'advertiser']);
    }

    private function admin(string $role = 'admin-owner'): User
    {
        $this->seed(AdminPermissionSeeder::class);
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole($role);

        return $user;
    }

    public function test_deleted_user_cannot_sign_in_and_unverified_or_suspended_admins_cannot_access_users(): void
    {
        $deleted = User::factory()->create(['password' => 'password123']);
        $deleted->delete();
        $admin = $this->admin();
        $admin->forceFill(['email_verified_at' => null])->save();

        $this->postJson('/api/auth/login', ['email' => $deleted->email, 'password' => 'password123'])->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users')->assertForbidden();
        $admin->forceFill(['email_verified_at' => now(), 'status' => 'suspended'])->save();
        $this->getJson('/api/admin/users')->assertForbidden();
    }

    private function adminPayload(): array
    {
        return ['name' => 'New Admin', 'email' => 'new-admin@mineads.test', 'password' => 'initial-password', 'password_confirmation' => 'initial-password', 'admin_role' => 'support'];
    }
}
