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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable()->default(null)->change();
        });

        DB::table('users')->where('role', 'advertiser')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('advertiser_accounts')->whereColumn('advertiser_accounts.user_id', 'users.id');
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('publisher_accounts')->whereColumn('publisher_accounts.user_id', 'users.id');
            })
            ->update(['role' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')->whereNull('role')->update(['role' => 'advertiser']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->nullable(false)->default('advertiser')->change();
        });
    }
};
