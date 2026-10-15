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
        Schema::table('ad_creatives', function (Blueprint $table) {
            $table->string('name')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('is_paused')->default(false);
            $table->softDeletes();
        });
        Schema::table('validated_impressions', function (Blueprint $table) {
            $table->index(['creative_id', 'validated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('validated_impressions', function (Blueprint $table) {
            $table->dropIndex(['creative_id', 'validated_at']);
        });
        Schema::table('ad_creatives', function (Blueprint $table) {
            $table->dropColumn(['name', 'rejection_reason', 'is_paused', 'deleted_at']);
        });
    }
};
