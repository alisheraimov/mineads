<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClickMetricsService
{
    public function query(int $advertiserAccountId): Builder
    {
        return DB::table('raw_click_events as clicks')
            ->join('ad_deliveries as delivery', 'delivery.id', '=', 'clicks.ad_delivery_id')
            ->join('campaigns as campaign', 'campaign.id', '=', 'delivery.campaign_id')
            ->where('campaign.advertiser_account_id', $advertiserAccountId);
    }

    /** @return array{clicks: int, raw_clicks: int, ctr: float|null} */
    public function metrics(Builder $query, int $impressions): array
    {
        $totals = $query->selectRaw('COUNT(*) as raw_clicks, SUM(CASE WHEN clicks.is_unique THEN 1 ELSE 0 END) as unique_clicks')->first();
        $clicks = (int) ($totals->unique_clicks ?? 0);

        return ['clicks' => $clicks, 'raw_clicks' => (int) $totals->raw_clicks,
            'ctr' => $impressions > 0 ? round($clicks * 100 / $impressions, 2) : null];
    }

    /** @return Collection<int, object> */
    public function grouped(Builder $query, string $dimension): Collection
    {
        return $query->select($dimension)->selectRaw('COUNT(*) as raw_clicks, SUM(CASE WHEN clicks.is_unique THEN 1 ELSE 0 END) as unique_clicks')
            ->groupBy($dimension)->get()->keyBy(last(explode('.', $dimension)));
    }
}
