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
        Schema::create('advertiser_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advertiser_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->bigInteger('amount_micros');
            $table->string('currency', 3)->default('USD');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();

            $table->index(['advertiser_account_id', 'created_at']);
            $table->index(['campaign_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('publisher_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publisher_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->bigInteger('amount_micros');
            $table->string('currency', 3)->default('USD');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();

            $table->index(['publisher_account_id', 'created_at']);
            $table->index(['server_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publisher_account_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending')->index();
            $table->unsignedBigInteger('amount_micros');
            $table->string('currency', 3)->default('USD');
            $table->string('provider')->default('stripe');
            $table->string('provider_payout_id')->nullable()->unique();
            $table->timestamp('period_start')->index();
            $table->timestamp('period_end')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['publisher_account_id', 'status']);
            $table->index(['publisher_account_id', 'period_start', 'period_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('publisher_ledger_entries');
        Schema::dropIfExists('advertiser_ledger_entries');
    }
};
