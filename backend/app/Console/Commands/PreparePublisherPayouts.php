<?php

namespace App\Console\Commands;

use App\Services\PublisherPayoutService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('publishers:prepare-payouts')]
#[Description('Settle eligible publisher earnings and reserve monthly payouts above the minimum')]
class PreparePublisherPayouts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PublisherPayoutService $payouts): int
    {
        $result = $payouts->prepare();

        $this->info("Settled {$result['settled_entries']} ledger entries; created {$result['created_payouts']} pending payouts.");

        return self::SUCCESS;
    }
}
