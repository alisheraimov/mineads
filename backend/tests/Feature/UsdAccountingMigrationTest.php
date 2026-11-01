<?php

namespace Tests\Feature;

use App\Models\AdvertiserAccount;
use App\Models\AdvertiserLedgerEntry;
use App\Models\Payout;
use App\Models\PublisherAccount;
use App\Models\PublisherLedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UsdAccountingMigrationTest extends TestCase
{
    use RefreshDatabase;

    public static function currencyTables(): array
    {
        return [
            [AdvertiserAccount::class, 'advertiser_accounts'],
            [PublisherAccount::class, 'publisher_accounts'],
            [AdvertiserLedgerEntry::class, 'advertiser_ledger_entries'],
            [PublisherLedgerEntry::class, 'publisher_ledger_entries'],
            [Payout::class, 'payouts'],
        ];
    }

    #[DataProvider('currencyTables')]
    public function test_migration_refuses_non_usd_data_before_changing_any_table(string $model, string $table): void
    {
        $migration = require database_path('migrations/2026_11_01_131136_remove_currency_columns_for_usd_accounting.php');
        $migration->down();
        $record = $model::factory()->create(['currency' => 'EUR']);

        try {
            $migration->up();
            $this->fail('Non-USD data must require reconciliation before migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($table, $exception->getMessage());
            foreach (self::currencyTables() as [, $name]) {
                $this->assertTrue(Schema::hasColumn($name, 'currency'));
            }
            $this->assertDatabaseHas($table, ['id' => $record->id, 'currency' => 'EUR']);
        } finally {
            DB::table($table)->where('id', $record->id)->update(['currency' => 'USD']);
            $migration->up();
        }
    }

    public function test_migration_preserves_usd_amounts_and_can_restore_the_original_schema(): void
    {
        $entry = PublisherLedgerEntry::factory()->create(['type' => 'payable', 'amount_micros' => 123456789]);
        $payout = Payout::factory()->create(['publisher_account_id' => $entry->publisher_account_id, 'amount_micros' => 50000000, 'cycle_month' => '2026-10-01']);
        $migration = require database_path('migrations/2026_11_01_131136_remove_currency_columns_for_usd_accounting.php');
        $migration->down();

        $this->assertDatabaseHas('publisher_ledger_entries', ['id' => $entry->id, 'currency' => 'USD', 'amount_micros' => 123456789]);
        $this->assertDatabaseHas('payouts', ['id' => $payout->id, 'currency' => 'USD', 'amount_micros' => 50000000]);

        $migration->up();

        foreach (self::currencyTables() as [, $table]) {
            $this->assertFalse(Schema::hasColumn($table, 'currency'));
        }
        $this->assertDatabaseHas('publisher_ledger_entries', ['id' => $entry->id, 'amount_micros' => 123456789]);
        $this->assertDatabaseHas('payouts', ['id' => $payout->id, 'amount_micros' => 50000000]);
    }

    public function test_payout_cycle_is_unique_per_publisher(): void
    {
        $publisher = PublisherAccount::factory()->create();
        Payout::factory()->create(['publisher_account_id' => $publisher->id, 'cycle_month' => '2026-10-01']);
        Payout::factory()->create(['publisher_account_id' => $publisher->id, 'cycle_month' => '2026-11-01']);
        Payout::factory()->create(['cycle_month' => '2026-10-01']);

        $this->expectException(QueryException::class);
        Payout::factory()->create(['publisher_account_id' => $publisher->id, 'cycle_month' => '2026-10-01']);
    }
}
