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
        if (! Schema::hasColumn('campaigns', 'cpm_micros')) {
            return;
        }

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('cpm_micros');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('campaigns', 'cpm_micros')) {
            return;
        }

        Schema::table('campaigns', function (Blueprint $table) {
            $table->unsignedBigInteger('cpm_micros')->nullable()->after('pricing_model');
        });
    }
};
