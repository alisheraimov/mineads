<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\PublisherLedgerEntry;
use App\Services\PublisherBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayoutController extends Controller
{
    public function show(Request $request, PublisherBalanceService $balances): JsonResponse
    {
        $publisher = $request->user()->publisherAccount()->first();
        abort_unless($publisher, 409, 'Publisher account is not set up.');
        abort_unless($publisher->status === 'active', 403, 'This publisher account has been suspended.');
        $input = $request->validate([
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:9999'],
            'status' => ['nullable', Rule::in(['pending', 'processing', 'paid', 'failed', 'cancelled'])],
        ]);
        $now = CarbonImmutable::now('UTC');
        $query = Payout::query()->where('publisher_account_id', $publisher->id)->where('currency', $publisher->currency);
        $years = (clone $query)->selectRaw('SUBSTR(CAST(period_start AS TEXT), 1, 4) as year')
            ->distinct()->orderByDesc('year')->pluck('year')->map(fn ($year): int => (int) $year)->all();
        $paid = (int) (clone $query)->where('status', 'paid')->sum('amount_micros');
        $pending = (int) PublisherLedgerEntry::query()->where('publisher_account_id', $publisher->id)
            ->where('currency', $publisher->currency)->where('type', 'pending_earning')
            ->whereBetween('created_at', [$now->startOfMonth(), $now])->sum('amount_micros');
        $available = $balances->availableMicros($publisher);
        $threshold = max(0, (int) config('publisher.minimum_payout_micros', 50000000));
        $holdMin = max(0, (int) config('publisher.payout_hold_days_min', 3));
        $holdMax = max($holdMin, (int) config('publisher.payout_hold_days_max', 4));
        $windowMonth = $now->startOfMonth();
        if ($now->greaterThan($windowMonth->addDays($holdMax)->endOfDay())) {
            $windowMonth = $windowMonth->addMonth();
        }
        $history = (clone $query)
            ->when(isset($input['year']), fn ($query) => $query->whereYear('period_start', $input['year']))
            ->when(isset($input['status']), fn ($query) => $query->where('status', $input['status']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(12, ['*'], 'page', (int) ($input['page'] ?? 1));

        return response()->json(['data' => [
            'account' => $publisher->only(['name', 'currency', 'status']),
            'metrics' => [
                'available_payout_micros' => $available,
                'pending_earnings_micros' => max(0, $pending),
                'lifetime_paid_micros' => $paid,
            ],
            'policy' => [
                'minimum_payout_micros' => $threshold,
                'threshold_met' => $available >= $threshold,
                'hold_days_min' => $holdMin, 'hold_days_max' => $holdMax,
                'estimated_window_start' => $windowMonth->addDays($holdMin)->toDateString(),
                'estimated_window_end' => $windowMonth->addDays($holdMax)->toDateString(),
            ],
            'payout_method' => ['provider' => 'stripe', 'status' => 'not_connected', 'account' => null, 'integration_available' => false],
            'history' => $history->getCollection()->map(fn (Payout $payout): array => [
                'id' => $payout->id, 'period_start' => $payout->period_start->toDateString(),
                'period_end' => $payout->period_end->toDateString(), 'amount_micros' => (int) $payout->amount_micros,
                'currency' => $payout->currency, 'status' => $payout->status, 'provider' => $payout->provider,
                'created_at' => $payout->created_at->toISOString(),
                'paid_at' => $payout->status === 'paid' ? $payout->paid_at?->toISOString() : null,
            ])->values(),
            'pagination' => ['page' => $history->currentPage(), 'last_page' => $history->lastPage(), 'total' => $history->total(), 'per_page' => $history->perPage()],
            'filter_years' => $years,
            'period' => ['start' => $now->startOfMonth()->toDateString(), 'end' => $now->toDateString(), 'timezone' => 'UTC'],
            'generated_at' => $now->toISOString(),
        ]]);
    }
}
