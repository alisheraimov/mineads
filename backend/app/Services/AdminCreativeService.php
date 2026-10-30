<?php

namespace App\Services;

use App\Models\AdCreative;
use App\Models\AdDelivery;
use App\Models\AdminCreativeEvent;
use App\Models\RawImpressionEvent;
use App\Models\User;
use App\Models\ValidatedImpression;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AdminCreativeService
{
    public const REASONS = [
        'misleading_content' => 'Misleading content',
        'inappropriate_content' => 'Inappropriate content',
        'suspicious_url' => 'Broken or suspicious URL',
        'unsupported_language' => 'Unsupported language',
        'formatting_issue' => 'Formatting issue',
        'policy_violation' => 'Policy violation',
        'other' => 'Other',
    ];

    public function query(): Builder
    {
        return AdCreative::withTrashed()->with(['campaign' => fn ($query) => $query->withTrashed()->with(['advertiserAccount.user' => fn ($query) => $query->withTrashed()])]);
    }

    public function reviewableQuery(): Builder
    {
        return AdCreative::query()->whereHas('campaign', fn ($query) => $query->whereHas('advertiserAccount.user'));
    }

    public function hasDeletedParent(AdCreative $creative): bool
    {
        return ! $creative->campaign || $creative->campaign->trashed() || ! $creative->campaign->advertiserAccount?->user || $creative->campaign->advertiserAccount->user->trashed();
    }

    public function version(AdCreative $creative): string
    {
        return hash('sha256', json_encode([$creative->getAttributes(), AdminCreativeEvent::where('creative_id', $creative->id)->max('id')]));
    }

    public function basic(AdCreative $creative): array
    {
        $campaign = $creative->campaign;
        $user = $campaign?->advertiserAccount?->user;

        return [
            'id' => $creative->id, 'name' => $creative->name, 'message_text' => $creative->message_text, 'click_url' => $creative->click_url,
            'language' => $creative->language, 'status' => $creative->trashed() ? 'deleted' : $creative->status, 'is_paused' => $creative->is_paused,
            'rejection_reason' => $creative->rejection_reason, 'rejection_code' => $creative->rejection_code, 'rejection_explanation' => $creative->rejection_explanation,
            'campaign' => $campaign ? ['id' => $campaign->id, 'name' => $campaign->name, 'status' => $campaign->trashed() ? 'deleted' : $campaign->status] : null,
            'advertiser' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->trashed() ? 'deleted' : $user->status] : null,
            'created_at' => $creative->created_at->toISOString(), 'updated_at' => $creative->updated_at->toISOString(),
            'submitted_at' => ($creative->review_submitted_at ?? $creative->created_at)->toISOString(), 'deleted_at' => $creative->deleted_at?->toISOString(),
            'has_deleted_parent' => $this->hasDeletedParent($creative),
        ];
    }

    public function details(AdCreative $creative, User $viewer, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $money = $viewer->adminCan('admin.campaigns.manage') || $viewer->adminCan('admin.finance.view');
        $now = CarbonImmutable::now('UTC');
        $valid = ValidatedImpression::query()->where('creative_id', $creative->id)->where('validated_at', '>=', $start)->where('validated_at', '<', $end)->where('validated_at', '<=', $now);
        $daily = (clone $valid)->selectRaw('DATE(validated_at) as day, COUNT(*) as impressions, SUM(billable_micros) as spend')->groupByRaw('DATE(validated_at)')->get()->keyBy('day');
        $count = (int) $daily->sum('impressions');
        $spend = (int) $daily->sum('spend');
        $invalid = RawImpressionEvent::query()->whereHas('adDelivery', fn ($query) => $query->where('creative_id', $creative->id))
            ->where('validation_status', 'invalid')->where('validated_at', '>=', $start)->where('validated_at', '<', $end)->where('validated_at', '<=', $now)->count();
        $last = AdDelivery::query()->where('creative_id', $creative->id)->where('decision', 'served')->where('requested_at', '>=', $start)->where('requested_at', '<', $end)->where('requested_at', '<=', $now)->max('requested_at');
        $metrics = ['validated_impressions' => $count, 'invalid_impressions' => $invalid,
            'invalid_rate' => $count + $invalid > 0 ? round($invalid * 100 / ($count + $invalid), 2) : null,
            'last_served_at' => $last ? CarbonImmutable::parse($last, 'UTC')->toISOString() : null];
        if ($money) {
            $metrics['spend_micros'] = $spend;
            $metrics['effective_cpm_micros'] = $count ? (int) round($spend * 1000 / $count) : null;
        }
        $points = [];
        for ($day = $start; $day->lt($end) && $day->lte($now); $day = $day->addDay()) {
            $bucket = $daily->get($day->toDateString());
            $point = ['date' => $day->toDateString(), 'impressions' => (int) ($bucket?->impressions ?? 0)];
            if ($money) {
                $point['spend_micros'] = (int) ($bucket?->spend ?? 0);
                $point['effective_cpm_micros'] = $bucket?->impressions > 0 ? (int) round($bucket->spend * 1000 / $bucket->impressions) : null;
            }
            $points[] = $point;
        }

        return [...$this->basic($creative), 'version' => $this->version($creative),
            'can_moderate' => $viewer->adminCan('admin.creatives.review') && ! $creative->trashed() && ! $this->hasDeletedParent($creative),
            'can_view_money' => $money, 'can_view_advertiser' => $viewer->adminCan('admin.users.view'), 'can_view_campaign' => $viewer->adminCan('admin.campaigns.view'),
            'metrics' => $metrics, 'points' => $points,
            'period' => ['from' => $start->toDateString(), 'to' => $end->subDay()->toDateString()],
            'history' => AdminCreativeEvent::where('creative_id', $creative->id)->latest('id')->get(['id', 'actor_name', 'action', 'details', 'created_at']),
            'generated_at' => $now->toISOString()];
    }

    public function record(AdCreative $creative, User $actor, string $action, ?string $previousStatus = null): void
    {
        AdminCreativeEvent::create(['creative_id' => $creative->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'action' => $action,
            'details' => ['previous_status' => $previousStatus, 'status' => $creative->status, 'reason' => $creative->rejection_reason,
                'message_text' => $creative->message_text, 'click_url' => $creative->click_url, 'language' => $creative->language]]);
    }

    public function invalidate(int $accountId): void
    {
        Cache::put("advertiser_campaigns_version:{$accountId}", (string) Str::uuid(), now()->addDay());
        Cache::forget("advertiser_dashboard:{$accountId}");
    }
}
