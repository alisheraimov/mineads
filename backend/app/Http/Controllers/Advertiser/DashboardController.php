<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\AdCreative;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\ValidatedImpression;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $advertiser = $request->user()
            ?->advertiserAccount()
            ->first();

        if (! $advertiser) {
            return response()->json([
                'message' => 'Advertiser account is not set up.',
            ], 409);
        }

        $cacheKey = "advertiser_dashboard:{$advertiser->id}";

        $payload = Cache::remember(
            $cacheKey,
            now()->addSeconds(60),
            fn (): array => $this->dashboardPayload($advertiser),
        );

        return response()->json(['data' => $payload]);
    }

    private function dashboardPayload(AdvertiserAccount $advertiser): array
    {
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $monthStart = $now->copy()->startOfMonth();
        $chartStart = $now->copy()->subDays(29)->startOfDay();

        $balanceMicros = (int) AdvertiserLedgerEntry::query()
            ->where('advertiser_account_id', $advertiser->id)
            ->sum('amount_micros');

        $spendTodayMicros = $this->spendMicros($advertiser->id, $todayStart, $now);
        $spendMonthMicros = $this->spendMicros($advertiser->id, $monthStart, $now);
        $impressionsToday = $this->impressionCount($advertiser->id, $todayStart, $now);
        $impressionsMonth = $this->impressionCount($advertiser->id, $monthStart, $now);

        return [
            'account' => [
                'id' => $advertiser->id,
                'name' => $advertiser->name,
                'status' => $advertiser->status,
                'currency' => $advertiser->currency,
            ],
            'period' => [
                'today_start' => $todayStart->toISOString(),
                'month_start' => $monthStart->toISOString(),
                'month_end' => $now->toISOString(),
            ],
            'metrics' => [
                'available_balance_micros' => $balanceMicros,
                'active_campaigns' => $this->campaignCount($advertiser->id, 'active'),
                'active_creatives' => $this->creativeCount($advertiser->id, 'approved', activeCampaignsOnly: true),
                'pending_creatives' => $this->creativeCount($advertiser->id, 'pending_review'),
                'spend_today_micros' => $spendTodayMicros,
                'spend_month_micros' => $spendMonthMicros,
                'impressions_today' => $impressionsToday,
                'impressions_month' => $impressionsMonth,
                'effective_cpm_micros' => $impressionsMonth > 0
                    ? (int) round(($spendMonthMicros / $impressionsMonth) * 1000)
                    : 0,
            ],
            'spend_chart' => $this->spendChart($advertiser->id, $chartStart, $now),
            'running_campaigns' => $this->runningCampaigns($advertiser->id),
            'generated_at' => $now->toISOString(),
        ];
    }

    private function campaignCount(int $advertiserAccountId, string $status): int
    {
        return Campaign::query()
            ->where('advertiser_account_id', $advertiserAccountId)
            ->where('status', $status)
            ->count();
    }

    private function creativeCount(int $advertiserAccountId, string $status, bool $activeCampaignsOnly = false): int
    {
        return AdCreative::query()
            ->where('status', $status)
            ->when($activeCampaignsOnly, fn ($query) => $query->where('is_paused', false))
            ->whereHas('campaign', function ($query) use ($advertiserAccountId, $activeCampaignsOnly): void {
                $query->where('advertiser_account_id', $advertiserAccountId);

                if ($activeCampaignsOnly) {
                    $query->where('status', 'active');
                }
            })
            ->count();
    }

    private function spendMicros(int $advertiserAccountId, CarbonInterface $start, CarbonInterface $end): int
    {
        return abs((int) AdvertiserLedgerEntry::query()
            ->where('advertiser_account_id', $advertiserAccountId)
            ->where('type', 'spend')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount_micros'));
    }

    private function impressionCount(int $advertiserAccountId, CarbonInterface $start, CarbonInterface $end): int
    {
        return ValidatedImpression::query()
            ->where('advertiser_account_id', $advertiserAccountId)
            ->whereBetween('validated_at', [$start, $end])
            ->count();
    }

    /**
     * @return array<int, array{date: string, label: string, spend_micros: int}>
     */
    private function spendChart(int $advertiserAccountId, CarbonInterface $start, CarbonInterface $end): array
    {
        $entries = AdvertiserLedgerEntry::query()
            ->where('advertiser_account_id', $advertiserAccountId)
            ->where('type', 'spend')
            ->whereBetween('created_at', [$start, $end])
            ->get(['amount_micros', 'created_at'])
            ->groupBy(fn (AdvertiserLedgerEntry $entry) => $entry->created_at->toDateString())
            ->map(fn (Collection $entries) => abs((int) $entries->sum('amount_micros')));

        return collect(range(0, 29))
            ->map(function (int $offset) use ($start, $entries): array {
                $day = $start->copy()->addDays($offset);

                return [
                    'date' => $day->toDateString(),
                    'label' => $day->format('M j'),
                    'spend_micros' => (int) ($entries[$day->toDateString()] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function runningCampaigns(int $advertiserAccountId): array
    {
        $campaigns = Campaign::query()
            ->where('advertiser_account_id', $advertiserAccountId)
            ->where('status', 'active')
            ->latest('updated_at')
            ->limit(5)
            ->get(['id', 'name', 'status', 'daily_budget_micros']);

        if ($campaigns->isEmpty()) {
            return [];
        }

        $campaignIds = $campaigns->pluck('id');

        $spendByCampaign = AdvertiserLedgerEntry::query()
            ->whereIn('campaign_id', $campaignIds)
            ->where('type', 'spend')
            ->get(['campaign_id', 'amount_micros'])
            ->groupBy('campaign_id')
            ->map(fn (Collection $entries) => abs((int) $entries->sum('amount_micros')));

        $impressionsByCampaign = ValidatedImpression::query()
            ->whereIn('campaign_id', $campaignIds)
            ->selectRaw('campaign_id, count(*) as aggregate')
            ->groupBy('campaign_id')
            ->pluck('aggregate', 'campaign_id');

        return $campaigns
            ->map(fn (Campaign $campaign): array => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'daily_budget_micros' => $campaign->daily_budget_micros,
                'spend_micros' => (int) ($spendByCampaign[$campaign->id] ?? 0),
                'impressions' => (int) ($impressionsByCampaign[$campaign->id] ?? 0),
            ])
            ->values()
            ->all();
    }
}
