<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('campaign_targets', function (Blueprint $table) {
            $table->dropIndex(['country_code', 'language']);
            $table->dropIndex(['campaign_id', 'country_code', 'language']);
            $table->dropColumn('language');
            $table->index('country_code');
            $table->index(['campaign_id', 'country_code']);
        });

        $duplicates = DB::table('campaign_targets')->select(['campaign_id', 'country_code', 'server_category'])
            ->selectRaw('MIN(id) as retained_id')->groupBy('campaign_id', 'country_code', 'server_category')
            ->havingRaw('COUNT(*) > 1')->get();
        foreach ($duplicates as $target) {
            DB::table('campaign_targets')->where('campaign_id', $target->campaign_id)
                ->where('country_code', $target->country_code)->where('server_category', $target->server_category)
                ->where('id', '!=', $target->retained_id)->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaign_targets', function (Blueprint $table) {
            $table->dropIndex(['country_code']);
            $table->dropIndex(['campaign_id', 'country_code']);
            $table->string('language', 16)->nullable();
            $table->index(['country_code', 'language']);
            $table->index(['campaign_id', 'country_code', 'language']);
        });
    }
};
