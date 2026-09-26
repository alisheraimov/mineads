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
        Schema::create('rate_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('cpm_micros');
            $table->boolean('is_default')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('rate_tier_countries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_tier_id')->constrained()->cascadeOnDelete();
            $table->string('country_code', 2)->unique();
            $table->timestamps();

            $table->index(['rate_tier_id', 'country_code']);
        });

        Schema::table('ad_deliveries', function (Blueprint $table) {
            $table->foreignId('rate_tier_id')->nullable()->after('creative_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('cpm_micros')->nullable()->after('rate_tier_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ad_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_tier_id');
            $table->dropColumn('cpm_micros');
        });

        Schema::dropIfExists('rate_tier_countries');
        Schema::dropIfExists('rate_tiers');
    }
};
