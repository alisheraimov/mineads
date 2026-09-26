<?php

namespace App\Http\Controllers;

use App\Jobs\ValidateRawImpressionEvent;
use App\Models\AdCreative;
use App\Models\AdDelivery;
use App\Models\Campaign;
use App\Models\PlayerSession;
use App\Models\RateTier;
use App\Models\RawImpressionEvent;
use App\Models\Server;
use App\Models\ServerApiToken;
use App\Models\ServerHeartbeat;
use App\Services\CampaignBudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PluginApiController extends Controller
{
    public function __construct(
        private readonly CampaignBudgetService $campaignBudget
    ) {}

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'setup_token' => ['required', 'string'],
        ]);

        $server = Server::query()
            ->where('status', 'pending')
            ->whereNotNull('setup_token_hash')
            ->where(function ($query) {
                $query->whereNull('setup_token_expires_at')
                    ->orWhere('setup_token_expires_at', '>', now());
            })
            ->get()
            ->first(fn (Server $server) => Hash::check($data['setup_token'], $server->setup_token_hash));

        if (! $server) {
            return response()->json([
                'message' => 'Invalid or expired setup token.',
            ], 422);
        }

        $plainToken = 'ma_srv_'.Str::random(48);

        DB::transaction(function () use ($server, $plainToken): void {
            $server->update([
                'status' => 'active',
                'setup_token_hash' => null,
                'setup_token_expires_at' => null,
                'activated_at' => now(),
                'last_seen_at' => now(),
            ]);

            ServerApiToken::query()->create([
                'server_id' => $server->id,
                'name' => 'Primary plugin token',
                'token_hash' => hash('sha256', $plainToken),
                'last_used_at' => now(),
            ]);
        });

        return response()->json([
            'server_id' => $server->id,
            'api_token' => $plainToken,
            'config' => $this->pluginConfig($server),
        ], 201);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $server = $this->authenticateServer($request);

        if (! $server) {
            return $this->unauthorized();
        }

        $data = $request->validate([
            'plugin_version' => ['nullable', 'string', 'max:64'],
            'minecraft_version' => ['nullable', 'string', 'max:64'],
            'platform' => ['nullable', 'string', 'max:64'],
            'online_players' => ['nullable', 'integer', 'min:0'],
            'max_players' => ['nullable', 'integer', 'min:0'],
            'tps' => ['nullable', 'numeric', 'min:0', 'max:20'],
        ]);

        ServerHeartbeat::query()->create([
            'server_id' => $server->id,
            'plugin_version' => $data['plugin_version'] ?? null,
            'minecraft_version' => $data['minecraft_version'] ?? null,
            'platform' => $data['platform'] ?? null,
            'online_players' => $data['online_players'] ?? 0,
            'max_players' => $data['max_players'] ?? null,
            'tps' => $data['tps'] ?? null,
            'received_at' => now(),
        ]);

        $server->update(['last_seen_at' => now()]);

        return response()->json([
            'ok' => true,
            'server_status' => $server->status,
            'config' => $this->pluginConfig($server),
        ]);
    }

    public function requestAds(Request $request): JsonResponse
    {
        $server = $this->authenticateServer($request);

        if (! $server) {
            return $this->unauthorized();
        }

        $data = $request->validate([
            'placement' => ['required', 'string', 'in:chat'],
            'request_id' => ['nullable', 'string', 'max:128'],
            'audiences' => ['required', 'array', 'min:1', 'max:20'],
            'audiences.*.audience_id' => ['required', 'string', 'max:128'],
            'audiences.*.country' => ['nullable', 'string', 'size:2'],
            'audiences.*.language' => ['nullable', 'string', 'max:16'],
            'audiences.*.eligible_player_count' => ['required', 'integer', 'min:0'],
            'audiences.*.players' => ['nullable', 'array', 'max:250'],
            'audiences.*.players.*.player_session_id' => ['required_with:audiences.*.players', 'string', 'max:128'],
            'audiences.*.players.*.player_hash' => ['required_with:audiences.*.players', 'string', 'max:128'],
        ]);

        $decisions = collect($data['audiences'])->map(function (array $audience) use ($server, $data) {
            $country = isset($audience['country']) ? strtoupper($audience['country']) : null;
            $language = isset($audience['language']) ? strtolower($audience['language']) : null;
            $eligiblePlayerCount = (int) $audience['eligible_player_count'];

            $this->upsertPlayerSessions($server, $audience['players'] ?? [], $country, $language);

            if ($eligiblePlayerCount < 1 || $server->status !== 'active') {
                return $this->recordNoFill($server, $data['placement'], $audience, $country, $language);
            }

            $rateTier = $this->findRateTier($country);
            $campaign = $rateTier ? $this->findCampaign($server, $country, $language, $rateTier, $eligiblePlayerCount) : null;
            $creative = $campaign ? $this->findCreative($campaign, $language) : null;

            if (! $campaign || ! $creative || ! $rateTier) {
                return $this->recordNoFill($server, $data['placement'], $audience, $country, $language);
            }

            $plainImpressionToken = 'imp_'.Str::random(48);

            $delivery = AdDelivery::query()->create([
                'server_id' => $server->id,
                'campaign_id' => $campaign->id,
                'creative_id' => $creative->id,
                'rate_tier_id' => $rateTier->id,
                'cpm_micros' => $rateTier->cpm_micros,
                'placement' => $data['placement'],
                'audience_country' => $country,
                'audience_language' => $language,
                'requested_player_count' => $eligiblePlayerCount,
                'decision' => 'served',
                'impression_token_hash' => hash('sha256', $plainImpressionToken),
                'expires_at' => now()->addMinutes(5),
                'requested_at' => now(),
            ]);

            return [
                'audience_id' => $audience['audience_id'],
                'decision' => 'serve',
                'ad_delivery_id' => $delivery->id,
                'campaign_id' => $campaign->id,
                'creative_id' => $creative->id,
                'placement' => $data['placement'],
                'message' => [
                    'plain' => $creative->message_text,
                    'click_url' => $creative->click_url,
                ],
                'tracking' => [
                    'impression_token' => $plainImpressionToken,
                    'expires_at' => $delivery->expires_at?->toISOString(),
                ],
            ];
        })->values();

        $server->update(['last_seen_at' => now()]);

        return response()->json(['decisions' => $decisions]);
    }

    public function impressions(Request $request): JsonResponse
    {
        $server = $this->authenticateServer($request);

        if (! $server) {
            return $this->unauthorized();
        }

        $data = $request->validate([
            'ad_delivery_id' => ['required', 'integer', 'exists:ad_deliveries,id'],
            'impression_token' => ['required', 'string'],
            'displayed_at' => ['required', 'date'],
            'players' => ['required', 'array', 'min:1', 'max:500'],
            'players.*.player_session_id' => ['nullable', 'string', 'max:128'],
            'players.*.player_hash' => ['required', 'string', 'max:128'],
        ]);

        $delivery = AdDelivery::query()
            ->where('id', $data['ad_delivery_id'])
            ->where('server_id', $server->id)
            ->first();

        if (! $delivery || $delivery->decision !== 'served') {
            return response()->json(['message' => 'Unknown ad delivery.'], 404);
        }

        if (! $delivery->impression_token_hash || ! hash_equals($delivery->impression_token_hash, hash('sha256', $data['impression_token']))) {
            return response()->json(['message' => 'Invalid impression token.'], 422);
        }

        if ($delivery->expires_at && $delivery->expires_at->isPast()) {
            return response()->json(['message' => 'Impression token has expired.'], 422);
        }

        $accepted = 0;
        $duplicates = 0;
        $rawImpressionEventIds = [];

        DB::transaction(function () use ($data, $server, $delivery, &$accepted, &$duplicates, &$rawImpressionEventIds): void {
            foreach ($data['players'] as $player) {
                $session = null;

                if (! empty($player['player_session_id'])) {
                    $session = PlayerSession::query()
                        ->where('server_id', $server->id)
                        ->where('session_token', $player['player_session_id'])
                        ->first();
                }

                $raw = RawImpressionEvent::query()->firstOrCreate(
                    [
                        'ad_delivery_id' => $delivery->id,
                        'player_hash' => $player['player_hash'],
                    ],
                    [
                        'server_id' => $server->id,
                        'player_session_id' => $session?->id,
                        'displayed_at' => $data['displayed_at'],
                        'received_at' => now(),
                        'validation_status' => 'pending',
                    ],
                );

                if (! $raw->wasRecentlyCreated) {
                    $duplicates++;

                    continue;
                }

                $rawImpressionEventIds[] = $raw->id;
                $accepted++;
            }
        });

        foreach ($rawImpressionEventIds as $rawImpressionEventId) {
            ValidateRawImpressionEvent::dispatch($rawImpressionEventId);
        }

        $server->update(['last_seen_at' => now()]);

        return response()->json([
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'status' => 'queued',
        ]);
    }

    private function authenticateServer(Request $request): ?Server
    {
        $plainToken = $request->bearerToken();

        if (! $plainToken) {
            return null;
        }

        $token = ServerApiToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->first();

        if (! $token) {
            return null;
        }

        $token->update(['last_used_at' => now()]);

        return $token->server;
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    private function pluginConfig(Server $server): array
    {
        return [
            'enabled' => $server->status === 'active',
            'placements' => [
                'chat' => [
                    'enabled' => $server->status === 'active',
                    'min_seconds_between_ads' => 300,
                    'max_ads_per_player_per_hour' => 4,
                    'min_online_players' => 1,
                ],
            ],
            'reporting' => [
                'batch_size' => 100,
                'flush_seconds' => 30,
            ],
        ];
    }

    private function upsertPlayerSessions(Server $server, array $players, ?string $country, ?string $language): void
    {
        foreach ($players as $player) {
            PlayerSession::query()->updateOrCreate(
                [
                    'server_id' => $server->id,
                    'session_token' => $player['player_session_id'],
                ],
                [
                    'player_hash' => $player['player_hash'],
                    'country_code' => $country,
                    'language' => $language,
                    'joined_at' => now(),
                    'last_seen_at' => now(),
                ],
            );
        }
    }

    private function findCampaign(Server $server, ?string $country, ?string $language, RateTier $rateTier, int $expectedImpressions): ?Campaign
    {
        return Campaign::query()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->whereHas('creatives', function ($query) use ($language) {
                $query->where('status', 'approved')
                    ->where(function ($query) use ($language) {
                        $query->whereNull('language');

                        if ($language) {
                            $query->orWhere('language', $language);
                        }
                    });
            })
            ->whereHas('targets', function ($query) use ($server, $country, $language) {
                $query->where(function ($query) use ($country) {
                    $query->whereNull('country_code');

                    if ($country) {
                        $query->orWhere('country_code', $country);
                    }
                })->where(function ($query) use ($language) {
                    $query->whereNull('language');

                    if ($language) {
                        $query->orWhere('language', $language);
                    }
                })->where(function ($query) use ($server) {
                    $query->whereNull('server_category');

                    if ($server->category) {
                        $query->orWhere('server_category', $server->category);
                    }
                });
            })
            ->inRandomOrder()
            ->get()
            ->first(fn (Campaign $campaign) => $this->campaignBudget->canServe($campaign, $rateTier->cpm_micros, $expectedImpressions));
    }

    private function findRateTier(?string $country): ?RateTier
    {
        if ($country) {
            $countryTier = RateTier::query()
                ->whereHas('countries', fn ($query) => $query->where('country_code', $country))
                ->first();

            if ($countryTier) {
                return $countryTier;
            }
        }

        return RateTier::query()
            ->where('is_default', true)
            ->orderBy('sort_order')
            ->first();
    }

    private function findCreative(Campaign $campaign, ?string $language): ?AdCreative
    {
        return $campaign->creatives()
            ->where('status', 'approved')
            ->where(function ($query) use ($language) {
                $query->whereNull('language');

                if ($language) {
                    $query->orWhere('language', $language);
                }
            })
            ->when($language, fn ($query) => $query->orderByRaw('case when language = ? then 0 else 1 end', [$language]))
            ->first();
    }

    private function recordNoFill(Server $server, string $placement, array $audience, ?string $country, ?string $language): array
    {
        AdDelivery::query()->create([
            'server_id' => $server->id,
            'placement' => $placement,
            'audience_country' => $country,
            'audience_language' => $language,
            'requested_player_count' => (int) $audience['eligible_player_count'],
            'decision' => 'no_fill',
            'requested_at' => now(),
        ]);

        return [
            'audience_id' => $audience['audience_id'],
            'decision' => 'no_fill',
            'retry_after_seconds' => 120,
        ];
    }
}
