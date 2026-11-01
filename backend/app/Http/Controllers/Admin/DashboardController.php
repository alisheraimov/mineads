<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Payout;
use App\Models\RawImpressionEvent;
use App\Models\Server;
use App\Models\ValidatedImpression;
use App\Services\AdminCreativeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->adminCan('admin.analytics.view'), 403, 'Your role cannot view analytics.');
        $now = CarbonImmutable::now('UTC');
        $active = Server::query()->where('status', 'active')->where('review_status', 'approved')->whereNotNull('activated_at');
        $online = (clone $active)->whereHas('latestHeartbeat', fn (Builder $query) => $query
            ->where('received_at', '>', $now->subSeconds(config('publisher.heartbeat_timeout_seconds')))
            ->where('received_at', '<=', $now));
        $offline = (clone $active)->whereNotIn('id', (clone $online)->select('servers.id'));
        $riskyIds = RawImpressionEvent::query()
            ->select('server_id')
            ->whereIn('validation_status', ['valid', 'invalid'])
            ->where('validated_at', '>=', $now->subHours(24))
            ->where('validated_at', '<=', $now)
            ->groupBy('server_id')
            ->havingRaw('COUNT(*) >= ?', [config('admin.health_minimum_processed_impressions')])
            ->havingRaw("SUM(CASE WHEN validation_status = 'invalid' THEN 1 ELSE 0 END) * 100.0 / COUNT(*) > ?", [config('admin.health_invalid_rate_percent')]);
        $risky = (clone $active)->whereIn('id', $riskyIds);
        $unhealthy = (clone $active)->where(fn (Builder $query) => $query
            ->whereIn('id', (clone $offline)->select('servers.id'))
            ->orWhereIn('id', (clone $risky)->select('servers.id')));
        $pendingCreatives = app(AdminCreativeService::class)->reviewableQuery()->where('status', 'pending_review');
        $oldestPendingCreative = (clone $pendingCreatives)->orderByRaw('COALESCE(review_submitted_at, created_at) ASC')->first();
        $payouts = Payout::query()->where('status', 'pending')
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount_micros), 0) as amount_micros')->first();

        return response()->json(['data' => $this->visibleData($request, [
            'active_campaigns' => Campaign::query()->where('status', 'active')->count(),
            'active_servers' => (clone $active)->count(),
            'online_servers' => (clone $online)->count(),
            'pending_creatives' => (clone $pendingCreatives)->count(),
            'oldest_pending_creative_at' => $oldestPendingCreative?->review_submitted_at ?? $oldestPendingCreative?->created_at,
            'unhealthy_servers' => $unhealthy->count(),
            'offline_servers' => $offline->count(),
            'high_invalid_rate_servers' => $risky->count(),
            'pending_payouts' => (int) $payouts->count,
            'pending_payout_micros' => (int) $payouts->amount_micros,
            'health_rules' => [
                'heartbeat_timeout_seconds' => (int) config('publisher.heartbeat_timeout_seconds'),
                'invalid_rate_percent' => (int) config('admin.health_invalid_rate_percent'),
                'minimum_processed_impressions' => (int) config('admin.health_minimum_processed_impressions'),
            ],
            'generated_at' => $now->toISOString(),
        ])]);
    }

    public function performance(Request $request): JsonResponse
    {
        abort_unless($request->user()->adminCan('admin.analytics.view'), 403, 'Your role cannot view analytics.');
        $input = $request->validate([
            'period' => ['sometimes', Rule::in(['today', 'yesterday', 'last7', 'last30', 'custom'])],
            'from' => ['required_if:period,custom', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
        ]);
        $now = CarbonImmutable::now('UTC');
        $today = $now->startOfDay();
        $period = $input['period'] ?? 'today';
        $start = match ($period) {
            'yesterday' => $today->subDay(),
            'last7' => $today->subDays(6),
            'last30' => $today->subDays(29),
            'custom' => CarbonImmutable::parse($input['from'], 'UTC'),
            default => $today,
        };
        $end = match ($period) {
            'yesterday' => $today,
            'custom' => CarbonImmutable::parse($input['to'], 'UTC')->addDay(),
            default => $today->addDay(),
        };
        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['to' => 'Select a range of 366 days or fewer.']);
        }
        $hourly = in_array($period, ['today', 'yesterday'], true);
        $inPeriod = fn (Builder $query, string $column) => $query
            ->where($column, '>=', $start)->where($column, '<', $end)->where($column, '<=', $now);
        $valid = $inPeriod(ValidatedImpression::query(), 'validated_at');
        $invalid = $inPeriod(RawImpressionEvent::query()->where('validation_status', 'invalid'), 'validated_at');
        $reported = $inPeriod(RawImpressionEvent::query(), 'received_at');
        $totals = (clone $valid)->selectRaw('COUNT(*) as impressions, COALESCE(SUM(billable_micros), 0) as spend, COALESCE(SUM(publisher_earning_micros), 0) as earnings, COALESCE(SUM(platform_fee_micros), 0) as fee')->first();
        $invalidCount = (clone $invalid)->count();
        $processed = (int) $totals->impressions + $invalidCount;
        $validBuckets = (clone $valid)->selectRaw($this->bucketExpression($hourly).' as bucket, COUNT(*) as impressions, SUM(billable_micros) as spend, SUM(publisher_earning_micros) as earnings, SUM(platform_fee_micros) as fee')
            ->groupBy('bucket')->orderBy('bucket')->get()->keyBy(fn ($row) => CarbonImmutable::parse($row->bucket, 'UTC')->format('Y-m-d H:00'));
        $invalidBuckets = (clone $invalid)->selectRaw($this->bucketExpression($hourly).' as bucket, COUNT(*) as impressions')
            ->groupBy('bucket')->orderBy('bucket')->get()->keyBy(fn ($row) => CarbonImmutable::parse($row->bucket, 'UTC')->format('Y-m-d H:00'));
        $chart = [];
        for ($cursor = $start; $cursor->lt($end) && $cursor->lte($now); $cursor = $hourly ? $cursor->addHour() : $cursor->addDay()) {
            $key = $cursor->format('Y-m-d H:00');
            $point = $validBuckets->get($key);
            $chart[] = [
                'date' => $cursor->toISOString(),
                'label' => $cursor->format($hourly ? 'H:i' : 'M j'),
                'validated' => (int) ($point?->impressions ?? 0),
                'invalid' => (int) ($invalidBuckets->get($key)?->impressions ?? 0),
                'spend_micros' => (int) ($point?->spend ?? 0),
                'earnings_micros' => (int) ($point?->earnings ?? 0),
                'fee_micros' => (int) ($point?->fee ?? 0),
            ];
        }

        return response()->json(['data' => $this->visibleData($request, [
            'period' => [
                'key' => $period, 'from' => $start->toDateString(), 'to' => $end->subDay()->toDateString(),
                'timezone' => 'UTC', 'interval' => $hourly ? 'hour' : 'day',
            ],
            'metrics' => [
                'reported_impressions' => (clone $reported)->count(),
                'validated_impressions' => (int) $totals->impressions,
                'invalid_impressions' => $invalidCount,
                'pending_impressions' => (clone $reported)->where('validation_status', 'pending')->count(),
                'invalid_rate' => $processed > 0 ? round($invalidCount * 100 / $processed, 2) : 0,
                'spend_micros' => (int) $totals->spend,
                'earnings_micros' => (int) $totals->earnings,
                'fee_micros' => (int) $totals->fee,
            ],
            'chart' => $chart,
            'generated_at' => $now->toISOString(),
        ])]);
    }

    private function visibleData(Request $request, array $data): array
    {
        if ($request->user()->adminCan('admin.finance.view')) {
            return $data;
        }
        foreach (['spend_micros', 'earnings_micros', 'fee_micros', 'pending_payouts', 'pending_payout_micros'] as $key) {
            unset($data[$key]);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->visibleData($request, $value);
            }
        }

        return $data;
    }

    private function bucketExpression(bool $hourly): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return $hourly ? "strftime('%Y-%m-%d %H:00:00', validated_at)" : 'DATE(validated_at)';
        }

        return $hourly ? "date_trunc('hour', validated_at)" : "date_trunc('day', validated_at)";
    }
}
