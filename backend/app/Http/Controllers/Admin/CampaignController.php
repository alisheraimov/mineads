<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminCampaignEvent;
use App\Models\AdvertiserAccount;
use App\Models\Campaign;
use App\Models\User;
use App\Services\AdminCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CampaignController extends Controller
{
    public function index(Request $request, AdminCampaignService $campaigns): JsonResponse
    {
        $this->authorizeView($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'advertiser_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'paused', 'ended', 'draft', 'deleted'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['nullable', Rule::in([10, 25, 50])],
        ]);
        $query = $campaigns->listingQuery();
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(function ($query) use ($search): void {
                $query->whereLike('name', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
                $id = preg_replace('/^CMP-/i', '', $search);
                if (ctype_digit($id)) {
                    $query->orWhere('campaigns.id', (int) $id);
                }
            });
        }
        if (! empty($filters['advertiser_id'])) {
            $query->whereHas('advertiserAccount', fn ($query) => $query->where('user_id', $filters['advertiser_id']));
        }
        if (($filters['status'] ?? '') === 'deleted') {
            $query->onlyTrashed();
        } elseif (! empty($filters['status'])) {
            $query->whereNull('campaigns.deleted_at')->where('campaigns.status', $filters['status']);
        }
        $page = $query->orderByDesc('campaigns.id')->paginate($filters['per_page'] ?? 10);
        $money = $campaigns->canViewMoney($request->user());
        $selected = ! empty($filters['advertiser_id']) ? User::withTrashed()->whereHas('advertiserAccount')->find($filters['advertiser_id']) : null;

        return response()->json(['data' => $page->getCollection()->map(fn (Campaign $campaign) => $campaigns->listItem($campaign, $money))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'selected_advertiser' => $selected?->only(['id', 'name', 'email']), 'can_view_money' => $money,
            'can_view_advertiser' => $request->user()->adminCan('admin.users.view'), 'generated_at' => now()->toISOString()]);
    }

    public function advertisers(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $input = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $query = User::withTrashed()->whereHas('advertiserAccount');
        if ($search = trim($input['search'] ?? '')) {
            $query->where(function ($query) use ($search): void {
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->whereLike('name', $pattern)->orWhereLike('email', $pattern);
                $id = preg_replace('/^U-/i', '', $search);
                if (ctype_digit($id)) {
                    $query->orWhere('id', (int) $id);
                }
            });
        }

        return response()->json(['data' => $query->orderBy('name')->limit(25)->get(['id', 'name', 'email'])]);
    }

    public function show(Request $request, int $id, AdminCampaignService $campaigns): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json(['data' => $campaigns->details(Campaign::withTrashed()->findOrFail($id), $request->user())]);
    }

    public function creatives(Request $request, int $id): JsonResponse
    {
        $this->authorizeView($request);
        $input = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $campaign = Campaign::withTrashed()->findOrFail($id);
        $page = $campaign->creatives()->withTrashed()->orderByDesc('id')->paginate(20, ['*'], 'page', $input['page'] ?? 1);

        return response()->json(['data' => $page->getCollection()->map(fn ($creative): array => [
            'id' => $creative->id, 'name' => $creative->name, 'message_text' => $creative->message_text, 'click_url' => $creative->click_url,
            'language' => $creative->language, 'status' => $creative->trashed() ? 'deleted' : $creative->status,
            'is_paused' => $creative->is_paused, 'rejection_reason' => $creative->rejection_reason, 'created_at' => $creative->created_at->toISOString(),
        ])->values(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function destroy(Request $request, int $id, AdminCampaignService $campaigns): JsonResponse
    {
        $this->authorizeView($request);
        abort_unless($request->user()->adminCan('admin.campaigns.manage'), 403, 'Your role cannot delete campaigns.');
        $input = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $accountId = DB::transaction(function () use ($request, $id, $input): int {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($actor->role === 'admin' && $actor->status === 'active' && $actor->hasVerifiedEmail() && $actor->adminCan('admin.campaigns.manage'), 403);
            $campaign = Campaign::withTrashed()->lockForUpdate()->findOrFail($id);
            abort_if($campaign->trashed(), 409, 'This campaign has already been deleted.');
            if ($input['name'] !== $campaign->name) {
                throw ValidationException::withMessages(['name' => 'Enter the campaign name exactly to confirm deletion.']);
            }
            AdvertiserAccount::query()->whereKey($campaign->advertiser_account_id)->lockForUpdate()->firstOrFail();
            $campaign->forceFill(['deleted_by' => $actor->id])->save();
            $campaign->delete();
            AdminCampaignEvent::query()->create(['campaign_id' => $campaign->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'action' => 'deleted', 'details' => ['name' => $campaign->name, 'previous_status' => $campaign->status]]);

            return $campaign->advertiser_account_id;
        });
        Cache::put("advertiser_campaigns_version:{$accountId}", (string) Str::uuid(), now()->addDay());
        Cache::forget("advertiser_dashboard:{$accountId}");

        return response()->json(['message' => 'Campaign deleted. Historical records have been retained.',
            'data' => $campaigns->details(Campaign::withTrashed()->findOrFail($id), $request->user())]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->adminCan('admin.campaigns.view'), 403, 'Your role cannot view campaigns.');
    }
}
