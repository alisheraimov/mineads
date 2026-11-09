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
        Schema::table('ad_deliveries', function (Blueprint $table) {
            $table->string('click_token_hash', 64)->nullable()->unique();
            $table->text('click_destination')->nullable();
            $table->timestamp('click_expires_at')->nullable();
        });
        Schema::create('raw_click_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_delivery_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_hash', 64);
            $table->boolean('is_unique');
            $table->string('exclusion_reason')->nullable();
            $table->timestamp('received_at')->index();
            $table->index(['ad_delivery_id', 'visitor_hash', 'is_unique'], 'click_visitor_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('raw_click_events');
        Schema::table('ad_deliveries', function (Blueprint $table) {
            $table->dropUnique(['click_token_hash']);
            $table->dropColumn(['click_token_hash', 'click_destination', 'click_expires_at']);
        });
    }
};
