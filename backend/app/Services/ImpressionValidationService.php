<?php

namespace App\Services;

use App\Models\AdDelivery;
use App\Models\AdvertiserLedgerEntry;
use App\Models\PublisherLedgerEntry;
use App\Models\RawImpressionEvent;
use App\Models\Server;
use App\Models\ServerHeartbeat;
use App\Models\ValidatedImpression;
use Illuminate\Support\Facades\DB;

class ImpressionValidationService
{
    private const DISPLAY_EARLY_GRACE_SECONDS = 30;

    private const REPORTING_GRACE_MINUTES = 5;

    private const RECENT_HEARTBEAT_MINUTES = 5;

    private const ONLINE_PLAYER_MARGIN = 2;

    private const MAX_VALID_IMPRESSIONS_PER_PLAYER_PER_HOUR = 4;

    public function __construct(
        private readonly CampaignBudgetService $campaignBudget
    ) {}

    public function validate(int $rawImpressionEventId): void
    {
        DB::transaction(function () use ($rawImpressionEventId): void {
            $raw = RawImpressionEvent::query()
                ->with(['adDelivery.campaign', 'playerSession'])
                ->lockForUpdate()
                ->find($rawImpressionEventId);

            if (! $raw || $raw->validation_status !== 'pending') {
                return;
            }

            $delivery = $raw->adDelivery;
            $server = Server::query()->find($raw->server_id);

            $decision = $this->decide($raw, $delivery, $server);

            $raw->update([
                'validation_status' => $decision['status'],
                'invalid_reason' => $decision['reason'],
                'risk_score' => $decision['risk_score'],
                'validated_at' => now(),
            ]);

            if ($decision['status'] !== 'valid' || ! $delivery || ! $server) {
                return;
            }

            $this->billValidatedImpression($raw, $delivery, $server);
        });
    }

    /**
     * @return array{status: string, reason: ?string, risk_score: int}
     */
    private function decide(RawImpressionEvent $raw, ?AdDelivery $delivery, ?Server $server): array
    {
        if (! $delivery || ! $server) {
            return $this->invalid('missing_delivery_or_server');
        }

        if ((int) $delivery->server_id !== (int) $raw->server_id) {
            return $this->invalid('delivery_server_mismatch');
        }

        if ($delivery->decision !== 'served') {
            return $this->invalid('delivery_not_served');
        }

        if (! $delivery->campaign_id || ! $delivery->creative_id || ! $delivery->cpm_micros) {
            return $this->invalid('delivery_not_billable');
        }

        if ($delivery->requested_at && $raw->displayed_at->lt($delivery->requested_at->copy()->subSeconds(self::DISPLAY_EARLY_GRACE_SECONDS))) {
            return $this->invalid('displayed_before_delivery');
        }

        if ($delivery->expires_at && $raw->displayed_at->gt($delivery->expires_at)) {
            return $this->invalid('displayed_after_token_expiry');
        }

        if ($raw->received_at->lt($raw->displayed_at->copy()->subSeconds(self::DISPLAY_EARLY_GRACE_SECONDS))) {
            return $this->invalid('received_before_display');
        }

        if ($raw->received_at->gt($raw->displayed_at->copy()->addMinutes(self::REPORTING_GRACE_MINUTES))) {
            return $this->invalid('reported_too_late');
        }

        $heartbeat = $this->recentHeartbeat($raw);

        if (! $heartbeat) {
            return $this->invalid('missing_recent_heartbeat');
        }

        if ($raw->playerSession && (int) $raw->playerSession->server_id !== (int) $raw->server_id) {
            return $this->invalid('player_session_server_mismatch');
        }

        if ($raw->playerSession?->last_seen_at && $raw->playerSession->last_seen_at->lt($raw->received_at->copy()->subMinutes(15))) {
            return $this->invalid('stale_player_session', 50);
        }

        if ($this->deliveryImpressionCount($raw) > ($heartbeat->online_players + self::ONLINE_PLAYER_MARGIN)) {
            return $this->invalid('impressions_exceed_recent_online_players', 70);
        }

        if ($this->recentPlayerImpressionCount($raw) >= self::MAX_VALID_IMPRESSIONS_PER_PLAYER_PER_HOUR) {
            return $this->invalid('player_frequency_cap_exceeded', 60);
        }

        if (! $delivery->campaign || ! $this->campaignBudget->canBill(
            $delivery->campaign,
            $this->campaignBudget->billableMicros($delivery->cpm_micros),
            $raw->displayed_at,
            lockAccount: true,
        )) {
            return $this->invalid('insufficient_campaign_budget_or_balance');
        }

        return [
            'status' => 'valid',
            'reason' => null,
            'risk_score' => 0,
        ];
    }

    private function recentHeartbeat(RawImpressionEvent $raw): ?ServerHeartbeat
    {
        return ServerHeartbeat::query()
            ->where('server_id', $raw->server_id)
            ->where('received_at', '<=', $raw->received_at)
            ->where('received_at', '>=', $raw->received_at->copy()->subMinutes(self::RECENT_HEARTBEAT_MINUTES))
            ->latest('received_at')
            ->first();
    }

    private function deliveryImpressionCount(RawImpressionEvent $raw): int
    {
        return RawImpressionEvent::query()
            ->where('ad_delivery_id', $raw->ad_delivery_id)
            ->where(function ($query) {
                $query->whereIn('validation_status', ['pending', 'valid', 'review'])
                    ->orWhere(function ($query) {
                        $query->where('validation_status', 'invalid')
                            ->whereIn('invalid_reason', [
                                'stale_player_session',
                                'impressions_exceed_recent_online_players',
                                'player_frequency_cap_exceeded',
                            ]);
                    });
            })
            ->count();
    }

    private function recentPlayerImpressionCount(RawImpressionEvent $raw): int
    {
        return RawImpressionEvent::query()
            ->where('id', '!=', $raw->id)
            ->where('player_hash', $raw->player_hash)
            ->where('displayed_at', '>=', $raw->displayed_at->copy()->subHour())
            ->where('displayed_at', '<=', $raw->displayed_at)
            ->where('validation_status', 'valid')
            ->count();
    }

    private function billValidatedImpression(RawImpressionEvent $raw, AdDelivery $delivery, Server $server): void
    {
        if (ValidatedImpression::query()->where('raw_impression_event_id', $raw->id)->exists()) {
            return;
        }

        $campaign = $delivery->campaign;

        if (! $campaign || ! $delivery->cpm_micros) {
            return;
        }

        $billableMicros = $this->campaignBudget->billableMicros($delivery->cpm_micros);
        $publisherEarningMicros = (int) floor($billableMicros * 0.7);
        $platformFeeMicros = $billableMicros - $publisherEarningMicros;

        ValidatedImpression::query()->create([
            'raw_impression_event_id' => $raw->id,
            'advertiser_account_id' => $campaign->advertiser_account_id,
            'publisher_account_id' => $server->publisher_account_id,
            'campaign_id' => $campaign->id,
            'creative_id' => $delivery->creative_id,
            'server_id' => $server->id,
            'player_hash' => $raw->player_hash,
            'billable_micros' => $billableMicros,
            'publisher_earning_micros' => $publisherEarningMicros,
            'platform_fee_micros' => $platformFeeMicros,
            'validated_at' => now(),
        ]);

        AdvertiserLedgerEntry::query()->create([
            'advertiser_account_id' => $campaign->advertiser_account_id,
            'campaign_id' => $campaign->id,
            'type' => 'spend',
            'amount_micros' => -1 * $billableMicros,
            'currency' => 'USD',
            'reference_type' => 'raw_impression_event',
            'reference_id' => $raw->id,
        ]);

        PublisherLedgerEntry::query()->create([
            'publisher_account_id' => $server->publisher_account_id,
            'server_id' => $server->id,
            'type' => 'pending_earning',
            'amount_micros' => $publisherEarningMicros,
            'currency' => 'USD',
            'reference_type' => 'raw_impression_event',
            'reference_id' => $raw->id,
        ]);
    }

    /**
     * @return array{status: string, reason: string, risk_score: int}
     */
    private function invalid(string $reason, int $riskScore = 100): array
    {
        return [
            'status' => 'invalid',
            'reason' => $reason,
            'risk_score' => $riskScore,
        ];
    }
}
