<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\PublisherAccount;
use App\Models\PublisherLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PublisherPayoutService
{
    public function __construct(private readonly PublisherBalanceService $balances) {}

    /**
     * @return array{settled_entries: int, created_payouts: int}
     */
    public function prepare(): array
    {
        $now = CarbonImmutable::now('UTC');
        $cycleMonth = $now->startOfMonth();
        $holdDays = max(0, (int) config('publisher.payout_hold_days_max', 4));

        if ($now->lt($cycleMonth->addDays($holdDays))) {
            $cycleMonth = $cycleMonth->subMonth();
        }

        $totals = ['settled_entries' => 0, 'created_payouts' => 0];

        PublisherAccount::query()->where('status', 'active')
            ->chunkById(100, function (Collection $publishers) use ($now, $cycleMonth, &$totals): void {
                foreach ($publishers as $publisher) {
                    $result = $this->preparePublisher($publisher->id, $cycleMonth, $now);
                    $totals['settled_entries'] += $result['settled_entries'];
                    $totals['created_payouts'] += $result['created_payouts'];
                }
            });

        return $totals;
    }

    /**
     * @return array{settled_entries: int, created_payouts: int}
     */
    private function preparePublisher(int $publisherId, CarbonImmutable $cycleMonth, CarbonImmutable $now): array
    {
        return DB::transaction(function () use ($publisherId, $cycleMonth, $now): array {
            $publisher = PublisherAccount::query()->lockForUpdate()->find($publisherId);

            if (! $publisher || $publisher->status !== 'active') {
                return ['settled_entries' => 0, 'created_payouts' => 0];
            }

            $settled = PublisherLedgerEntry::query()
                ->where('publisher_account_id', $publisher->id)
                ->where('currency', $publisher->currency)
                ->where('type', 'pending_earning')
                ->where('created_at', '<', $cycleMonth)
                ->update(['type' => 'payable', 'settled_at' => $now]);

            $result = ['settled_entries' => $settled, 'created_payouts' => 0];

            if (Payout::query()->where('publisher_account_id', $publisher->id)
                ->where('currency', $publisher->currency)
                ->where('cycle_month', $cycleMonth->toDateString())->exists()) {
                return $result;
            }

            $available = $this->balances->availableMicros($publisher);
            $minimum = max(0, (int) config('publisher.minimum_payout_micros', 50000000));

            if ($available <= 0 || $available < $minimum) {
                return $result;
            }

            Payout::query()->create([
                'publisher_account_id' => $publisher->id,
                'currency' => $publisher->currency,
                'status' => 'pending',
                'amount_micros' => $available,
                'provider' => 'stripe',
                'cycle_month' => $cycleMonth->toDateString(),
                'period_start' => $cycleMonth->subMonth(),
                'period_end' => $cycleMonth->subSecond(),
            ]);

            $result['created_payouts'] = 1;

            return $result;
        }, attempts: 3);
    }
}
