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
        Schema::table('campaigns', function (Blueprint $table) {
            $table->index(['advertiser_account_id', 'id'], 'campaigns_advertiser_list_index');
        });
        Schema::table('advertiser_ledger_entries', function (Blueprint $table) {
            $table->index(['campaign_id', 'type', 'created_at'], 'ledger_campaign_spend_index');
        });
        Schema::table('validated_impressions', function (Blueprint $table) {
            $table->index(['campaign_id', 'creative_id'], 'impressions_campaign_creative_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('validated_impressions', fn (Blueprint $table) => $table->dropIndex('impressions_campaign_creative_index'));
        Schema::table('advertiser_ledger_entries', fn (Blueprint $table) => $table->dropIndex('ledger_campaign_spend_index'));
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropIndex('campaigns_advertiser_list_index'));
    }
};
