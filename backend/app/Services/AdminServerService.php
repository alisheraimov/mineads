<?php

namespace App\Services;

use App\Models\RawImpressionEvent;
use App\Models\Server;
use App\Models\ServerHeartbeat;
use App\Models\ServerReviewEvent;
use App\Models\User;
use App\Models\ValidatedImpression;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class AdminServerService
{
    public function query(): Builder
    {
        return Server::withTrashed()->with(['latestHeartbeat', 'publisherAccount.user' => fn ($q) => $q->withTrashed()]);
    }

    public function money(User $viewer): bool
    {
        return $viewer->adminCan('admin.servers.manage') || $viewer->adminCan('admin.finance.view');
    }

    public function basic(Server $server): array
    {
        $publisher = $server->publisherAccount;
        $user = $publisher?->user;
        $heartbeat = $server->latestHeartbeat;
        $online = $heartbeat && $heartbeat->received_at->gt(now()->subSeconds(config('publisher.heartbeat_timeout_seconds'))) && $heartbeat->received_at->lte(now());

        return [
            'id' => $server->id, 'name' => $server->name, 'host' => $server->host, 'category' => $server->category,
            'status' => $server->trashed() ? 'deleted' : ($server->status === 'inactive' ? 'disabled' : $server->status),
            'review_status' => $server->review_status, 'legacy_approval' => $server->legacy_approval, 'review_reason' => $server->review_reason, 'suspension_reason' => $server->suspension_reason,
            'connection' => $heartbeat ? ($online ? 'online' : 'offline') : 'never_connected',
            'last_seen_at' => $heartbeat?->received_at?->toISOString(), 'online_players' => $online ? (int) $heartbeat->online_players : null,
            'max_players' => $online ? $heartbeat->max_players : null, 'plugin_version' => $heartbeat?->plugin_version, 'minecraft_version' => $heartbeat?->minecraft_version,
            'plugin_update_available' => config('publisher.latest_plugin_version') && $heartbeat?->plugin_version ? version_compare($heartbeat->plugin_version, config('publisher.latest_plugin_version'), '<') : false,
            'publisher' => $user ? ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'status' => $user->trashed() ? 'deleted' : ($user->status !== 'active' ? $user->status : $publisher->status)] : null,
            'has_deleted_parent' => ! $user || $user->trashed(),
            'created_at' => $server->created_at->toISOString(), 'activated_at' => $server->activated_at?->toISOString(),
            'submitted_at' => $server->review_submitted_at?->toISOString(), 'reviewed_at' => $server->reviewed_at?->toISOString(), 'deleted_at' => $server->deleted_at?->toISOString(),
        ];
    }

    public function details(Server $server, User $viewer): array
    {
        $basic = $this->basic($server);

        return [...$basic, 'version' => app(ServerReviewService::class)->version($server),
            'can_manage' => $viewer->adminCan('admin.servers.manage') && ! $server->trashed() && ! $basic['has_deleted_parent'],
            'can_view_money' => $this->money($viewer), 'can_view_publisher' => $viewer->adminCan('admin.users.view'),
            'history' => ServerReviewEvent::where('server_id', $server->id)->latest('id')->limit(5)->get(['id', 'actor_name', 'action', 'details', 'created_at']),
        ];
    }

    public function activity(Server $server, User $viewer, CarbonImmutable $start, CarbonImmutable $end, string $status, int $perPage): array
    {
        $now = CarbonImmutable::now('UTC');
        $hourly = $start->diffInHours($end) <= 25;
        $driver = $server->getConnection()->getDriverName();
        $format = fn (string $field): string => $hourly ? ($driver === 'sqlite' ? "strftime('%Y-%m-%d %H:00:00', {$field})" : "to_char({$field}, 'YYYY-MM-DD HH24:00:00')") : "DATE({$field})";
        $raw = RawImpressionEvent::where('server_id', $server->id)->whereRaw('COALESCE(validated_at, received_at) >= ?', [$start])->whereRaw('COALESCE(validated_at, received_at) < ?', [$end])->whereRaw('COALESCE(validated_at, received_at) <= ?', [$now]);
        $bucket = $format('COALESCE(validated_at, received_at)');
        $counts = (clone $raw)->selectRaw("{$bucket} as bucket, SUM(CASE WHEN validation_status = 'valid' THEN 1 ELSE 0 END) as valid_count, SUM(CASE WHEN validation_status = 'invalid' THEN 1 ELSE 0 END) as invalid_count, SUM(CASE WHEN validation_status = 'pending' THEN 1 ELSE 0 END) as pending_count")->groupByRaw($bucket)->get()->keyBy('bucket');
        $hbBucket = $format('received_at');
        $players = ServerHeartbeat::where('server_id', $server->id)->where('received_at', '>=', $start)->where('received_at', '<', $end)->where('received_at', '<=', $now)->selectRaw("{$hbBucket} as bucket, AVG(online_players) as average, MIN(online_players) as minimum, MAX(online_players) as maximum")->groupByRaw($hbBucket)->get()->keyBy('bucket');
        $money = $this->money($viewer);
        $earnings = collect();
        if ($money) {
            $eb = $format('validated_at');
            $earnings = ValidatedImpression::where('server_id', $server->id)->where('validated_at', '>=', $start)->where('validated_at', '<', $end)->where('validated_at', '<=', $now)->selectRaw("{$eb} as bucket, SUM(publisher_earning_micros) as earnings")->groupByRaw($eb)->get()->keyBy('bucket');
        }
        $points = [];
        for ($at = $hourly ? $start->startOfHour() : $start->startOfDay(); $at->lt($end) && $at->lte($now); $at = $hourly ? $at->addHour() : $at->addDay()) {
            $key = $hourly ? $at->format('Y-m-d H:00:00') : $at->toDateString();
            $count = $counts->get($key);
            $sample = $players->get($key);
            $point = ['date' => $at->toISOString(), 'valid' => (int) ($count?->valid_count ?? 0), 'invalid' => (int) ($count?->invalid_count ?? 0),
                'players' => $sample ? round((float) $sample->average, 1) : null, 'minimum' => $sample ? (int) $sample->minimum : null, 'maximum' => $sample ? (int) $sample->maximum : null];
            if ($money) {
                $point['earnings_micros'] = (int) ($earnings->get($key)?->earnings ?? 0);
            }
            $points[] = $point;
        }
        $valid = (int) $counts->sum('valid_count');
        $invalid = (int) $counts->sum('invalid_count');
        $metrics = ['valid' => $valid, 'invalid' => $invalid, 'pending' => (int) $counts->sum('pending_count'), 'invalid_rate' => $valid + $invalid ? round($invalid * 100 / ($valid + $invalid), 2) : null];
        if ($money) {
            $metrics['earnings_micros'] = (int) $earnings->sum('earnings');
        }
        $reasons = (clone $raw)->where('validation_status', 'invalid')->selectRaw('invalid_reason as reason, COUNT(*) as count')->groupBy('invalid_reason')->orderByDesc('count')->get()->map(fn ($r) => ['reason' => $r->reason, 'count' => (int) $r->count, 'percent' => $invalid ? round($r->count * 100 / $invalid, 1) : 0]);
        $page = (clone $raw)->when($status !== 'all', fn ($q) => $q->where('validation_status', $status))->with('adDelivery.campaign')->orderByDesc('received_at')->orderByDesc('id')->paginate($perPage);
        $rows = $page->getCollection()->map(fn ($event) => ['id' => $event->id, 'time' => $event->received_at->toISOString(), 'validation_status' => $event->validation_status, 'invalid_reason' => $event->invalid_reason, 'campaign' => $event->adDelivery?->campaign ? ['id' => $event->adDelivery->campaign->id, 'name' => $event->adDelivery->campaign->name] : null]);

        return ['metrics' => $metrics, 'points' => $points, 'reasons' => $reasons, 'impressions' => $rows, 'meta' => $this->meta($page), 'period' => ['from' => $start->toISOString(), 'to' => $end->min($now)->toISOString(), 'hourly' => $hourly], 'can_view_campaign' => $viewer->adminCan('admin.campaigns.view')];
    }

    public function meta(mixed $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()];
    }
}
