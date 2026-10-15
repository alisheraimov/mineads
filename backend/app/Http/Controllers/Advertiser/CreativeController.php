<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Http\Requests\Advertiser\SaveCreativeRequest;
use App\Models\AdCreative;
use App\Models\AdvertiserAccount;
use App\Models\Campaign;
use App\Models\ValidatedImpression;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreativeController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        $account = $this->account($request);

        return response()->json(['data' => [
            'campaigns' => Campaign::query()->where('advertiser_account_id', $account->id)->where('status', '!=', 'ended')->orderBy('name')->get(['id', 'name', 'status']),
            'languages' => config('campaigns.languages'),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['approved', 'pending_review', 'rejected', 'paused'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $key = $this->prefix($account).':list:'.hash('sha256', json_encode($filters));

        return response()->json(['data' => Cache::remember($key, 30, function () use ($account, $filters): array {
            $base = AdCreative::query()->whereHas('campaign', fn ($query) => $query->where('advertiser_account_id', $account->id));
            $state = "CASE WHEN is_paused THEN 'paused' ELSE status END";
            $groups = (clone $base)->selectRaw("{$state} as state, COUNT(*) as amount")->groupByRaw($state)->pluck('amount', 'state')->map(fn ($count) => (int) $count);
            $counts = ['all' => $groups->sum(), ...$groups->all()];
            if ($status = $filters['status'] ?? null) {
                $base->when($status === 'paused', fn ($q) => $q->where('is_paused', true), fn ($q) => $q->where('status', $status)->where('is_paused', false));
            }
            if ($search = trim($filters['search'] ?? '')) {
                $escaped = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $base->where(fn ($q) => $q->whereLike('name', $escaped)->orWhereLike('message_text', $escaped));
            }
            $page = $base->with('campaign:id,name,status')->orderByDesc('id')->paginate(20);
            $totals = ValidatedImpression::query()->where('advertiser_account_id', $account->id)->whereIn('creative_id', $page->getCollection()->pluck('id'))
                ->selectRaw('creative_id, COUNT(*) as impressions, SUM(billable_micros) as spend_micros')->groupBy('creative_id')->get()->keyBy('creative_id');

            return ['creatives' => $page->getCollection()->map(fn ($creative) => [...$this->basic($creative),
                'impressions' => (int) ($totals[$creative->id]->impressions ?? 0), 'spend_micros' => (int) ($totals[$creative->id]->spend_micros ?? 0)])->all(),
                'counts' => $counts, 'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]];
        })]);
    }

    public function show(Request $request, int $creative): JsonResponse
    {
        $account = $this->account($request);
        $model = $this->owned($account, $creative);
        $dates = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $start = CarbonImmutable::parse($dates['from'] ?? now()->subDays(6)->toDateString(), 'UTC')->startOfDay();
        $end = CarbonImmutable::parse($dates['to'] ?? now()->toDateString(), 'UTC')->endOfDay();
        abort_if($end->lessThan($start) || $start->diffInDays($end) > 366, 422, 'Choose a date range of up to 366 days.');

        return response()->json(['data' => Cache::remember($this->prefix($account).":detail:v2:{$creative}:{$start}:{$end}", 30, function () use ($account, $model, $start, $end): array {
            $query = ValidatedImpression::query()->where('validated_impressions.advertiser_account_id', $account->id)->where('validated_impressions.creative_id', $model->id)->whereBetween('validated_impressions.validated_at', [$start, $end]);
            $daily = (clone $query)->selectRaw('DATE(validated_at) as day, COUNT(*) as impressions, SUM(billable_micros) as spend_micros')->groupByRaw('DATE(validated_at)')->get()->keyBy('day');
            $count = (int) $daily->sum('impressions');
            $spend = (int) $daily->sum('spend_micros');
            $chart = [];
            for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
                $row = $daily[$day->toDateString()] ?? null;
                $chart[] = ['date' => $day->toDateString(), 'label' => $day->format('j M'), ...$this->metrics((int) ($row->impressions ?? 0), (int) ($row->spend_micros ?? 0))];
            }
            $countries = (clone $query)->join('raw_impression_events as raw', 'raw.id', '=', 'validated_impressions.raw_impression_event_id')
                ->join('ad_deliveries as delivery', 'delivery.id', '=', 'raw.ad_delivery_id')
                ->selectRaw('delivery.audience_country as label, COUNT(*) as impressions, SUM(billable_micros) as spend_micros')->groupBy('delivery.audience_country')->orderByDesc('impressions')->get();
            $categories = (clone $query)->join('servers', 'servers.id', '=', 'validated_impressions.server_id')
                ->selectRaw('servers.category as label, COUNT(*) as impressions, SUM(billable_micros) as spend_micros')->groupBy('servers.category')->orderByDesc('impressions')->get();

            return [...$this->basic($model), 'metrics' => $this->metrics($count, $spend), 'spend_chart' => $chart,
                'countries' => $countries->map(fn ($row) => ['label' => $row->label, 'impressions' => (int) $row->impressions, 'spend_micros' => (int) $row->spend_micros])->all(),
                'server_categories' => $categories->map(fn ($row) => ['label' => $row->label, 'impressions' => (int) $row->impressions, 'spend_micros' => (int) $row->spend_micros])->all(),
                'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()]];
        })]);
    }

    public function store(SaveCreativeRequest $request): JsonResponse
    {
        $account = $this->account($request, true);
        $model = DB::transaction(function () use ($request, $account): AdCreative {
            $data = $request->validated();
            $campaign = Campaign::query()->where('advertiser_account_id', $account->id)->lockForUpdate()->findOrFail($data['campaign_id']);
            abort_if($campaign->status === 'ended', 409, 'Choose a campaign that has not ended.');

            return $campaign->creatives()->create([...$data, 'status' => 'pending_review', 'type' => 'chat']);
        });
        $this->invalidate($account);

        return response()->json(['data' => $this->basic($model)], 201);
    }

    public function update(SaveCreativeRequest $request, int $creative): JsonResponse
    {
        $account = $this->account($request, true);
        $model = DB::transaction(function () use ($request, $account, $creative): AdCreative {
            $model = $this->owned($account, $creative, true);
            $model->fill($request->validated());
            if ($model->isDirty(['message_text', 'click_url', 'language'])) {
                $model->status = 'pending_review';
                $model->rejection_reason = null;
            }
            $model->save();

            return $model;
        });
        $this->invalidate($account);

        return response()->json(['data' => $this->basic($model)]);
    }

    public function pause(Request $request, int $creative): JsonResponse
    {
        $account = $this->account($request, true);
        $data = $request->validate(['is_paused' => ['required', 'boolean']]);
        $model = DB::transaction(function () use ($account, $creative, $data): AdCreative {
            $model = $this->owned($account, $creative, true);
            $model->update($data);

            return $model;
        });
        $this->invalidate($account);

        return response()->json(['data' => $this->basic($model)]);
    }

    public function destroy(Request $request, int $creative): JsonResponse
    {
        $account = $this->account($request, true);
        $this->owned($account, $creative)->delete();
        $this->invalidate($account);

        return response()->json(['message' => 'Creative deleted.']);
    }

    private function basic(AdCreative $creative): array
    {
        return ['id' => $creative->id, 'name' => $creative->name ?? 'Creative #'.$creative->id,
            'campaign_id' => $creative->campaign_id, 'campaign_name' => $creative->campaign->name, 'campaign_status' => $creative->campaign->status,
            'status' => $creative->status, 'is_paused' => $creative->is_paused, 'rejection_reason' => $creative->rejection_reason,
            'language' => $creative->language, 'message_text' => $creative->message_text, 'click_url' => $creative->click_url,
            'created_at' => $creative->created_at->toISOString(), 'updated_at' => $creative->updated_at->toISOString()];
    }

    private function metrics(int $count, int $spend): array
    {
        return ['impressions' => $count, 'spend_micros' => $spend, 'effective_cpm_micros' => $count ? (int) round($spend * 1000 / $count) : 0];
    }

    private function account(Request $request, bool $write = false): AdvertiserAccount
    {
        $account = $request->user()->advertiserAccount()->first();
        abort_unless($account, 409, 'Advertiser account is not set up.');
        abort_if($write && $account->status !== 'active', 403, 'Your advertiser account is suspended.');

        return $account;
    }

    private function owned(AdvertiserAccount $account, int $id, bool $lock = false): AdCreative
    {
        return AdCreative::query()->whereHas('campaign', fn ($q) => $q->where('advertiser_account_id', $account->id))
            ->with('campaign')->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
    }

    private function prefix(AdvertiserAccount $account): string
    {
        return "advertiser_creatives:{$account->id}:".Cache::get("advertiser_campaigns_version:{$account->id}", 'initial');
    }

    private function invalidate(AdvertiserAccount $account): void
    {
        Cache::put("advertiser_campaigns_version:{$account->id}", (string) Str::uuid(), now()->addDay());
        Cache::forget("advertiser_dashboard:{$account->id}");
    }
}
