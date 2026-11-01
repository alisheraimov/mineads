<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\PublisherAccount;
use App\Models\PublisherLedgerEntry;
use Illuminate\Support\Facades\DB;

class PayoutReservationService
{
    /**
     * Caller holds the publisher account lock. Existing commitments without allocation
     * records consume the oldest earnings first; their original records are preserved.
     */
    public function reserve(Payout $payout, PublisherAccount $publisher): void
    {
        $entries = PublisherLedgerEntry::query()->where('publisher_account_id', $publisher->id)
            ->where('type', 'payable')
            ->where('amount_micros', '>', 0)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
        $used = DB::table('payout_earnings as allocation')->join('payouts', 'payouts.id', '=', 'allocation.payout_id')
            ->where('payouts.publisher_account_id', $publisher->id)
            ->where('payouts.id', '!=', $payout->id)->whereIn('payouts.status', ['pending', 'processing', 'paid'])
            ->selectRaw('publisher_ledger_entry_id, SUM(allocation.amount_micros) as amount')->groupBy('publisher_ledger_entry_id')->pluck('amount', 'publisher_ledger_entry_id');
        $legacy = (int) Payout::query()->where('publisher_account_id', $publisher->id)
            ->where('id', '!=', $payout->id)->whereIn('status', ['pending', 'processing', 'paid'])
            ->whereNotIn('id', DB::table('payout_earnings')->select('payout_id'))->sum('amount_micros');
        DB::table('payout_earnings')->where('payout_id', $payout->id)->delete();
        $remaining = (int) $payout->amount_micros;
        $first = null;
        $last = null;
        foreach ($entries as $entry) {
            $free = max(0, (int) $entry->amount_micros - (int) ($used[$entry->id] ?? 0));
            $skip = min($legacy, $free);
            $legacy -= $skip;
            $free -= $skip;
            $amount = min($remaining, $free);
            if ($amount <= 0) {
                continue;
            }
            DB::table('payout_earnings')->insert(['payout_id' => $payout->id, 'publisher_ledger_entry_id' => $entry->id, 'amount_micros' => $amount]);
            $first ??= $entry->created_at;
            $last = $entry->created_at;
            $remaining -= $amount;
            if ($remaining === 0) {
                break;
            }
        }
        abort_if($remaining > 0, 409, 'Released earnings no longer cover this payout. Refresh balances.');
        $payout->update(['period_start' => $first->copy()->startOfMonth(), 'period_end' => $last->copy()->endOfMonth()]);
    }
}
