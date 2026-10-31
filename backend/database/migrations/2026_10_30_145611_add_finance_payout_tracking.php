<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->string('payment_method', 50)->nullable();
            $table->string('external_reference')->nullable();
            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unique(['payment_method', 'external_reference'], 'payout_payment_reference_unique');
        });
        Schema::create('payout_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('action');
            $table->json('details');
            $table->timestamps();
            $table->index(['payout_id', 'id']);
        });
        Schema::create('payout_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained()->restrictOnDelete();
            $table->foreignId('publisher_ledger_entry_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_micros');
            $table->unique(['payout_id', 'publisher_ledger_entry_id']);
        });
        foreach (DB::table('payouts')->where('status', 'processing')->orderBy('id')->cursor() as $payout) {
            DB::table('payout_events')->insert([
                'payout_id' => $payout->id, 'actor_name' => 'System', 'action' => 'migrated',
                'details' => json_encode(['from' => 'processing', 'to' => 'pending', 'reason' => 'Processing is represented as Pending in manual Finance MVP.']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('payouts')->where('status', 'processing')->update(['status' => 'pending']);
        Schema::table('payouts', fn (Blueprint $table) => $table->index(['currency', 'status', 'paid_at'], 'finance_payout_paid_index'));
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_earnings');
        Schema::dropIfExists('payout_events');
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropIndex('finance_payout_paid_index');
            $table->dropUnique('payout_payment_reference_unique');
            $table->dropColumn(['payment_method', 'external_reference', 'reason', 'internal_note', 'version']);
        });
    }
};
