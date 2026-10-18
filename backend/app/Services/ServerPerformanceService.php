<?php

namespace App\Services;

use App\Models\ValidatedImpression;
use Carbon\CarbonImmutable;

class ServerPerformanceService
{
    public function report(int $serverId, string $from, string $to, string $group, bool $compare): array
    {
        $start = CarbonImmutable::parse($from, 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($to, 'UTC')->startOfDay();
        $days = (int) $start->diffInDays($end) + 1;
        $previousStart = $start->subDays($days);
        $rows = ValidatedImpression::query()->where('server_id', $serverId)
            ->whereBetween('validated_at', [$compare ? $previousStart : $start, $end->endOfDay()->min(now('UTC'))])
            ->selectRaw('DATE(validated_at) as date, COUNT(*) as impressions, SUM(publisher_earning_micros) as earnings')
            ->groupByRaw('DATE(validated_at)')->get()->keyBy('date');
        $buckets = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->addDays($offset);
            $key = match ($group) {
                'week' => $date->startOfWeek()->toDateString(),
                'month' => $date->startOfMonth()->toDateString(),
                default => $date->toDateString(),
            };
            if (! isset($buckets[$key])) {
                $buckets[$key] = ['date' => $date->toDateString(), 'label' => $date->format('M j'), 'earnings_micros' => 0, 'impressions' => 0, 'previous_earnings_micros' => 0, 'previous_impressions' => 0];
            }
            $current = $rows->get($date->toDateString());
            $previous = $rows->get($previousStart->addDays($offset)->toDateString());
            $buckets[$key]['earnings_micros'] += (int) ($current?->earnings ?? 0);
            $buckets[$key]['impressions'] += (int) ($current?->impressions ?? 0);
            if ($compare) {
                $buckets[$key]['previous_earnings_micros'] += (int) ($previous?->earnings ?? 0);
                $buckets[$key]['previous_impressions'] += (int) ($previous?->impressions ?? 0);
            }
        }
        $points = array_map(function (array $point): array {
            $point['effective_cpm_micros'] = $point['impressions'] > 0 ? (int) round($point['earnings_micros'] * 1000 / $point['impressions']) : 0;
            $point['previous_effective_cpm_micros'] = $point['previous_impressions'] > 0 ? (int) round($point['previous_earnings_micros'] * 1000 / $point['previous_impressions']) : 0;

            return $point;
        }, array_values($buckets));

        return [
            'from' => $from, 'to' => $to, 'group' => $group, 'compare' => $compare, 'timezone' => 'UTC',
            'previous_from' => $previousStart->toDateString(), 'previous_to' => $start->subDay()->toDateString(),
            'earnings_micros' => array_sum(array_column($points, 'earnings_micros')),
            'impressions' => array_sum(array_column($points, 'impressions')),
            'points' => $points,
        ];
    }
}
