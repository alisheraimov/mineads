<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\PublisherAccount;
use App\Models\PublisherLedgerEntry;

class PublisherBalanceService
{
    public function availableMicros(PublisherAccount $publisher): int
    {
        $payable = (int) PublisherLedgerEntry::query()
            ->where('publisher_account_id', $publisher->id)
            ->where('currency', $publisher->currency)
            ->where('type', 'payable')->sum('amount_micros');
        $committed = (int) Payout::query()
            ->where('publisher_account_id', $publisher->id)
            ->where('currency', $publisher->currency)
            ->whereIn('status', ['pending', 'processing', 'paid'])->sum('amount_micros');

        return max(0, $payable - $committed);
    }
}
