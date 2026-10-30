<?php

namespace Tests\Feature;

use App\Models\RawImpressionEvent;
use App\Models\Server;
use App\Models\ServerHeartbeat;
use App\Models\User;
use App\Models\ValidatedImpression;
use App\Services\ServerReviewService;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminServersApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 10, 30)->setTime(12, 0));
    }

    private function admin(string $role = 'admin-owner'): User
    {
        $this->seed(AdminPermissionSeeder::class);
        $u = User::factory()->create(['role' => 'admin']);
        $u->assignRole($role);

        return $u;
    }

    private function version(Server $server): string
    {
        return app(ServerReviewService::class)->version($server->fresh());
    }

    public static function endpoints(): array
    {
        return [['GET', '/api/admin/servers'], ['GET', '/api/admin/publishers'], ['GET', '/api/admin/servers/1'], ['GET', '/api/admin/servers/1/activity'], ['GET', '/api/admin/servers/1/history'], ['POST', '/api/admin/servers/1/moderate'], ['DELETE', '/api/admin/servers/1']];
    }

    #[DataProvider('endpoints')]
    public function test_guests_cannot_access_admin_server_endpoints(string $method, string $url): void
    {
        $this->json($method, $url)->assertUnauthorized();
    }

    public static function viewers(): array
    {
        return [['admin-owner', true, true], ['ad-ops', true, true], ['support', false, false], ['finance', false, true]];
    }

    #[DataProvider('viewers')]
    public function test_role_permissions_and_financial_redaction(string $role, bool $manage, bool $money): void
    {
        $s = Server::factory()->create();
        $this->actingAs($this->admin($role), 'sanctum')->getJson('/api/admin/servers/'.$s->id)->assertOk()->assertJsonPath('data.can_manage', $manage)->assertJsonPath('data.can_view_money', $money);
        $response = $this->getJson('/api/admin/servers/'.$s->id.'/activity')->assertOk();
        $publishers = $this->getJson('/api/admin/publishers')->assertOk();
        if (! $money) {
            $response->assertJsonMissingPath('data.metrics.earnings_micros')->assertJsonMissingPath('data.points.0.earnings_micros');
            $publishers->assertJsonMissingPath('data.0.earnings_micros');
        }
        if (! $manage) {
            $this->postJson('/api/admin/servers/'.$s->id.'/moderate', ['action' => 'suspend', 'reason' => 'Review needed', 'version' => $this->version($s)])->assertForbidden();
            $this->deleteJson('/api/admin/servers/'.$s->id)->assertForbidden();
        }
    }

    public function test_publishers_and_unassigned_admins_cannot_view_servers(): void
    {
        $u = User::factory()->create(['role' => 'publisher']);
        $this->actingAs($u, 'sanctum')->getJson('/api/admin/servers')->assertForbidden();
        $u = User::factory()->create(['role' => 'admin']);
        $this->actingAs($u, 'sanctum')->getJson('/api/admin/servers')->assertForbidden();
    }

    public function test_queue_filters_deleted_parents_and_sorts_oldest_submission_first(): void
    {
        $new = Server::factory()->create(['review_status' => 'pending_review', 'review_submitted_at' => now()->subHour()]);
        $old = Server::factory()->create(['review_status' => 'pending_review', 'review_submitted_at' => now()->subDay()]);
        $deleted = Server::factory()->create(['review_status' => 'pending_review']);
        $deleted->delete();
        $parent = Server::factory()->create(['review_status' => 'pending_review']);
        $parent->publisherAccount->user->delete();
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/servers?review=pending_review')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $old->id)->assertJsonPath('data.1.id', $new->id);
        $this->getJson('/api/admin/servers?include_deleted=1&search=SRV-'.$deleted->id)->assertJsonPath('data.0.status', 'deleted');
        $this->getJson('/api/admin/servers?publisher='.$old->publisherAccount->user_id)->assertJsonCount(1, 'data');
    }

    public function test_approval_requires_activation_and_reject_reason_and_stale_decisions_are_blocked(): void
    {
        $s = Server::factory()->create(['review_status' => 'pending_review', 'activated_at' => null, 'legacy_approval' => false]);
        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/servers/'.$s->id.'/moderate', ['action' => 'approve', 'version' => $this->version($s)])->assertConflict();
        $s->update(['activated_at' => now()]);
        $version = $this->version($s);
        $this->postJson('/api/admin/servers/'.$s->id.'/moderate', ['action' => 'reject', 'version' => $version])->assertJsonValidationErrors('reason');
        $this->postJson('/api/admin/servers/'.$s->id.'/moderate', ['action' => 'approve', 'version' => $version])->assertOk()->assertJsonPath('data.review_status', 'approved');
        $this->assertDatabaseHas('server_review_events', ['server_id' => $s->id, 'action' => 'approved']);
        $this->postJson('/api/admin/servers/'.$s->id.'/moderate', ['action' => 'suspend', 'reason' => 'Investigation', 'version' => $version])->assertConflict();
    }

    public function test_suspension_reactivation_preserves_disabled_state_and_deletion_retains_history(): void
    {
        $s = Server::factory()->create(['status' => 'inactive']);
        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/servers/'.$s->id.'/moderate', ['action' => 'suspend', 'reason' => 'Investigating traffic', 'version' => $this->version($s)])->assertOk()->assertJsonPath('data.suspension_reason', 'Investigating traffic');
        $this->postJson('/api/admin/servers/'.$s->id.'/moderate', ['action' => 'reactivate', 'version' => $this->version($s)])->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->deleteJson('/api/admin/servers/'.$s->id, ['version' => $this->version($s), 'confirmation' => 'wrong'])->assertJsonValidationErrors('confirmation');
        $this->deleteJson('/api/admin/servers/'.$s->id, ['version' => $this->version($s), 'confirmation' => 'SRV-'.$s->id])->assertOk()->assertJsonPath('data.can_manage', false);
        $this->assertSoftDeleted('servers', ['id' => $s->id]);
        $this->assertDatabaseCount('server_review_events', 3);
        $this->getJson('/api/admin/servers/'.$s->id.'/history')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_heartbeat_changes_do_not_invalidate_review_but_host_changes_do(): void
    {
        $s = Server::factory()->create(['review_status' => 'pending_review']);
        $version = $this->version($s);
        $s->update(['last_seen_at' => now()]);
        $this->assertSame($version, $this->version($s));
        $s->update(['host' => 'new.example.net']);
        $this->actingAs($this->admin(), 'sanctum')->postJson('/api/admin/servers/'.$s->id.'/moderate', ['action' => 'approve', 'version' => $version])->assertConflict();
    }

    public function test_activity_counts_reasons_player_gaps_and_period_bounds(): void
    {
        $s = Server::factory()->create();
        ServerHeartbeat::factory()->create(['server_id' => $s->id, 'online_players' => 12, 'received_at' => now()->subHour()]);
        foreach (['valid', 'invalid', 'pending'] as $status) {
            RawImpressionEvent::factory()->create(['server_id' => $s->id, 'validation_status' => $status, 'invalid_reason' => $status === 'invalid' ? 'duplicate' : null, 'received_at' => now()->subHour(), 'validated_at' => $status === 'pending' ? null : now()->subHour()]);
        }
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/servers/'.$s->id.'/activity?period=last24&validation=invalid')->assertOk()->assertJsonPath('data.metrics.valid', 1)->assertJsonPath('data.metrics.invalid', 1)->assertJsonPath('data.metrics.pending', 1)->assertJsonPath('data.metrics.invalid_rate', 50)->assertJsonPath('data.reasons.0.percent', 100)->assertJsonCount(1, 'data.impressions')->assertJsonPath('data.points.0.players', null);
        $this->getJson('/api/admin/servers/'.$s->id.'/activity?period=custom&from=2025-01-01&to=2026-10-30')->assertUnprocessable();
        $this->getJson('/api/admin/servers/'.$s->id.'/activity?period=custom&from=2026-10-30&to=2026-11-01')->assertJsonValidationErrors('to');
    }

    public function test_publisher_list_includes_earnings_and_restricts_suspended_active_count(): void
    {
        $s = Server::factory()->create();
        ServerHeartbeat::factory()->create(['server_id' => $s->id, 'received_at' => now()->subMinutes(30)]);
        $s->publisherAccount->user->update(['status' => 'suspended']);
        ValidatedImpression::factory()->create(['publisher_account_id' => $s->publisher_account_id, 'server_id' => $s->id, 'publisher_earning_micros' => 4200]);
        $this->actingAs($this->admin('finance'), 'sanctum')->getJson('/api/admin/publishers?search='.$s->publisherAccount->user->email)->assertOk()->assertJsonPath('data.0.active_servers_count', 0)->assertJsonPath('data.0.earnings_micros', 4200)->assertJsonPath('data.0.last_heartbeat_at', '2026-10-30T11:30:00.000000Z');
    }
}
