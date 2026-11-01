<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['advertiser_accounts', 'publisher_accounts', 'advertiser_ledger_entries', 'publisher_ledger_entries', 'payouts'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->where('currency', '!=', 'USD')->orWhereNull('currency')->exists()) {
                throw new RuntimeException("Cannot remove currency from {$table}: non-USD records require reconciliation first.");
            }
        }

        Schema::table('publisher_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('publisher_ledger_settlement_index');
        });
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropUnique('publisher_payout_cycle_unique');
            $table->dropIndex('finance_payout_paid_index');
        });
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
        Schema::table('publisher_ledger_entries', function (Blueprint $table) {
            $table->index(['publisher_account_id', 'type', 'created_at'], 'publisher_ledger_settlement_index');
        });
        Schema::table('payouts', function (Blueprint $table) {
            $table->unique(['publisher_account_id', 'cycle_month'], 'publisher_payout_cycle_unique');
            $table->index(['status', 'paid_at'], 'finance_payout_paid_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('currency', 3)->default('USD');
            });
        }
        Schema::table('publisher_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('publisher_ledger_settlement_index');
            $table->index(['publisher_account_id', 'currency', 'type', 'created_at'], 'publisher_ledger_settlement_index');
        });
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropUnique('publisher_payout_cycle_unique');
            $table->dropIndex('finance_payout_paid_index');
            $table->unique(['publisher_account_id', 'currency', 'cycle_month'], 'publisher_payout_cycle_unique');
            $table->index(['currency', 'status', 'paid_at'], 'finance_payout_paid_index');
        });
    }
};
