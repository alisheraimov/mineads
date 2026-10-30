<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServerReviewEvent;
use App\Models\User;
use App\Services\AdminServerService;
use App\Services\ServerReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    public function __construct(private readonly AdminServerService $service, private readonly ServerReviewService $review) {}

    private function view(Request $request): void
    {
        abort_unless($request->user()->adminCan('admin.servers.view'), 403, 'Your role cannot view servers.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->view($request);
        $input = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'review' => ['nullable', Rule::in(['all', 'pending_review', 'approved', 'rejected', 'awaiting_activation'])], 'publisher' => ['nullable', 'integer', 'min:1'], 'connection' => ['nullable', Rule::in(['online', 'offline', 'never_connected'])], 'category' => ['nullable', Rule::in(['survival', 'skyblock', 'factions', 'minigames', 'creative', 'anarchy', 'smp', 'other'])], 'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended', 'pending'])], 'include_deleted' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', Rule::in([10, 25, 50])]]);
        $base = $this->service->query();
        $live = fn ($q) => $q->whereNull('deleted_at')->whereHas('publisherAccount.user');
        $counts = [];
        foreach (['all', 'pending_review', 'approved', 'rejected', 'awaiting_activation'] as $state) {
            $q = clone $base;
            $live($q);
            if ($state !== 'all') {
                $q->where('review_status', $state);
            }
            if ($state === 'pending_review') {
                $q->whereNotNull('activated_at');
            }
            $counts[$state] = $q->count();
        }
        $state = $input['review'] ?? 'all';
        if (! $request->boolean('include_deleted') || $state !== 'all') {
            $live($base);
        }
        $base->when($state !== 'all', fn ($q) => $q->where('review_status', $state));
        $base->when($state === 'pending_review', fn ($q) => $q->whereNotNull('activated_at'));
        if ($search = $input['search'] ?? null) {
            $needle = '%'.addcslashes($search, '%_\\').'%';
            $base->where(fn ($q) => $q->where('name', 'like', $needle)->orWhere('host', 'like', $needle)->orWhere('id', ctype_digit(preg_replace('/^SRV-/i', '', $search)) ? (int) preg_replace('/^SRV-/i', '', $search) : -1));
        }
        $base->when(isset($input['publisher']), fn ($q) => $q->whereHas('publisherAccount', fn ($q) => $q->where('user_id', $input['publisher'])));
        foreach (['category', 'status'] as $key) {
            $base->when(isset($input[$key]), fn ($q) => $q->where($key, $input[$key]));
        }
        if ($connection = $input['connection'] ?? null) {
            if ($connection === 'never_connected') {
                $base->whereDoesntHave('latestHeartbeat');
            } else {
                $online = fn ($q) => $q->where('received_at', '>', now()->subSeconds(config('publisher.heartbeat_timeout_seconds')))->where('received_at', '<=', now());
                if ($connection === 'online') {
                    $base->whereHas('latestHeartbeat', $online);
                } else {
                    $base->whereHas('latestHeartbeat')->whereDoesntHave('latestHeartbeat', $online);
                }
            }
        }
        $page = ($state === 'pending_review' ? $base->orderBy('review_submitted_at')->orderBy('id') : $base->latest('id'))->paginate((int) ($input['per_page'] ?? 10));

        return response()->json(['data' => $page->getCollection()->map(fn ($s) => $this->service->basic($s)), 'meta' => $this->service->meta($page), 'counts' => $counts, 'can_view_publisher' => $request->user()->adminCan('admin.users.view')]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->view($request);

        return response()->json(['data' => $this->service->details($this->service->query()->findOrFail($id), $request->user())]);
    }

    public function activity(Request $request, int $id): JsonResponse
    {
        $this->view($request);
        $input = $request->validate(['period' => ['sometimes', Rule::in(['last24', 'last7', 'last30', 'custom'])], 'from' => ['required_if:period,custom', 'date_format:Y-m-d', 'before_or_equal:today'], 'to' => ['required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'], 'validation' => ['sometimes', Rule::in(['all', 'valid', 'invalid', 'pending', 'review'])], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', Rule::in([10, 25, 50])]]);
        $now = CarbonImmutable::now('UTC');
        $period = $input['period'] ?? 'last7';
        $end = $period === 'custom' ? CarbonImmutable::parse($input['to'], 'UTC')->addDay() : $now;
        $start = match ($period) {
            'last24' => $now->subHours(24), 'last30' => $now->subDays(29)->startOfDay(), 'custom' => CarbonImmutable::parse($input['from'], 'UTC'), default => $now->subDays(6)->startOfDay()
        };
        abort_if($start->diffInDays($end) > 366, 422, 'Choose a date range of 366 days or less.');

        return response()->json(['data' => $this->service->activity($this->service->query()->findOrFail($id), $request->user(), $start, $end, $input['validation'] ?? 'all', (int) ($input['per_page'] ?? 10))]);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $this->view($request);
        $this->service->query()->findOrFail($id);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = ServerReviewEvent::where('server_id', $id)->latest('id')->paginate(20, ['id', 'actor_name', 'action', 'details', 'created_at']);

        return response()->json(['data' => $page->items(), 'meta' => $this->service->meta($page)]);
    }

    public function moderate(Request $request, int $id): JsonResponse
    {
        return $this->mutate($request, $id, false);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->mutate($request, $id, true);
    }

    private function mutate(Request $request, int $id, bool $delete): JsonResponse
    {
        abort_unless($request->user()->adminCan('admin.servers.manage'), 403, 'Your role cannot manage servers.');
        $input = $request->validate(['version' => ['required', 'string', 'size:64'], 'action' => [$delete ? 'nullable' : 'required', Rule::in(['approve', 'reject', 'suspend', 'reactivate'])], 'reason' => ['required_if:action,reject,suspend', 'nullable', 'string', 'max:2000'], 'confirmation' => [$delete ? 'required' : 'nullable', Rule::in(['SRV-'.$id])]]);
        DB::transaction(function () use ($request, $id, $input, $delete): void {
            $actor = User::findOrFail($request->user()->id);
            $actor->unsetRelation('roles');
            $actor->unsetRelation('permissions');
            abort_unless($actor->status === 'active' && $actor->email_verified_at && $actor->adminCan('admin.servers.manage'), 403, 'Your access changed.');
            $server = $this->service->query()->lockForUpdate()->findOrFail($id);
            abort_if($server->trashed() || $this->service->basic($server)['has_deleted_parent'], 409, 'This server or publisher was deleted. It is read-only.');
            abort_unless(hash_equals($this->review->version($server), $input['version']), 409, 'This server changed. Refresh before deciding.');
            $action = $delete ? 'delete' : $input['action'];
            if ($action === 'approve' || $action === 'reject') {
                abort_unless($server->activated_at && $server->review_status === 'pending_review' && $server->status !== 'suspended', 409, 'Only activated servers awaiting review can be reviewed.');
                $server->update(['review_status' => $action === 'approve' ? 'approved' : 'rejected', 'reviewed_at' => now(), 'review_reason' => $action === 'reject' ? trim($input['reason']) : null, 'legacy_approval' => false]);
            } elseif ($action === 'suspend') {
                abort_if($server->status === 'suspended', 409, 'This server is already suspended.');
                $server->update(['status_before_suspension' => $server->status, 'status' => 'suspended', 'suspension_reason' => trim($input['reason'])]);
            } elseif ($action === 'reactivate') {
                abort_unless($server->status === 'suspended', 409, 'This server is not suspended.');
                $server->update(['status' => $server->status_before_suspension ?? ($server->activated_at ? 'active' : 'pending'), 'status_before_suspension' => null, 'suspension_reason' => null]);
            } else {
                $server->apiTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
                $server->update(['setup_token_hash' => null, 'setup_token_expires_at' => null]);
                $server->delete();
            }
            $this->review->record($server, $actor, match ($action) {
                'approve' => 'approved','reject' => 'rejected','suspend' => 'suspended','reactivate' => 'reactivated',default => 'deleted'
            });
        }, 3);

        return response()->json(['data' => $this->service->details($this->service->query()->findOrFail($id), $request->user()), 'message' => $delete ? 'Server deleted. History retained.' : 'Server updated.']);
    }
}
