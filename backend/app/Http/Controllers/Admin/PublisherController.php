<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PublisherAccount;
use App\Models\Server;
use App\Models\ServerHeartbeat;
use App\Models\ValidatedImpression;
use App\Services\AdminServerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublisherController extends Controller
{
    public function index(Request $request, AdminServerService $service): JsonResponse
    {
        abort_unless($request->user()->adminCan('admin.servers.view'), 403, 'Your role cannot view publishers.');
        $input = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(['active', 'suspended', 'deleted'])], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', Rule::in([10, 25, 50])]]);
        $query = PublisherAccount::with(['user' => fn ($q) => $q->withTrashed()])->withCount('servers')->withCount(['servers as active_servers_count' => fn ($q) => $q->where('status', 'active')->whereNotNull('activated_at')->where('review_status', 'approved')])
            ->addSelect(['last_heartbeat_at' => ServerHeartbeat::selectRaw('MAX(received_at)')->whereIn('server_id', Server::select('id')->whereColumn('publisher_account_id', 'publisher_accounts.id'))]);
        if ($search = $input['search'] ?? null) {
            $needle = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('user', fn ($q) => $q->withTrashed()->where(fn ($q) => $q->where('name', 'like', $needle)->orWhere('email', 'like', $needle)->orWhere('id', ctype_digit(preg_replace('/^U-/i', '', $search)) ? (int) preg_replace('/^U-/i', '', $search) : -1)));
        }
        if (($input['status'] ?? null) === 'deleted') {
            $query->whereHas('user', fn ($q) => $q->onlyTrashed());
        } else {
            $query->whereHas('user', fn ($q) => $q->when(($input['status'] ?? null) === 'active', fn ($q) => $q->where('status', 'active')))->when(($input['status'] ?? null) === 'active', fn ($q) => $q->where('status', 'active'));
            if (($input['status'] ?? null) === 'suspended') {
                $query->where(fn ($q) => $q->where('status', 'suspended')->orWhereHas('user', fn ($q) => $q->where('status', 'suspended')));
            }
        }
        $money = $service->money($request->user());
        if ($money) {
            $query->addSelect(['earnings_micros' => ValidatedImpression::selectRaw('COALESCE(SUM(publisher_earning_micros),0)')->whereColumn('publisher_account_id', 'publisher_accounts.id')]);
        }
        $page = $query->latest('id')->paginate((int) ($input['per_page'] ?? 10));

        return response()->json(['data' => $page->getCollection()->map(function ($a) use ($money): array {
            $u = $a->user;
            $status = ! $u || $u->trashed() ? 'deleted' : ($u->status !== 'active' ? $u->status : $a->status);
            $row = ['id' => $u?->id, 'name' => $u?->name ?? 'Deleted publisher', 'email' => $u?->email, 'status' => $status, 'servers_count' => $a->servers_count, 'active_servers_count' => $status === 'active' ? $a->active_servers_count : 0, 'last_heartbeat_at' => $a->last_heartbeat_at];
            if ($money) {
                $row['earnings_micros'] = (int) $a->earnings_micros;
            }

            $row['last_heartbeat_at'] = $a->last_heartbeat_at ? CarbonImmutable::parse($a->last_heartbeat_at, 'UTC')->toISOString() : null;

            return $row;
        }), 'meta' => $service->meta($page), 'can_view_money' => $money, 'can_view_publisher' => $request->user()->adminCan('admin.users.view')]);
    }
}
