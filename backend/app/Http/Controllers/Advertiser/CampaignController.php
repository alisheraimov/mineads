<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Http\Requests\Advertiser\SaveCampaignRequest;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\ValidatedImpression;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CampaignController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        $account = $this->account($request);

        return response()->json(['data' => [
            'countries' => config('campaigns.countries'),
            'languages' => config('campaigns.languages'),
            'server_categories' => config('campaigns.server_categories'),
            'available_balance_micros' => $this->balance($account),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'paused', 'ended', 'draft'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $key = $this->cachePrefix($account).':list:'.hash('sha256', json_encode($filters));
        $data = Cache::remember($key, 30, function () use ($account, $filters): array {
            $query = Campaign::query()->where('advertiser_account_id', $account->id);
            if ($search = trim($filters['search'] ?? '')) {
                $query->where(function ($query) use ($search): void {
                    $query->whereLike('name', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
                    $id = preg_replace('/^CMP-/i', '', $search);
                    if (ctype_digit($id)) {
                        $query->orWhere('id', (int) $id);
                    }
                });
            }
            if ($status = $filters['status'] ?? null) {
                $query->where('status', $status);
            }
            $page = $query->withCount(['creatives as active_creatives_count' => fn ($query) => $query->where('status', 'approved')->where('is_paused', false)])
                ->orderByDesc('id')->paginate(20);
            $ids = $page->getCollection()->pluck('id');
            $spend = AdvertiserLedgerEntry::query()->where('advertiser_account_id', $account->id)
                ->whereIn('campaign_id', $ids)->where('type', 'spend')
                ->selectRaw('campaign_id, SUM(amount_micros) as amount')->groupBy('campaign_id')->pluck('amount', 'campaign_id');
            $impressions = ValidatedImpression::query()->where('advertiser_account_id', $account->id)
                ->whereIn('campaign_id', $ids)->selectRaw('campaign_id, COUNT(*) as amount')->groupBy('campaign_id')->pluck('amount', 'campaign_id');
            $counts = Campaign::query()->where('advertiser_account_id', $account->id)
                ->selectRaw('status, COUNT(*) as amount')->groupBy('status')->pluck('amount', 'status')->map(fn ($count) => (int) $count);

            return [
                'campaigns' => $page->getCollection()->map(function (Campaign $campaign) use ($spend, $impressions): array {
                    $amount = abs((int) ($spend[$campaign->id] ?? 0));
                    $count = (int) ($impressions[$campaign->id] ?? 0);

                    return [...$this->basic($campaign), 'spend_micros' => $amount, 'impressions' => $count,
                        'effective_cpm_micros' => $count ? (int) round($amount * 1000 / $count) : 0,
                        'active_creatives_count' => $campaign->status === 'active' ? $campaign->active_creatives_count : 0];
                })->all(),
                'counts' => ['all' => $counts->sum(), ...$counts->all()],
                'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
                'account_name' => $account->name,
                'generated_at' => now()->toISOString(),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, int $campaign): JsonResponse
    {
        $account = $this->account($request);
        $model = $this->ownedCampaign($account, $campaign);
        $dates = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $start = CarbonImmutable::parse($dates['from'] ?? now()->subDays(6)->toDateString(), 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($dates['to'] ?? now()->toDateString(), 'UTC')->endOfDay();
        abort_if($end->lessThan($start) || $start->diffInDays($end) > 366, 422, 'Choose a date range of up to 366 days.');
        $key = $this->cachePrefix($account).":detail:v2:{$campaign}:{$start->toDateString()}:{$end->toDateString()}";

        return response()->json(['data' => Cache::remember($key, 30, fn () => $this->details($account, $model, $start, $end))]);
    }

    public function store(SaveCampaignRequest $request): JsonResponse
    {
        $account = $this->account($request);
        abort_unless($account->status === 'active', 403, 'Your advertiser account is suspended.');
        $campaign = DB::transaction(function () use ($request, $account): Campaign {
            $campaign = new Campaign(['advertiser_account_id' => $account->id, 'status' => 'active', 'pricing_model' => 'cpm']);
            $this->saveCampaign($campaign, $request->validated());

            return $campaign;
        });
        $this->invalidate($account);

        return response()->json(['data' => $this->basic($campaign)], 201);
    }

    public function update(SaveCampaignRequest $request, int $campaign): JsonResponse
    {
        $account = $this->account($request);
        abort_unless($account->status === 'active', 403, 'Your advertiser account is suspended.');
        $model = DB::transaction(function () use ($request, $account, $campaign): Campaign {
            $model = $this->ownedCampaign($account, $campaign, true);
            abort_if($model->status === 'ended', 409, 'Ended campaigns cannot be edited.');
            $this->saveCampaign($model, $request->validated());

            return $model;
        });
        $this->invalidate($account);

        return response()->json(['data' => $this->basic($model)]);
    }

    public function status(Request $request, int $campaign): JsonResponse
    {
        $account = $this->account($request);
        abort_unless($account->status === 'active', 403, 'Your advertiser account is suspended.');
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'paused', 'ended'])]]);
        $model = DB::transaction(function () use ($account, $campaign, $data): Campaign {
            $model = $this->ownedCampaign($account, $campaign, true);
            abort_if($model->status === 'ended' && $data['status'] !== 'ended', 409, 'Ended campaigns cannot be resumed.');
            $model->update(['status' => $data['status']]);

            return $model;
        });
        $this->invalidate($account);

        return response()->json(['data' => $this->basic($model)]);
    }

    private function saveCampaign(Campaign $campaign, array $data): void
    {
        $campaign->fill(collect($data)->only(['name', 'daily_budget_micros', 'total_budget_micros'])->all());
        $campaign->starts_at = CarbonImmutable::parse($data['starts_at'])->utc();
        $campaign->ends_at = CarbonImmutable::parse($data['ends_at'])->utc();
        $campaign->save();
        $existing = $campaign->targets()->get(['country_code', 'language', 'server_category']);
        $dimensions = ['countries' => 'country_code', 'languages' => 'language', 'server_categories' => 'server_category'];
        $unchanged = $existing->isNotEmpty();
        foreach ($dimensions as $field => $column) {
            $unchanged = $unchanged && $existing->pluck($column)->filter()->unique()->sort()->values()->all() === collect($data[$field])->sort()->values()->all();
        }
        if ($unchanged) {
            return;
        }
        $campaign->targets()->delete();
        // Each row must match all three dimensions in the serving query.
        $targets = [];
        foreach ($data['countries'] as $country) {
            foreach ($data['languages'] as $language) {
                foreach ($data['server_categories'] as $category) {
                    $targets[] = ['campaign_id' => $campaign->id, 'country_code' => $country, 'language' => $language,
                        'server_category' => $category, 'created_at' => now(), 'updated_at' => now()];
                }
            }
        }
        foreach (array_chunk($targets, 500) as $chunk) {
            $campaign->targets()->insert($chunk);
        }
    }

    private function details(AdvertiserAccount $account, Campaign $campaign, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $ledger = AdvertiserLedgerEntry::query()->where('advertiser_account_id', $account->id)->where('campaign_id', $campaign->id)->where('type', 'spend');
        $spend = abs((int) (clone $ledger)->sum('amount_micros'));
        $today = abs((int) (clone $ledger)->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])->sum('amount_micros'));
        $month = abs((int) (clone $ledger)->whereBetween('created_at', [now()->startOfMonth(), now()->endOfDay()])->sum('amount_micros'));
        $daily = (clone $ledger)->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as day, SUM(amount_micros) as amount')->groupByRaw('DATE(created_at)')->pluck('amount', 'day');
        $impressions = ValidatedImpression::query()->where('advertiser_account_id', $account->id)->where('campaign_id', $campaign->id);
        $dailyImpressions = (clone $impressions)->whereBetween('validated_at', [$start, $end])
            ->selectRaw('DATE(validated_at) as day, COUNT(*) as amount')->groupByRaw('DATE(validated_at)')->pluck('amount', 'day');
        $count = (int) $dailyImpressions->sum();
        $periodSpend = abs((int) $daily->sum());
        $creativeTotals = (clone $impressions)->selectRaw('creative_id, COUNT(*) as impressions, SUM(billable_micros) as spend_micros')->groupBy('creative_id')->get()->keyBy('creative_id');
        $campaign->load(['targets', 'creatives']);
        $balance = $this->balance($account);
        $issues = [];
        if ($campaign->status !== 'active') {
            $issues[] = $campaign->status === 'ended' ? 'Campaign ended' : 'Campaign paused';
        }
        if ($account->status !== 'active') {
            $issues[] = 'Advertiser account suspended';
        }
        if ($campaign->starts_at?->isFuture()) {
            $issues[] = 'Scheduled start has not been reached';
        }
        if ($campaign->ends_at?->isPast()) {
            $issues[] = 'Campaign schedule has expired';
        }
        if (! $campaign->creatives->contains(fn ($creative) => $creative->status === 'approved' && ! $creative->is_paused)) {
            $issues[] = 'No approved creative';
        }
        if ($campaign->targets->isEmpty()) {
            $issues[] = 'No audience targeting configured';
        } elseif ($campaign->creatives->contains('status', 'approved') && ! $campaign->creatives->contains(fn ($creative) => $creative->status === 'approved' && ! $creative->is_paused && ($creative->language === null || $campaign->targets->contains(fn ($target) => $target->language === null || $target->language === $creative->language)))) {
            $issues[] = 'Approved creative languages do not match targeting';
        }
        if ($balance <= 0) {
            $issues[] = 'Insufficient account balance';
        }
        if ($campaign->daily_budget_micros !== null && $today >= $campaign->daily_budget_micros) {
            $issues[] = 'Daily budget exhausted';
        }
        if ($campaign->total_budget_micros !== null && $spend >= $campaign->total_budget_micros) {
            $issues[] = 'Total budget exhausted';
        }
        $chart = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $daySpend = abs((int) ($daily[$day->toDateString()] ?? 0));
            $dayCount = (int) ($dailyImpressions[$day->toDateString()] ?? 0);
            $chart[] = ['date' => $day->toDateString(), 'label' => $day->format('j M'),
                'spend_micros' => $daySpend, 'impressions' => $dayCount,
                'effective_cpm_micros' => $dayCount ? (int) round($daySpend * 1000 / $dayCount) : 0];
        }

        return [...$this->basic($campaign),
            'account_name' => $account->name,
            'countries' => $campaign->targets->pluck('country_code')->filter()->unique()->values()->all(),
            'languages' => $campaign->targets->pluck('language')->filter()->unique()->values()->all(),
            'server_categories' => $campaign->targets->pluck('server_category')->filter()->unique()->values()->all(),
            'delivery_eligible' => $issues === [], 'delivery_issues' => $issues,
            'metrics' => ['spend_micros' => $periodSpend, 'impressions' => $count, 'effective_cpm_micros' => $count ? (int) round($periodSpend * 1000 / $count) : 0,
                'spend_today_micros' => $today, 'spend_total_micros' => $spend, 'spend_month_micros' => $month],
            'spend_chart' => $chart,
            'creatives' => $campaign->creatives->map(fn ($creative) => [
                'id' => $creative->id, 'name' => $creative->name ?? 'Creative #'.$creative->id, 'status' => $creative->status,
                'language' => $creative->language, 'message_text' => $creative->message_text, 'click_url' => $creative->click_url,
                'is_paused' => $creative->is_paused,
                'impressions' => (int) ($creativeTotals[$creative->id]->impressions ?? 0),
                'spend_micros' => (int) ($creativeTotals[$creative->id]->spend_micros ?? 0),
            ])->all(),
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'generated_at' => now()->toISOString(),
        ];
    }

    private function basic(Campaign $campaign): array
    {
        return ['id' => $campaign->id, 'name' => $campaign->name, 'status' => $campaign->status,
            'daily_budget_micros' => $campaign->daily_budget_micros === null ? null : (int) $campaign->daily_budget_micros,
            'total_budget_micros' => $campaign->total_budget_micros === null ? null : (int) $campaign->total_budget_micros,
            'starts_at' => $campaign->starts_at?->toISOString(), 'ends_at' => $campaign->ends_at?->toISOString()];
    }

    private function account(Request $request): AdvertiserAccount
    {
        $account = $request->user()->advertiserAccount()->first();
        abort_unless($account, 409, 'Advertiser account is not set up.');

        return $account;
    }

    private function ownedCampaign(AdvertiserAccount $account, int $id, bool $lock = false): Campaign
    {
        return Campaign::query()->where('advertiser_account_id', $account->id)->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    private function balance(AdvertiserAccount $account): int
    {
        return (int) AdvertiserLedgerEntry::query()->where('advertiser_account_id', $account->id)->sum('amount_micros');
    }

    private function cachePrefix(AdvertiserAccount $account): string
    {
        return "advertiser_campaigns:{$account->id}:".Cache::get("advertiser_campaigns_version:{$account->id}", 'initial');
    }

    private function invalidate(AdvertiserAccount $account): void
    {
        Cache::put("advertiser_campaigns_version:{$account->id}", (string) Str::uuid(), now()->addDay());
        Cache::forget("advertiser_dashboard:{$account->id}");
    }
}
