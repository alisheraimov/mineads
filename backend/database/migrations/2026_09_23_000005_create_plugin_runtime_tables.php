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
        Schema::create('server_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('plugin_version')->nullable();
            $table->string('minecraft_version')->nullable();
            $table->string('platform')->nullable();
            $table->unsignedInteger('online_players')->default(0);
            $table->unsignedInteger('max_players')->nullable();
            $table->decimal('tps', 5, 2)->nullable();
            $table->timestamp('received_at')->useCurrent()->index();

            $table->index(['server_id', 'received_at']);
        });

        Schema::create('player_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('player_hash', 128);
            $table->string('session_token', 128)->unique();
            $table->string('country_code', 2)->nullable()->index();
            $table->string('language', 16)->nullable()->index();
            $table->timestamp('joined_at')->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->index(['server_id', 'player_hash']);
            $table->index(['server_id', 'country_code', 'language']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_sessions');
        Schema::dropIfExists('server_heartbeats');
    }
};
