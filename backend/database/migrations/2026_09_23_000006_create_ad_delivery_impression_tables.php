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
        Schema::create('ad_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creative_id')->nullable()->constrained('ad_creatives')->nullOnDelete();
            $table->string('placement')->default('chat');
            $table->string('audience_country', 2)->nullable();
            $table->string('audience_language', 16)->nullable();
            $table->unsignedInteger('requested_player_count')->default(0);
            $table->string('decision')->index();
            $table->string('impression_token_hash')->nullable()->unique();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('requested_at')->useCurrent()->index();

            $table->index(['server_id', 'requested_at']);
            $table->index(['campaign_id', 'requested_at']);
            $table->index(['audience_country', 'audience_language']);
        });

        Schema::create('raw_impression_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('player_hash', 128);
            $table->timestamp('displayed_at')->index();
            $table->timestamp('received_at')->useCurrent()->index();
            $table->string('validation_status')->default('pending')->index();
            $table->string('invalid_reason')->nullable();

            $table->unique(['ad_delivery_id', 'player_hash']);
            $table->index(['server_id', 'received_at']);
            $table->index(['player_hash', 'displayed_at']);
        });

        Schema::create('validated_impressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('raw_impression_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('advertiser_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('publisher_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creative_id')->constrained('ad_creatives')->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('player_hash', 128);
            $table->unsignedBigInteger('billable_micros');
            $table->unsignedBigInteger('publisher_earning_micros');
            $table->unsignedBigInteger('platform_fee_micros');
            $table->timestamp('validated_at')->useCurrent()->index();

            $table->index(['advertiser_account_id', 'validated_at']);
            $table->index(['publisher_account_id', 'validated_at']);
            $table->index(['campaign_id', 'validated_at']);
            $table->index(['server_id', 'validated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('validated_impressions');
        Schema::dropIfExists('raw_impression_events');
        Schema::dropIfExists('ad_deliveries');
    }
};
