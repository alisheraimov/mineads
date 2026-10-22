<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('publisher_ledger_entries', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable();
            $table->index(['publisher_account_id', 'currency', 'type', 'created_at'], 'publisher_ledger_settlement_index');
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->date('cycle_month')->nullable();
            $table->unique(['publisher_account_id', 'currency', 'cycle_month'], 'publisher_payout_cycle_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropUnique('publisher_payout_cycle_unique');
            $table->dropColumn('cycle_month');
        });

        Schema::table('publisher_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('publisher_ledger_settlement_index');
            $table->dropColumn('settled_at');
        });
    }
};
