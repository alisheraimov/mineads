<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Http\Requests\Publisher\StoreServerRequest;
use App\Models\PublisherAccount;
use App\Models\Server;
use App\Models\ValidatedImpression;
use App\Services\ServerPerformanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $publisher = $this->publisher($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['pending', 'active', 'offline', 'inactive', 'suspended'])],
            'category' => ['nullable', Rule::in($this->categories())],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $publisher->servers()->with('latestHeartbeat');
        $all = (clone $query)->get();
        $summaries = $all->map(fn (Server $server): array => $this->summary($server));
        $rows = $summaries->filter(function (array $server) use ($filters): bool {
            $search = strtolower($filters['search'] ?? '');

            return (! $search || str_contains(strtolower($server['name'].' '.$server['host']), $search))
                && (empty($filters['status']) || $server['status'] === $filters['status'])
                && (empty($filters['category']) || $server['category'] === $filters['category']);
        })->sortByDesc('id')->values();
        $page = (int) ($filters['page'] ?? 1);

        return response()->json(['data' => $rows->slice(($page - 1) * 20, 20)->values(), 'meta' => [
            'page' => $page, 'per_page' => 20, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / 20)),
            'total_servers' => $all->count(), 'active_servers' => $summaries->where('status', 'active')->count(),
            'online_players' => $summaries->sum('online_players'), 'needs_attention' => $summaries->where('status', '!=', 'active')->count(),
            'generated_at' => now('UTC')->toISOString(),
        ]]);
    }

    public function store(StoreServerRequest $request): JsonResponse
    {
        $publisher = $this->publisher($request);
        $data = $request->validated();
        $result = DB::transaction(function () use ($publisher, $data): array {
            PublisherAccount::query()->whereKey($publisher->id)->lockForUpdate()->firstOrFail();
            abort_if($publisher->servers()->where('host', $data['host'])->exists(), 422, 'A server with this host already exists in your workspace.');
            $server = $publisher->servers()->create([...$data, 'status' => 'pending']);

            return $this->issueToken($server);
        });

        return response()->json(['data' => $result], 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $server): JsonResponse
    {
        $model = $this->ownedServer($request, $server)->load('latestHeartbeat');
        $now = now('UTC');
        $metrics = ValidatedImpression::query()->where('server_id', $model->id)
            ->whereBetween('validated_at', [$now->copy()->startOfMonth(), $now])
            ->selectRaw('DATE(validated_at) as date, COUNT(*) as impressions, SUM(publisher_earning_micros) as earnings')
            ->groupByRaw('DATE(validated_at)')->get();
        $today = $metrics->firstWhere('date', $now->toDateString());

        return response()->json(['data' => [
            ...$this->summary($model),
            'currency' => $this->publisher($request)->currency,
            'created_at' => $model->created_at->toISOString(),
            'activated_at' => $model->activated_at?->toISOString(),
            'setup_token_expires_at' => $model->setup_token_expires_at?->toISOString(),
            'earnings_today_micros' => (int) ($today?->earnings ?? 0),
            'earnings_month_micros' => (int) $metrics->sum('earnings'),
            'impressions_today' => (int) ($today?->impressions ?? 0),
            'generated_at' => $now->toISOString(),
        ]]);
    }

    public function performance(Request $request, int $server, ServerPerformanceService $performance): JsonResponse
    {
        $model = $this->ownedServer($request, $server);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
            'group' => ['required', Rule::in(['day', 'week', 'month'])],
            'compare' => ['sometimes', 'boolean'],
        ]);
        abort_if(CarbonImmutable::parse($data['from'])->diffInDays($data['to']) >= 366, 422, 'Choose a date range of one year or less.');

        return response()->json(['data' => $performance->report($model->id, $data['from'], $data['to'], $data['group'], $request->boolean('compare'))]);
    }

    public function regenerate(Request $request, int $server): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $server): array {
            $model = $this->ownedServer($request, $server, true);
            abort_if($model->status === 'suspended', 403, 'Suspended servers cannot be reactivated.');
            $model->apiTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $model->update(['status' => 'pending', 'activated_at' => null]);

            return $this->issueToken($model);
        });

        return response()->json(['data' => $result])->header('Cache-Control', 'no-store');
    }

    public function status(Request $request, int $server): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $server, $data): void {
            $model = $this->ownedServer($request, $server, true);
            abort_if($model->status === 'suspended', 403, 'Suspended servers cannot be changed.');
            $model->update(['status' => $data['enabled'] ? ($model->activated_at ? 'active' : 'pending') : 'inactive']);
        });

        return $this->show($request, $server);
    }

    public function destroy(Request $request, int $server): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string']]);
        DB::transaction(function () use ($request, $server, $data): void {
            $model = $this->ownedServer($request, $server, true);
            abort_unless(hash_equals($model->name, $data['name']), 422, 'Enter the server name exactly to remove it.');
            $model->apiTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $model->update(['status' => 'inactive', 'setup_token_hash' => null, 'setup_token_expires_at' => null]);
            $model->delete();
        });

        return response()->json(['message' => 'Server removed. Historical reports are preserved.']);
    }

    private function publisher(Request $request): PublisherAccount
    {
        $publisher = $request->user()->publisherAccount;
        abort_unless($publisher, 409, 'Publisher account is not set up.');
        abort_unless($publisher->status === 'active', 403, 'This publisher account has been suspended.');

        return $publisher;
    }

    private function ownedServer(Request $request, int $id, bool $lock = false): Server
    {
        return $this->publisher($request)->servers()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    private function issueToken(Server $server): array
    {
        $token = 'ma_setup_'.Str::random(48);
        $server->update(['setup_token_hash' => Hash::make($token), 'setup_token_expires_at' => now()->addHours(config('publisher.setup_token_hours'))]);

        return ['server' => $this->summary($server), 'setup_token' => $token, 'setup_token_expires_at' => $server->setup_token_expires_at->toISOString()];
    }

    private function summary(Server $server): array
    {
        $heartbeat = $server->latestHeartbeat;
        $recent = $heartbeat && $heartbeat->received_at->gt(now()->subSeconds(config('publisher.heartbeat_timeout_seconds')));
        $status = $server->status === 'active' && ! $recent ? 'offline' : $server->status;

        return [
            'id' => $server->id, 'name' => $server->name, 'host' => $server->host, 'category' => $server->category,
            'status' => $status, 'enabled' => $server->status !== 'inactive',
            'last_seen_at' => $heartbeat?->received_at?->toISOString(),
            'online_players' => $status === 'active' ? (int) ($heartbeat?->online_players ?? 0) : 0,
            'plugin_version' => $heartbeat?->plugin_version, 'minecraft_version' => $heartbeat?->minecraft_version,
            'plugin_update_available' => config('publisher.latest_plugin_version') && $heartbeat?->plugin_version
                ? version_compare($heartbeat->plugin_version, config('publisher.latest_plugin_version'), '<') : null,
        ];
    }

    private function categories(): array
    {
        return ['survival', 'skyblock', 'factions', 'minigames', 'creative', 'anarchy', 'smp', 'other'];
    }
}
