<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdCreative;
use App\Models\Campaign;
use App\Models\User;
use App\Services\AdminCreativeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CreativeController extends Controller
{
    public function index(Request $request, AdminCreativeService $service): JsonResponse
    {
        $this->authorizeView($request);
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(['pending_review', 'approved', 'rejected', 'all'])],
            'advertiser_id' => ['nullable', 'integer', 'min:1'], 'campaign_id' => ['nullable', 'integer', 'min:1'],
            'language' => ['nullable', Rule::in([...config('campaigns.languages'), 'none'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['nullable', Rule::in([10, 25, 50])],
        ]);
        $status = $input['status'] ?? 'pending_review';
        $query = $service->query();
        if ($status !== 'all') {
            $query->whereNull('ad_creatives.deleted_at')->where('ad_creatives.status', $status)->whereIn('ad_creatives.id', $service->reviewableQuery()->select('ad_creatives.id'));
        }
        if ($search = trim($input['search'] ?? '')) {
            $query->where(function ($query) use ($search): void {
                $query->whereLike('message_text', $this->pattern($search));
                $id = preg_replace('/^CR-/i', '', $search);
                if (ctype_digit($id)) {
                    $query->orWhere('ad_creatives.id', (int) $id);
                }
            });
        }
        if (! empty($input['advertiser_id'])) {
            $query->whereHas('campaign', fn ($query) => $query->withTrashed()->whereHas('advertiserAccount', fn ($query) => $query->where('user_id', $input['advertiser_id'])));
        }
        if (! empty($input['campaign_id'])) {
            $query->where('campaign_id', $input['campaign_id']);
        }
        if (! empty($input['language'])) {
            $query->when($input['language'] === 'none', fn ($query) => $query->whereNull('language'), fn ($query) => $query->where('language', $input['language']));
        }
        $query->when($status === 'pending_review', fn ($query) => $query->orderByRaw('COALESCE(review_submitted_at, created_at) ASC')->orderBy('id'), fn ($query) => $query->orderByDesc('updated_at')->orderByDesc('id'));
        $page = $query->paginate($input['per_page'] ?? 10);
        $advertiser = ! empty($input['advertiser_id']) ? User::withTrashed()->whereHas('advertiserAccount')->find($input['advertiser_id']) : null;
        $campaign = ! empty($input['campaign_id']) ? Campaign::withTrashed()->find($input['campaign_id']) : null;

        return response()->json(['data' => $page->getCollection()->map(fn (AdCreative $creative) => $service->basic($creative))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'pending_count' => $service->reviewableQuery()->where('status', 'pending_review')->count(),
            'selected_advertiser' => $advertiser?->only(['id', 'name', 'email']), 'selected_campaign' => $campaign?->only(['id', 'name']),
            'languages' => config('campaigns.languages'), 'can_review' => $request->user()->adminCan('admin.creatives.review'),
            'can_view_campaign' => $request->user()->adminCan('admin.campaigns.view'), 'can_view_advertiser' => $request->user()->adminCan('admin.users.view')]);
    }

    public function options(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $input = $request->validate(['kind' => ['required', Rule::in(['advertisers', 'campaigns'])], 'search' => ['nullable', 'string', 'max:255'], 'advertiser_id' => ['nullable', 'integer', 'min:1']]);
        $search = trim($input['search'] ?? '');
        if ($input['kind'] === 'advertisers') {
            $query = User::withTrashed()->whereHas('advertiserAccount');
            if ($search !== '') {
                $query->where(fn ($query) => $query->whereLike('name', $this->pattern($search))->orWhereLike('email', $this->pattern($search)));
            }
            $items = $query->orderBy('name')->limit(25)->get(['id', 'name', 'email']);
        } else {
            $query = Campaign::withTrashed();
            if (! empty($input['advertiser_id'])) {
                $query->whereHas('advertiserAccount', fn ($query) => $query->where('user_id', $input['advertiser_id']));
            }
            if ($search !== '') {
                $query->where(function ($query) use ($search): void {
                    $query->whereLike('name', $this->pattern($search));
                    $id = preg_replace('/^CMP-/i', '', $search);
                    if (ctype_digit($id)) {
                        $query->orWhere('id', (int) $id);
                    }
                });
            }
            $items = $query->orderBy('name')->limit(25)->get(['id', 'name']);
        }

        return response()->json(['data' => $items]);
    }

    public function show(Request $request, int $id, AdminCreativeService $service): JsonResponse
    {
        $this->authorizeView($request);
        [$start, $end] = $this->period($request);

        return response()->json(['data' => $service->details($service->query()->findOrFail($id), $request->user(), $start, $end)]);
    }

    public function moderate(Request $request, int $id, AdminCreativeService $service): JsonResponse
    {
        return $this->change($request, $id, $service, false);
    }

    public function destroy(Request $request, int $id, AdminCreativeService $service): JsonResponse
    {
        return $this->change($request, $id, $service, true);
    }

    private function change(Request $request, int $id, AdminCreativeService $service, bool $delete): JsonResponse
    {
        $this->authorizeView($request);
        abort_unless($request->user()->adminCan('admin.creatives.review'), 403, 'Your role cannot moderate creatives.');
        $rules = ['version' => ['required', 'string', 'size:64']];
        if ($delete) {
            $rules['confirmation'] = ['required', 'string', Rule::in(['CR-'.$id])];
        } else {
            $rules += ['action' => ['required', Rule::in(['approve', 'reject', 'return_to_review'])],
                'reason' => ['required_if:action,reject', 'nullable', Rule::in(array_keys(AdminCreativeService::REASONS))],
                'explanation' => ['required_if:reason,other', 'nullable', 'string', 'max:2000'],
                'confirm_withdrawal' => ['sometimes', 'boolean']];
        }
        $input = $request->validate($rules);
        [$start, $end] = $this->period($request);
        $accountId = DB::transaction(function () use ($request, $id, $input, $service, $delete): int {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($actor->role === 'admin' && $actor->status === 'active' && $actor->hasVerifiedEmail() && $actor->adminCan('admin.creatives.review'), 403);
            $campaignId = AdCreative::withTrashed()->findOrFail($id)->campaign_id;
            Campaign::withTrashed()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
            $creative = $service->query()->lockForUpdate()->findOrFail($id);
            abort_if($creative->trashed() || $service->hasDeletedParent($creative), 409, 'This creative or its campaign/advertiser was deleted. It is read-only.');
            abort_unless(hash_equals($service->version($creative), $input['version']), 409, 'This creative changed. Reload the latest content before deciding.');
            $previous = $creative->status;
            if ($delete) {
                $creative->delete();
                $action = 'deleted';
            } else {
                $action = $input['action'];
                abort_if($action === 'approve' && $previous !== 'pending_review', 409, 'Only pending creatives can be approved.');
                abort_if($action === 'reject' && ! in_array($previous, ['pending_review', 'approved'], true), 409, 'This creative is already rejected.');
                abort_if($action === 'return_to_review' && ! in_array($previous, ['approved', 'rejected'], true), 409, 'This creative is already pending review.');
                abort_if($previous === 'approved' && ! ($input['confirm_withdrawal'] ?? false), 422, 'Confirm that future serving will stop.');
                $creative->status = match ($action) {
                    'approve' => 'approved', 'reject' => 'rejected', default => 'pending_review'
                };
                $creative->rejection_code = $action === 'reject' ? $input['reason'] : null;
                $creative->rejection_explanation = $action === 'reject' ? ($input['explanation'] ?? null) : null;
                $creative->rejection_reason = $action === 'reject' ? AdminCreativeService::REASONS[$input['reason']].(! empty($input['explanation']) ? ': '.$input['explanation'] : '') : null;
                if ($action === 'return_to_review') {
                    $creative->review_submitted_at = now();
                }
                $creative->save();
                $action = match ($action) {
                    'approve' => 'approved', 'reject' => 'rejected', default => 'returned_to_review'
                };
            }
            $service->record($creative, $actor, $action, $previous);

            return $creative->campaign->advertiser_account_id;
        });
        $service->invalidate($accountId);

        return response()->json(['data' => $service->details($service->query()->findOrFail($id), $request->user(), $start, $end), 'message' => $delete ? 'Creative deleted. History has been retained.' : 'Review decision saved.']);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->adminCan('admin.creatives.view') || $request->user()->adminCan('admin.creatives.review'), 403, 'Your role cannot view creatives.');
    }

    private function period(Request $request): array
    {
        $input = $request->validate(['period' => ['sometimes', Rule::in(['today', 'yesterday', 'last7', 'last30', 'custom'])],
            'from' => ['required_if:period,custom', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today']]);
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = match ($input['period'] ?? 'last30') {
            'today' => $today, 'yesterday' => $today->subDay(), 'last7' => $today->subDays(6), 'custom' => CarbonImmutable::parse($input['from'], 'UTC'), default => $today->subDays(29)
        };
        $end = match ($input['period'] ?? 'last30') {
            'yesterday' => $today, 'custom' => CarbonImmutable::parse($input['to'], 'UTC')->addDay(), default => $today->addDay()
        };
        abort_if($start->diffInDays($end) > 366, 422, 'Choose a range of up to 366 days.');

        return [$start, $end];
    }

    private function pattern(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }
}
