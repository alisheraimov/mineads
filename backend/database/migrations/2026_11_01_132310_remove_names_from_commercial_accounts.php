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
        foreach (['advertiser_accounts', 'publisher_accounts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['advertiser_accounts', 'publisher_accounts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('name')->default('');
            });
            DB::table($name)->update(['name' => DB::raw("(SELECT users.name FROM users WHERE users.id = {$name}.user_id)")]);
        }
    }
};
