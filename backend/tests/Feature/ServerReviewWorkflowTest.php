<?php

namespace Tests\Feature;

use App\Models\AdCreative;
use App\Models\AdDelivery;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\PublisherAccount;
use App\Models\RawImpressionEvent;
use App\Models\Server;
use App\Models\ServerHeartbeat;
use App\Models\User;
use App\Services\ImpressionValidationService;
use App\Services\ServerReviewService;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 10, 30)->setTime(12, 0));
    }

    public function test_new_activation_submits_for_review_and_approval_enables_plugin(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $created = $this->actingAs($publisher->user, 'sanctum')->postJson('/api/publisher/servers', ['name' => 'Review Test', 'host' => 'review.example.net', 'category' => 'smp'])->assertCreated()->assertJsonPath('data.server.review_status', 'awaiting_activation');
        $id = $created->json('data.server.id');
        $activated = $this->postJson('/api/plugin/v1/activate', ['setup_token' => $created->json('data.setup_token')])->assertCreated()->assertJsonPath('config.enabled', false);
        $token = $activated->json('api_token');
        $this->withToken($token)->postJson('/api/plugin/v1/heartbeat', ['online_players' => 15])->assertOk()->assertJsonPath('config.enabled', false);
        $this->postJson('/api/plugin/v1/ads/request', ['placement' => 'chat', 'audiences' => [['audience_id' => 'test', 'eligible_player_count' => 15]]])->assertOk()->assertJsonPath('decisions.0.decision', 'no_fill');
        $this->seed(AdminPermissionSeeder::class);
        $owner = User::factory()->create(['role' => 'admin']);
        $owner->assignRole('admin-owner');
        $server = Server::findOrFail($id);
        $this->actingAs($owner, 'sanctum')->postJson('/api/admin/servers/'.$id.'/moderate', ['action' => 'approve', 'version' => app(ServerReviewService::class)->version($server)])->assertOk();
        $this->withToken($token)->postJson('/api/plugin/v1/heartbeat')->assertOk()->assertJsonPath('config.enabled', true);
    }

    public function test_host_change_blocks_serving_name_changes_keep_approval_and_rejection_can_be_resubmitted(): void
    {
        $s = Server::factory()->create(['host' => 'old.example.net']);
        $u = $s->publisherAccount->user;
        $this->actingAs($u, 'sanctum')->putJson('/api/publisher/servers/'.$s->id, ['name' => 'New name', 'host' => 'old.example.net', 'category' => 'smp', 'review_status' => 'approved'])->assertOk()->assertJsonPath('data.review_status', 'approved');
        $this->putJson('/api/publisher/servers/'.$s->id, ['name' => 'New name', 'host' => 'new.example.net', 'category' => 'smp', 'review_status' => 'approved'])->assertOk()->assertJsonPath('data.review_status', 'pending_review')->assertJsonPath('data.legacy_approval', false);
        $this->assertDatabaseHas('server_review_events', ['server_id' => $s->id, 'action' => 'host_changed']);
        $this->seed(AdminPermissionSeeder::class);
        $owner = User::factory()->create(['role' => 'admin']);
        $owner->assignRole('ad-ops');
        $this->actingAs($owner, 'sanctum')->postJson('/api/admin/servers/'.$s->id.'/moderate', ['action' => 'reject', 'reason' => 'Unable to join server', 'version' => app(ServerReviewService::class)->version($s->fresh())])->assertOk();
        $this->actingAs($u, 'sanctum')->getJson('/api/publisher/servers/'.$s->id)->assertOk()->assertJsonPath('data.review_reason', 'Unable to join server');
        $this->postJson('/api/publisher/servers/'.$s->id.'/resubmit')->assertOk()->assertJsonPath('data.review_status', 'pending_review')->assertJsonPath('data.review_reason', null);
        $this->assertDatabaseHas('server_review_events', ['server_id' => $s->id, 'action' => 'rejected']);
    }

    public function test_cross_tenant_edits_and_suspended_server_changes_are_refused(): void
    {
        $s = Server::factory()->create();
        $this->actingAs(PublisherAccount::factory()->create()->user, 'sanctum')->putJson('/api/publisher/servers/'.$s->id, ['name' => 'Stolen', 'host' => 'stolen.example.net', 'category' => 'smp'])->assertNotFound();
        $this->postJson('/api/publisher/servers/'.$s->id.'/resubmit')->assertNotFound();
        $s->update(['status' => 'suspended']);
        $this->actingAs($s->publisherAccount->user, 'sanctum')->putJson('/api/publisher/servers/'.$s->id, ['name' => 'Bypass', 'host' => 'bypass.example.net', 'category' => 'smp'])->assertForbidden();
    }

    public function test_rollout_approves_only_previously_activated_servers_and_preserves_disabled_state(): void
    {
        $active = Server::factory()->create();
        $disabled = Server::factory()->create(['status' => 'inactive']);
        $pending = Server::factory()->create(['activated_at' => null, 'status' => 'pending']);
        $migration = require database_path('migrations/2026_10_30_033440_add_server_review_workflow.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('servers', ['id' => $active->id, 'review_status' => 'approved', 'legacy_approval' => true]);
        $this->assertDatabaseHas('servers', ['id' => $disabled->id, 'status' => 'inactive', 'review_status' => 'approved']);
        $this->assertDatabaseHas('servers', ['id' => $pending->id, 'review_status' => 'awaiting_activation', 'legacy_approval' => false]);
        $this->assertDatabaseCount('server_review_events', 0);
    }

    public function test_impressions_displayed_before_deletion_are_billable_but_later_displays_are_invalid(): void
    {
        $s = Server::factory()->create();
        $campaign = Campaign::factory()->create(['status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        AdvertiserLedgerEntry::factory()->create(['advertiser_account_id' => $campaign->advertiser_account_id, 'campaign_id' => null, 'type' => 'deposit', 'amount_micros' => 100000000]);
        $creative = AdCreative::factory()->create(['campaign_id' => $campaign->id, 'status' => 'approved']);
        $delivery = AdDelivery::factory()->create(['server_id' => $s->id, 'campaign_id' => $campaign->id, 'creative_id' => $creative->id, 'requested_at' => now()->subMinutes(2), 'expires_at' => now()->addMinutes(3), 'decision' => 'served', 'cpm_micros' => 2000000]);
        ServerHeartbeat::factory()->create(['server_id' => $s->id, 'received_at' => now()->subMinute(), 'online_players' => 30]);
        $old = RawImpressionEvent::factory()->create(['server_id' => $s->id, 'ad_delivery_id' => $delivery->id, 'player_session_id' => null, 'displayed_at' => now()->subMinute(), 'received_at' => now(), 'validation_status' => 'pending']);
        $late = RawImpressionEvent::factory()->create(['server_id' => $s->id, 'ad_delivery_id' => $delivery->id, 'player_session_id' => null, 'displayed_at' => now(), 'received_at' => now(), 'validation_status' => 'pending']);
        $s->delete();
        app(ServerReviewService::class)->record($s, null, 'deleted');
        app(ImpressionValidationService::class)->validate($old->id);
        app(ImpressionValidationService::class)->validate($late->id);
        $this->assertDatabaseHas('raw_impression_events', ['id' => $old->id, 'validation_status' => 'valid']);
        $this->assertDatabaseHas('validated_impressions', ['raw_impression_event_id' => $old->id, 'publisher_earning_micros' => 1400]);
        $this->assertDatabaseHas('raw_impression_events',['id' => $late->id, 'validation_status' => 'invalid', 'invalid_reason' => 'server_withdrawn_before_display', 'risk_score' => 0]);
    }
}
