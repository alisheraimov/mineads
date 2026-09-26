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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertiser_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('draft')->index();
            $table->string('pricing_model')->default('cpm');
            $table->unsignedBigInteger('daily_budget_micros')->nullable();
            $table->unsignedBigInteger('total_budget_micros')->nullable();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->timestamps();

            $table->index(['advertiser_account_id', 'status']);
        });

        Schema::create('ad_creatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('chat');
            $table->string('language', 16)->nullable()->index();
            $table->text('message_text');
            $table->string('click_url')->nullable();
            $table->string('status')->default('pending_review')->index();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });

        Schema::create('campaign_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('country_code', 2)->nullable();
            $table->string('language', 16)->nullable();
            $table->string('server_category')->nullable();
            $table->timestamps();

            $table->index(['country_code', 'language']);
            $table->index(['campaign_id', 'country_code', 'language']);
            $table->index(['campaign_id', 'server_category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_targets');
        Schema::dropIfExists('ad_creatives');
        Schema::dropIfExists('campaigns');
    }
};
