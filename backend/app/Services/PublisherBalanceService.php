<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\PublisherAccount;
use App\Models\PublisherLedgerEntry;

class PublisherBalanceService
{
    public function availableMicros(PublisherAccount $publisher): int
    {
        return max(0, $this->rawAvailableMicros($publisher));
    }

    public function rawAvailableMicros(PublisherAccount $publisher): int
    {
        $payable = (int) PublisherLedgerEntry::query()
            ->where('publisher_account_id', $publisher->id)

            ->where('type', 'payable')->sum('amount_micros');
        $committed = (int) Payout::query()
            ->where('publisher_account_id', $publisher->id)

            ->whereIn('status', ['pending', 'processing', 'paid'])->sum('amount_micros');

        return $payable - $committed;
    }
}
