<?php

namespace Tests\Feature;

use App\Models\PublisherAccount;
use App\Models\PublisherLedgerEntry;
use App\Models\Server;
use App\Models\ServerApiToken;
use App\Models\ServerHeartbeat;
use App\Models\User;
use App\Models\ValidatedImpression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PublisherServersApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 10, 15)->setTime(12, 0));
    }

    public function test_creation_normalizes_host_and_returns_a_single_use_token_for_24_hours(): void
    {
        $publisher = PublisherAccount::factory()->create();

        $response = $this->actingAs($publisher->user)->postJson('/api/publisher/servers', [
            'name' => ' Cedar SMP ', 'host' => 'PLAY.Example.COM.:25565', 'category' => 'smp',
            'status' => 'active', 'publisher_account_id' => 999,
        ]);

        $response->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.server.host', 'play.example.com')
            ->assertJsonPath('data.server.name', 'Cedar SMP')
            ->assertJsonPath('data.server.status', 'pending')
            ->assertJsonPath('data.setup_token_expires_at', '2026-10-16T12:00:00.000000Z');
        $server = Server::query()->findOrFail($response->json('data.server.id'));
        $this->assertTrue(Hash::check($response->json('data.setup_token'), $server->setup_token_hash));
        $this->assertSame($publisher->id, $server->publisher_account_id);
        $this->assertNull($server->activated_at);

        $this->getJson('/api/publisher/servers/'.$server->id)->assertOk()->assertJsonMissingPath('data.setup_token')->assertJsonMissingPath('data.setup_token_hash');
    }

    #[TestWith(['https://example.com'])]
    #[TestWith(['play.example.com/path'])]
    #[TestWith(['example.com:65536'])]
    #[TestWith(['example.com:0'])]
    #[TestWith(['example.com:abc'])]
    #[TestWith(['bad host.com'])]
    #[TestWith(['999.999.999.999'])]
    public function test_creation_rejects_invalid_hosts(string $host): void
    {
        $publisher = PublisherAccount::factory()->create();

        $this->actingAs($publisher->user)->postJson('/api/publisher/servers', ['name' => 'Test', 'host' => $host, 'category' => 'survival'])
            ->assertUnprocessable()->assertJsonValidationErrors('host');
        $this->assertDatabaseCount('servers', 0);
    }

    #[TestWith(['192.0.2.1:25566', '192.0.2.1:25566'])]
    #[TestWith(['[2001:0db8::1]:25565', '[2001:db8::1]'])]
    #[TestWith(['2001:db8::1', '[2001:db8::1]'])]
    #[TestWith(['[2001:0db8::1]:025566', '[2001:db8::1]:25566'])]
    public function test_creation_accepts_ip_addresses_and_ports(string $host, string $expected): void
    {
        $publisher = PublisherAccount::factory()->create();

        $this->actingAs($publisher->user)->postJson('/api/publisher/servers', ['name' => 'Test', 'host' => $host, 'category' => 'survival'])
            ->assertCreated()->assertJsonPath('data.server.host', $expected);
    }

    public function test_creation_rejects_missing_fields_and_unknown_category(): void
    {
        $publisher = PublisherAccount::factory()->create();

        $this->actingAs($publisher->user)->postJson('/api/publisher/servers', ['category' => 'custom'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'host', 'category']);
    }

    public function test_creation_rejects_non_string_host_without_server_error(): void
    {
        $publisher = PublisherAccount::factory()->create();

        $this->actingAs($publisher->user)->postJson('/api/publisher/servers', ['name' => 'Test', 'host' => ['example.com'], 'category' => 'smp'])
            ->assertUnprocessable()->assertJsonValidationErrors('host');
    }

    public function test_duplicate_hosts_are_rejected_within_a_publisher_only(): void
    {
        $publisher = PublisherAccount::factory()->create();
        Server::factory()->create(['publisher_account_id' => $publisher->id, 'host' => 'play.example.com']);

        $this->actingAs($publisher->user)->postJson('/api/publisher/servers', ['name' => 'Duplicate', 'host' => 'PLAY.EXAMPLE.COM:25565', 'category' => 'smp'])
            ->assertUnprocessable()->assertJsonValidationErrors('host');
        $other = PublisherAccount::factory()->create();
        $this->actingAs($other->user)->postJson('/api/publisher/servers', ['name' => 'Other', 'host' => 'play.example.com', 'category' => 'smp'])->assertCreated();
    }

    public function test_list_uses_recent_heartbeats_for_status_and_excludes_stale_players(): void
    {
        config(['publisher.latest_plugin_version' => '2.0.0']);
        $publisher = PublisherAccount::factory()->create();
        $online = Server::factory()->create(['publisher_account_id' => $publisher->id, 'name' => 'Online', 'category' => 'smp']);
        $offline = Server::factory()->create(['publisher_account_id' => $publisher->id, 'name' => 'Offline']);
        ServerHeartbeat::factory()->create(['server_id' => $online->id, 'received_at' => now()->subMinutes(4), 'online_players' => 20, 'plugin_version' => '1.2.3']);
        ServerHeartbeat::factory()->create(['server_id' => $online->id, 'received_at' => now()->subMinutes(6), 'online_players' => 70]);
        ServerHeartbeat::factory()->create(['server_id' => $offline->id, 'received_at' => now()->subMinutes(5), 'online_players' => 99]);
        Server::factory()->create();

        $this->actingAs($publisher->user)->getJson('/api/publisher/servers?status=active&category=smp&search=Online')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $online->id)
            ->assertJsonPath('data.0.plugin_version', '1.2.3')->assertJsonPath('data.0.plugin_update_available', true)->assertJsonPath('meta.total_servers', 2)
            ->assertJsonPath('meta.active_servers', 1)->assertJsonPath('meta.online_players', 20)->assertJsonPath('meta.needs_attention', 1);
        $this->getJson('/api/publisher/servers/'.$offline->id)->assertOk()->assertJsonPath('data.status', 'offline')->assertJsonPath('data.online_players', 0);
    }

    public function test_setup_token_activates_once_and_regeneration_revokes_old_credentials(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $created = $this->actingAs($publisher->user)->postJson('/api/publisher/servers', ['name' => 'Test', 'host' => 'play.example.com', 'category' => 'survival'])->assertCreated();
        $id = $created->json('data.server.id');
        $token = $created->json('data.setup_token');

        $activation = $this->postJson('/api/plugin/v1/activate', ['setup_token' => $token])->assertCreated()->assertJsonPath('config.enabled', true);
        $this->postJson('/api/plugin/v1/activate', ['setup_token' => $token])->assertUnprocessable();
        $new = $this->postJson('/api/publisher/servers/'.$id.'/setup-token')->assertOk()->assertJsonPath('data.server.status', 'pending');
        $this->assertDatabaseMissing('server_api_tokens', ['server_id' => $id, 'revoked_at' => null]);
        $this->withHeader('Authorization', 'Bearer '.$activation->json('api_token'))->postJson('/api/plugin/v1/heartbeat')->assertUnauthorized();
        $this->postJson('/api/plugin/v1/activate', ['setup_token' => $new->json('data.setup_token')])->assertCreated();
    }

    public function test_expired_setup_token_cannot_activate(): void
    {
        Server::factory()->create(['status' => 'pending', 'setup_token_hash' => Hash::make('expired'), 'setup_token_expires_at' => now()]);

        $this->postJson('/api/plugin/v1/activate', ['setup_token' => 'expired'])->assertUnprocessable();
        $this->assertDatabaseCount('server_api_tokens', 0);
    }

    public function test_disabling_stops_delivery_and_enabling_preserves_plugin_credentials(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $server = Server::factory()->create(['publisher_account_id' => $publisher->id]);
        ServerApiToken::factory()->create(['server_id' => $server->id, 'token_hash' => hash('sha256', 'plugin-test')]);

        $this->actingAs($publisher->user)->patchJson('/api/publisher/servers/'.$server->id.'/status', ['enabled' => false])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->withHeader('Authorization', 'Bearer plugin-test')->postJson('/api/plugin/v1/heartbeat')->assertOk()->assertJsonPath('config.enabled', false);
        $this->patchJson('/api/publisher/servers/'.$server->id.'/status', ['enabled' => true])->assertOk();
        $this->postJson('/api/plugin/v1/heartbeat')->assertOk()->assertJsonPath('config.enabled', true);
    }

    public function test_removal_requires_exact_name_and_preserves_historical_data(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $server = Server::factory()->create(['publisher_account_id' => $publisher->id, 'name' => 'History']);
        $impression = ValidatedImpression::factory()->create(['server_id' => $server->id, 'publisher_account_id' => $publisher->id]);
        $earning = PublisherLedgerEntry::factory()->create(['server_id' => $server->id, 'publisher_account_id' => $publisher->id]);
        ServerApiToken::factory()->create(['server_id' => $server->id, 'token_hash' => hash('sha256', 'removed-plugin')]);

        $this->actingAs($publisher->user)->deleteJson('/api/publisher/servers/'.$server->id, ['name' => 'history'])->assertUnprocessable();
        $this->deleteJson('/api/publisher/servers/'.$server->id, ['name' => 'History'])->assertOk();
        $this->assertSoftDeleted($server);
        $this->assertModelExists($impression);
        $this->assertModelExists($earning);
        $this->getJson('/api/publisher/servers/'.$server->id)->assertNotFound();
        $this->getJson('/api/publisher/servers')->assertOk()->assertJsonCount(0, 'data');
        $this->withHeader('Authorization', 'Bearer removed-plugin')->postJson('/api/plugin/v1/heartbeat')->assertUnauthorized();
    }

    #[TestWith(['GET', ''])]
    #[TestWith(['GET', '/performance?from=2026-10-01&to=2026-10-15&group=day'])]
    #[TestWith(['POST', '/setup-token'])]
    #[TestWith(['PATCH', '/status'])]
    #[TestWith(['DELETE', ''])]
    public function test_other_publishers_cannot_access_or_mutate_a_server(string $method, string $suffix): void
    {
        $publisher = PublisherAccount::factory()->create();
        $server = Server::factory()->create(['name' => 'Other']);

        $this->actingAs($publisher->user)->json($method, '/api/publisher/servers/'.$server->id.$suffix, ['enabled' => false, 'name' => 'Other'])->assertNotFound();
        $this->assertDatabaseHas('servers', ['id' => $server->id, 'status' => 'active', 'deleted_at' => null]);
    }

    #[TestWith(['suspended'])]
    public function test_suspended_servers_cannot_be_enabled_or_reconnected(string $status): void
    {
        $publisher = PublisherAccount::factory()->create();
        $server = Server::factory()->create(['publisher_account_id' => $publisher->id, 'status' => $status]);

        $this->actingAs($publisher->user)->patchJson('/api/publisher/servers/'.$server->id.'/status', ['enabled' => true])->assertForbidden();
        $this->postJson('/api/publisher/servers/'.$server->id.'/setup-token')->assertForbidden();
    }

    public function test_performance_compares_equal_periods_and_calculates_weighted_cpm(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $server = Server::factory()->create(['publisher_account_id' => $publisher->id]);
        foreach ([['2026-10-14 12:00:00', 1000], ['2026-10-15 11:00:00', 3000], ['2026-10-12 12:00:00', 2000]] as [$date, $earnings]) {
            ValidatedImpression::factory()->create(['publisher_account_id' => $publisher->id, 'server_id' => $server->id, 'validated_at' => $date, 'publisher_earning_micros' => $earnings]);
        }
        ValidatedImpression::factory()->create(['publisher_earning_micros' => 999999]);

        $this->actingAs($publisher->user)->getJson('/api/publisher/servers/'.$server->id.'/performance?from=2026-10-14&to=2026-10-15&group=month&compare=1')->assertOk()
            ->assertJsonPath('data.previous_from', '2026-10-12')->assertJsonPath('data.previous_to', '2026-10-13')
            ->assertJsonPath('data.earnings_micros', 4000)->assertJsonPath('data.impressions', 2)->assertJsonCount(1, 'data.points')
            ->assertJsonPath('data.points.0.effective_cpm_micros', 2000000)->assertJsonPath('data.points.0.previous_earnings_micros', 2000)
            ->assertJsonPath('data.points.0.previous_effective_cpm_micros', 2000000);
        $this->getJson('/api/publisher/servers/'.$server->id)->assertOk()->assertJsonPath('data.earnings_today_micros', 3000)->assertJsonPath('data.earnings_month_micros', 6000);
    }

    #[TestWith(['from=2024-01-01&to=2026-10-15&group=day'])]
    #[TestWith(['from=2026-10-15&to=2026-10-14&group=day'])]
    #[TestWith(['from=2026-10-15&to=2026-10-16&group=day'])]
    #[TestWith(['from=2026-10-14&to=2026-10-15&group=bad'])]
    public function test_performance_rejects_invalid_ranges_and_groupings(string $query): void
    {
        $publisher = PublisherAccount::factory()->create();
        $server = Server::factory()->create(['publisher_account_id' => $publisher->id]);

        $this->actingAs($publisher->user)->getJson('/api/publisher/servers/'.$server->id.'/performance?'.$query)->assertUnprocessable();
    }

    public function test_guests_cannot_manage_servers(): void
    {
        $this->getJson('/api/publisher/servers')->assertUnauthorized();
    }

    #[TestWith(['advertiser'])]
    #[TestWith(['admin'])]
    public function test_other_roles_cannot_manage_servers(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->getJson('/api/publisher/servers')->assertForbidden();
    }

    public function test_suspended_publisher_cannot_manage_servers(): void
    {
        $publisher = PublisherAccount::factory()->create(['status' => 'suspended']);

        $this->actingAs($publisher->user)->getJson('/api/publisher/servers')->assertForbidden();
    }
}
