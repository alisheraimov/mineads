<?php

namespace App\Services;

use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Campaign;
use Carbon\CarbonInterface;

class CampaignBudgetService
{
    public function canServe(Campaign $campaign, int $cpmMicros, int $expectedImpressions): bool
    {
        $expectedCostMicros = $this->billableMicros($cpmMicros) * $expectedImpressions;

        return $expectedCostMicros > 0
            && $this->hasBudgetFor($campaign, $expectedCostMicros, now());
    }

    public function canBill(Campaign $campaign, int $billableMicros, CarbonInterface $billableAt, bool $lockAccount = false): bool
    {
        if ($lockAccount) {
            AdvertiserAccount::query()
                ->whereKey($campaign->advertiser_account_id)
                ->lockForUpdate()
                ->first();
        }

        return $billableMicros > 0
            && $this->hasBudgetFor($campaign, $billableMicros, $billableAt);
    }

    public function billableMicros(int $cpmMicros): int
    {
        return intdiv($cpmMicros, 1000);
    }

    private function hasBudgetFor(Campaign $campaign, int $amountMicros, CarbonInterface $billableAt): bool
    {
        if ($this->advertiserBalanceMicros($campaign->advertiser_account_id) < $amountMicros) {
            return false;
        }

        if ($campaign->total_budget_micros !== null && ($this->campaignSpendMicros($campaign) + $amountMicros) > $campaign->total_budget_micros) {
            return false;
        }

        if ($campaign->daily_budget_micros !== null && ($this->campaignSpendMicros($campaign, $billableAt) + $amountMicros) > $campaign->daily_budget_micros) {
            return false;
        }

        return true;
    }

    private function advertiserBalanceMicros(int $advertiserAccountId): int
    {
        return (int) AdvertiserLedgerEntry::query()
            ->where('advertiser_account_id', $advertiserAccountId)
            ->sum('amount_micros');
    }

    private function campaignSpendMicros(Campaign $campaign, ?CarbonInterface $day = null): int
    {
        $query = AdvertiserLedgerEntry::query()
            ->where('campaign_id', $campaign->id)
            ->where('type', 'spend');

        if ($day) {
            $query->whereBetween('created_at', [
                $day->copy()->startOfDay(),
                $day->copy()->endOfDay(),
            ]);
        }

        return abs((int) $query->sum('amount_micros'));
    }
}
