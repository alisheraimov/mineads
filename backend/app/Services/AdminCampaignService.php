<?php

namespace App\Services;

use App\Models\AdDelivery;
use App\Models\AdminCampaignEvent;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use App\Models\RawImpressionEvent;
use App\Models\User;
use App\Models\ValidatedImpression;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AdminCampaignService
{
    public function canViewMoney(User $user): bool
    {
        return $user->adminCan('admin.campaigns.manage') || $user->adminCan('admin.finance.view');
    }

    public function listingQuery(): Builder
    {
        return Campaign::withTrashed()
            ->with(['advertiserAccount.user' => fn ($query) => $query->withTrashed()])
            ->withCount(['creatives', 'creatives as pending_creatives_count' => fn ($query) => $query->where('status', 'pending_review'), 'validatedImpressions' => fn ($query) => $query->where('validated_at', '<=', now())])
            ->withSum(['ledgerEntries as lifetime_spend' => fn ($query) => $query->where('type', 'spend')->where('created_at', '<=', now())], 'amount_micros')
            ->withMax(['deliveries as last_delivery_at' => fn ($query) => $query->where('decision', 'served')->where('requested_at', '<=', now())], 'requested_at');
    }

    public function basic(Campaign $campaign, bool $money): array
    {
        $account = $campaign->advertiserAccount;
        $user = $account?->user;
        $data = [
            'id' => $campaign->id, 'name' => $campaign->name, 'status' => $campaign->trashed() ? 'deleted' : $campaign->status,
            'advertiser' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->trashed() ? 'deleted' : $user->status] : null,
            'starts_at' => $campaign->starts_at?->toISOString(), 'ends_at' => $campaign->ends_at?->toISOString(),
            'created_at' => $campaign->created_at->toISOString(), 'updated_at' => $campaign->updated_at->toISOString(), 'deleted_at' => $campaign->deleted_at?->toISOString(),
        ];
        if ($money) {
            $data['daily_budget_micros'] = $campaign->daily_budget_micros === null ? null : (int) $campaign->daily_budget_micros;
            $data['total_budget_micros'] = $campaign->total_budget_micros === null ? null : (int) $campaign->total_budget_micros;
        }

        return $data;
    }

    public function listItem(Campaign $campaign, bool $money): array
    {
        $data = [...$this->basic($campaign, $money), 'impressions' => (int) $campaign->validated_impressions_count,
            'creatives_count' => (int) $campaign->creatives_count, 'pending_creatives_count' => (int) $campaign->pending_creatives_count, 'last_delivery_at' => $campaign->last_delivery_at ? CarbonImmutable::parse($campaign->last_delivery_at, 'UTC')->toISOString() : null];
        if ($money) {
            $data['spend_micros'] = abs((int) $campaign->lifetime_spend);
        }

        return $data;
    }

    public function details(Campaign $campaign, User $viewer): array
    {
        $campaign->load(['advertiserAccount.user' => fn ($query) => $query->withTrashed(), 'targets', 'deletedBy']);
        $money = $this->canViewMoney($viewer);
        $now = CarbonImmutable::now('UTC');
        $ledger = AdvertiserLedgerEntry::query()->where('campaign_id', $campaign->id)->where('type', 'spend')->where('created_at', '<=', $now);
        $spent = abs((int) (clone $ledger)->sum('amount_micros'));
        $todaySpent = abs((int) (clone $ledger)->where('created_at', '>=', $now->startOfDay())->sum('amount_micros'));
        $balance = (int) AdvertiserLedgerEntry::query()->where('advertiser_account_id', $campaign->advertiser_account_id)->sum('amount_micros');
        $valid = ValidatedImpression::query()->where('campaign_id', $campaign->id)->where('validated_at', '<=', $now);
        $totals = (clone $valid)->selectRaw('COUNT(*) as impressions, COALESCE(SUM(billable_micros), 0) as spend')->first();
        $raw = RawImpressionEvent::query()->whereHas('adDelivery', fn ($query) => $query->where('campaign_id', $campaign->id))->where('received_at', '<=', $now);
        $invalid = (clone $raw)->where('validation_status', 'invalid')->where('validated_at', '<=', $now)->count();
        $deliveries = AdDelivery::query()->where('campaign_id', $campaign->id)->where('decision', 'served')->where('requested_at', '<=', $now);
        $metrics = ['reported_impressions' => (clone $raw)->count(), 'validated_impressions' => (int) $totals->impressions, 'invalid_impressions' => $invalid,
            'pending_impressions' => (clone $raw)->whereIn('validation_status', ['pending', 'review'])->count(),
            'invalid_rate' => $totals->impressions + $invalid > 0 ? round($invalid * 100 / ($totals->impressions + $invalid), 2) : 0,
            'served_deliveries' => (clone $deliveries)->count(), 'last_delivery_at' => (clone $deliveries)->max('requested_at')];
        if ($metrics['last_delivery_at']) {
            $metrics['last_delivery_at'] = CarbonImmutable::parse($metrics['last_delivery_at'], 'UTC')->toISOString();
        }
        if ($money) {
            $metrics['spend_micros'] = (int) $totals->spend;
            $metrics['effective_cpm_micros'] = $totals->impressions > 0 ? (int) round($totals->spend * 1000 / $totals->impressions) : null;
        }
        $counts = $campaign->creatives()->withTrashed()->get(['status', 'deleted_at']);
        $issues = $this->deliveryIssues($campaign, $balance, $spent, $todaySpent);
        $rules = $campaign->targets->groupBy(fn ($target) => json_encode($target->server_category))->map(fn ($group): array => [
            'countries' => $group->contains('country_code', null) ? [] : $group->pluck('country_code')->unique()->values()->all(),
            'server_category' => $group->first()->server_category,
        ])->values()->all();
        $data = [...$this->basic($campaign, $money), 'metrics' => $metrics, 'targeting_rules' => $rules, 'delivery_eligible' => $issues === [], 'delivery_issues' => $issues,
            'creative_counts' => ['total' => $counts->count(), 'approved' => $counts->whereNull('deleted_at')->where('status', 'approved')->count(),
                'pending_review' => $counts->whereNull('deleted_at')->where('status', 'pending_review')->count(), 'rejected' => $counts->whereNull('deleted_at')->where('status', 'rejected')->count(), 'deleted' => $counts->whereNotNull('deleted_at')->count()],
            'can_view_money' => $money, 'can_delete' => ! $campaign->trashed() && $viewer->adminCan('admin.campaigns.manage'), 'can_view_advertiser' => $viewer->adminCan('admin.users.view'),
            'deleted_by' => $campaign->deletedBy ? ['id' => $campaign->deletedBy->id, 'name' => $campaign->deletedBy->name] : null,
            'history' => AdminCampaignEvent::query()->where('campaign_id', $campaign->id)->latest('id')->limit(20)->get(['id', 'actor_id', 'actor_name', 'action', 'created_at']),
            'performance' => $this->chart($valid, $campaign, $now, $money), 'generated_at' => $now->toISOString()];
        if ($money) {
            $data['budget'] = ['spent_today_micros' => $todaySpent, 'lifetime_spend_micros' => $spent, 'available_balance_micros' => $balance,
                'daily_remaining_micros' => $campaign->daily_budget_micros === null ? null : max(0, $campaign->daily_budget_micros - $todaySpent),
                'total_remaining_micros' => $campaign->total_budget_micros === null ? null : max(0, $campaign->total_budget_micros - $spent)];
        }

        return $data;
    }

    private function deliveryIssues(Campaign $campaign, int $balance, int $spent, int $todaySpent): array
    {
        $issues = [];
        if ($campaign->trashed()) {
            $issues[] = 'Campaign deleted';
        } elseif ($campaign->status !== 'active') {
            $issues[] = 'Campaign '.$campaign->status;
        }
        $account = $campaign->advertiserAccount;
        if (! $account || $account->status !== 'active') {
            $issues[] = 'Advertiser account suspended or unavailable';
        }
        if (! $account?->user || $account->user->trashed() || $account->user->status !== 'active') {
            $issues[] = 'Advertiser user suspended or deleted';
        }
        if ($campaign->starts_at?->isFuture()) {
            $issues[] = 'Scheduled start has not been reached';
        }
        if ($campaign->ends_at?->isPast()) {
            $issues[] = 'Campaign schedule has expired';
        }
        if ($campaign->targets->isEmpty()) {
            $issues[] = 'No audience targeting configured';
        }
        if (! $campaign->creatives()->where('status', 'approved')->where('is_paused', false)->exists()) {
            $issues[] = 'No approved, unpaused creative';
        }
        if ($balance <= 0) {
            $issues[] = 'Insufficient advertiser funds';
        }
        if ($campaign->daily_budget_micros !== null && $todaySpent >= $campaign->daily_budget_micros) {
            $issues[] = 'Daily budget exhausted';
        }
        if ($campaign->total_budget_micros !== null && $spent >= $campaign->total_budget_micros) {
            $issues[] = 'Total budget exhausted';
        }

        return $issues;
    }

    private function chart(Builder $valid, Campaign $campaign, CarbonImmutable $now, bool $money): array
    {
        $first = (clone $valid)->min('validated_at');
        $start = CarbonImmutable::parse($first ?? $campaign->created_at, 'UTC')->min($campaign->created_at)->min($now)->startOfDay();
        $monthly = $start->diffInDays($now) > 366;
        if ($monthly) {
            $start = $start->startOfMonth();
        }
        $expression = $monthly ? (DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m-01', validated_at)" : "date_trunc('month', validated_at)") : 'DATE(validated_at)';
        $buckets = (clone $valid)->selectRaw($expression.' as bucket, COUNT(*) as impressions, SUM(billable_micros) as spend')->groupBy('bucket')->orderBy('bucket')->get()
            ->keyBy(fn ($row) => CarbonImmutable::parse($row->bucket, 'UTC')->toDateString());
        $points = [];
        for ($day = $start; $day->lte($now); $day = $monthly ? $day->addMonth() : $day->addDay()) {
            $bucket = $buckets->get($day->toDateString());
            $point = ['date' => $day->toDateString(), 'impressions' => (int) ($bucket?->impressions ?? 0)];
            if ($money) {
                $point['spend_micros'] = (int) ($bucket?->spend ?? 0);
                $point['effective_cpm_micros'] = $bucket?->impressions > 0 ? (int) round($bucket->spend * 1000 / $bucket->impressions) : null;
            }
            $points[] = $point;
        }

        return ['interval' => $monthly ? 'month' : 'day', 'points' => $points];
    }
}
