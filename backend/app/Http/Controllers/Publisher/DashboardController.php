<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\ValidatedImpression;
use App\Services\PublisherBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request, PublisherBalanceService $balances): JsonResponse
    {
        $publisher = $request->user()->publisherAccount()->first();

        if (! $publisher) {
            return response()->json(['message' => 'Publisher account is not set up.'], 409);
        }

        if ($publisher->status !== 'active') {
            return response()->json(['message' => 'This publisher account has been suspended.'], 403);
        }

        $now = now('UTC');
        $today = $now->copy()->startOfDay();
        $month = $now->copy()->startOfMonth();
        $chartStart = $today->copy()->subDays(29);
        $start = $chartStart->lt($month) ? $chartStart : $month;

        $daily = ValidatedImpression::query()
            ->where('publisher_account_id', $publisher->id)
            ->whereBetween('validated_at', [$start, $now])
            ->selectRaw('DATE(validated_at) as date, COUNT(*) as impressions, SUM(publisher_earning_micros) as earnings_micros')
            ->groupByRaw('DATE(validated_at)')
            ->orderBy('date')
            ->get();

        $monthly = $daily->where('date', '>=', $month->toDateString());
        $todayMetrics = $daily->firstWhere('date', $today->toDateString());
        $earningsMonth = (int) $monthly->sum('earnings_micros');
        $impressionsMonth = (int) $monthly->sum('impressions');

        $byDate = $daily->keyBy('date');
        $chart = collect(range(0, 29))->map(function (int $offset) use ($chartStart, $byDate): array {
            $day = $chartStart->copy()->addDays($offset);
            $metrics = $byDate->get($day->toDateString());

            return [
                'date' => $day->toDateString(),
                'label' => $day->format('M j'),
                'earnings_micros' => (int) ($metrics?->earnings_micros ?? 0),
                'impressions' => (int) ($metrics?->impressions ?? 0),
            ];
        })->all();

        return response()->json(['data' => [
            'account' => $publisher->only(['id', 'name', 'status', 'currency']),
            'server_count' => $publisher->servers()->count(),
            'period' => [
                'today_start' => $today->toISOString(),
                'month_start' => $month->toISOString(),
                'month_end' => $now->toISOString(),
                'chart_start' => $chartStart->toISOString(),
                'timezone' => 'UTC',
            ],
            'metrics' => [
                'earnings_today_micros' => (int) ($todayMetrics?->earnings_micros ?? 0),
                'earnings_month_micros' => $earningsMonth,
                'impressions_today' => (int) ($todayMetrics?->impressions ?? 0),
                'impressions_month' => $impressionsMonth,
                'effective_cpm_micros' => $impressionsMonth > 0
                    ? (int) round($earningsMonth * 1000 / $impressionsMonth)
                    : 0,
                'available_payout_micros' => $balances->availableMicros($publisher),
            ],
            'performance_chart' => $chart,
            'generated_at' => $now->toISOString(),
        ]]);
    }
}
