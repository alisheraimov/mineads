<?php

namespace App\Http\Controllers\Advertiser;

use App\Http\Controllers\Controller;
use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\ValidatedImpression;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class BillingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $account = $request->user()->advertiserAccount()->first();
        abort_unless($account, 409, 'Advertiser account is not set up.');
        $input = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $page = (int) ($input['page'] ?? 1);
        $snapshot = Cache::remember("advertiser_billing:v1:{$account->id}", 30, fn (): array => $this->snapshot($account));
        $total = count($snapshot['months']);
        $snapshot['months'] = array_slice($snapshot['months'], ($page - 1) * 12, 12);
        $snapshot['pagination'] = ['page' => $page, 'last_page' => max(1, (int) ceil($total / 12)), 'total' => $total];

        return response()->json(['data' => $snapshot]);
    }

    private function snapshot(AdvertiserAccount $account): array
    {
        $now = CarbonImmutable::now('UTC');
        $currentMonth = $now->startOfMonth();
        $monthExpression = 'SUBSTR(CAST(created_at AS TEXT), 1, 7)';
        $totals = AdvertiserLedgerEntry::query()
            ->where('advertiser_account_id', $account->id)->where('currency', $account->currency)
            ->selectRaw("{$monthExpression} as month, SUM(amount_micros) as net_micros,
                SUM(CASE WHEN type = 'deposit' THEN amount_micros ELSE 0 END) as funds_added_micros,
                SUM(CASE WHEN type = 'spend' THEN -amount_micros ELSE 0 END) as spend_micros,
                SUM(CASE WHEN type NOT IN ('deposit', 'spend') THEN amount_micros ELSE 0 END) as adjustments_micros")
            ->groupByRaw($monthExpression)->orderBy('month')->get()->keyBy('month');
        $firstMonth = $totals->isEmpty() ? $currentMonth : CarbonImmutable::parse($totals->keys()->first().'-01', 'UTC');
        $lastMonth = $totals->isEmpty() ? $currentMonth : $currentMonth->max(CarbonImmutable::parse($totals->keys()->last().'-01', 'UTC'));
        $balance = 0;
        $months = [];
        for ($month = $firstMonth->min($currentMonth); $month->lessThanOrEqualTo($lastMonth); $month = $month->addMonth()) {
            $row = $totals[$month->format('Y-m')] ?? null;
            $opening = $balance;
            $balance += (int) ($row->net_micros ?? 0);
            $months[] = ['month' => $month->format('Y-m'), 'is_current' => $month->equalTo($currentMonth),
                'starting_balance_micros' => $opening, 'funds_added_micros' => (int) ($row->funds_added_micros ?? 0),
                'spend_micros' => (int) ($row->spend_micros ?? 0), 'adjustments_micros' => (int) ($row->adjustments_micros ?? 0),
                'ending_balance_micros' => $balance];
        }
        $current = collect($months)->firstWhere('is_current', true);

        return [
            'account' => ['name' => $account->name, 'status' => $account->status, 'currency' => $account->currency],
            'metrics' => ['available_balance_micros' => $balance, 'funds_added_month_micros' => $current['funds_added_micros'],
                'spend_month_micros' => $current['spend_micros'],
                'impressions_month' => ValidatedImpression::query()->where('advertiser_account_id', $account->id)->whereBetween('validated_at', [$currentMonth, $now->endOfMonth()])->count()],
            'low_balance' => $balance < 10000000, 'funding_available' => false,
            'months' => array_reverse($months), 'generated_at' => $now->toISOString(),
        ];
    }
}
