<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advertiser_ledger_entries', function (Blueprint $table) {
            $table->index(['advertiser_account_id', 'type', 'created_at'], 'advertiser_ledger_account_type_created_index');
        });

        Schema::table('validated_impressions', function (Blueprint $table) {
            $table->index(['advertiser_account_id', 'campaign_id', 'validated_at'], 'validated_impressions_account_campaign_validated_index');
        });
    }

    public function down(): void
    {
        Schema::table('validated_impressions', function (Blueprint $table) {
            $table->dropIndex('validated_impressions_account_campaign_validated_index');
        });

        Schema::table('advertiser_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('advertiser_ledger_account_type_created_index');
        });
    }
};
