<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\PublisherAccount;
use App\Models\PublisherLedgerEntry;
use App\Models\User;
use App\Services\PublisherBalanceService;
use Carbon\CarbonImmutable;
use Database\Seeders\AdminPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminPayoutActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));
    }

    private function admin(string $role = 'finance'): User
    {
        $this->seed(AdminPermissionSeeder::class);
        $user = User::factory()->create(['role' => 'admin']);
        $user->assignRole($role);

        return $user;
    }

    private function payout(string $status = 'pending'): Payout
    {
        $publisher = PublisherAccount::factory()->create();
        PublisherLedgerEntry::factory()->create(['publisher_account_id' => $publisher->id, 'server_id' => null, 'type' => 'payable', 'amount_micros' => 100000000, 'created_at' => '2026-09-10']);

        return Payout::factory()->create(['publisher_account_id' => $publisher->id, 'status' => $status, 'created_at' => '2026-10-05 00:10', 'amount_micros' => 60000000]);
    }

    private function url(Payout $payout): string
    {
        return '/api/admin/finance/payouts/'.$payout->id.'/actions';
    }

    public function test_marking_paid_records_evidence_audit_and_keeps_funds_committed(): void
    {
        $p = $this->payout();
        $actor = $this->admin();
        $this->actingAs($actor, 'sanctum')->postJson($this->url($p), ['version' => 1, 'action' => 'paid', 'payment_method' => 'bank_transfer', 'external_reference' => 'BANK-123', 'paid_at' => '2026-10-29T10:20:00Z', 'amount_micros' => 1, 'status' => 'cancelled'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.version', 2)->assertJsonPath('data.amount_micros', 60000000)->assertJsonPath('data.paid_at', '2026-10-29T10:20:00.000000Z');
        $this->assertDatabaseHas('payouts', ['id' => $p->id, 'payment_method' => 'bank_transfer', 'external_reference' => 'BANK-123', 'status' => 'paid']);
        $this->assertDatabaseHas('payout_events', ['payout_id' => $p->id, 'actor_id' => $actor->id, 'action' => 'paid']);
        $this->assertSame(40000000, app(PublisherBalanceService::class)->rawAvailableMicros($p->publisherAccount));
        $this->postJson($this->url($p), ['version' => 2, 'action' => 'cancelled', 'reason' => 'Mistaken action'])->assertConflict();
        $this->assertDatabaseCount('payout_events', 1);
    }

    #[TestWith(['failed'])]
    #[TestWith(['cancelled'])]
    public function test_failure_and_cancellation_release_reservation_and_require_a_reason(string $action): void
    {
        $p = $this->payout();
        $this->actingAs($this->admin(), 'sanctum')->postJson($this->url($p), ['version' => 1, 'action' => $action])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($this->url($p), ['version' => 1, 'action' => $action, 'reason' => 'Transfer did not complete'])->assertOk()->assertJsonPath('data.status', $action)->assertJsonPath('data.balances.available_micros', 100000000);
        $this->assertDatabaseHas('payouts', ['id' => $p->id, 'reason' => 'Transfer did not complete']);
        $this->assertDatabaseHas('payout_events', ['action' => $action, 'payout_id' => $p->id]);
    }

    public function test_retry_reuses_record_rechecks_balance_and_preserves_attempt_history(): void
    {
        $p = $this->payout();
        $this->actingAs($this->admin(), 'sanctum')->postJson($this->url($p), ['version' => 1, 'action' => 'failed', 'reason' => 'Bank rejected'])->assertOk();
        $other = Payout::factory()->create(['publisher_account_id' => $p->publisher_account_id, 'amount_micros' => 80000000]);
        $this->postJson($this->url($p), ['version' => 2, 'action' => 'retry'])->assertConflict();
        $other->update(['status' => 'cancelled']);
        $this->postJson($this->url($p), ['version' => 2, 'action' => 'retry'])->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.version', 3)->assertJsonPath('data.balances.available_micros', 40000000);
        $this->assertDatabaseCount('payouts', 2);
        $this->assertDatabaseCount('payout_events', 2);
        $this->assertDatabaseHas('payout_earnings', ['payout_id' => $p->id, 'amount_micros' => 60000000]);
        $this->assertSame('Bank rejected', PayoutEvent::where('payout_id', $p->id)->oldest('id')->first()->details['reason']);
    }

    public function test_stale_and_duplicate_submissions_cannot_record_two_outcomes(): void
    {
        $p = $this->payout();
        $payload = ['version' => 1, 'action' => 'failed', 'reason' => 'Known failure'];
        $this->actingAs($this->admin(), 'sanctum')->postJson($this->url($p), $payload)->assertOk();
        $this->postJson($this->url($p), $payload)->assertConflict();
        $this->assertDatabaseCount('payout_events', 1);
    }

    #[TestWith(['user_suspended'])]
    #[TestWith(['user_deleted'])]
    #[TestWith(['account_suspended'])]
    public function test_restricted_publishers_keep_history_and_block_payment_actions(string $restriction): void
    {
        $p = $this->payout();
        if ($restriction === 'user_deleted') {
            $p->publisherAccount->user->delete();
        } elseif ($restriction === 'user_suspended') {
            $p->publisherAccount->user->update(['status' => 'suspended']);
        } else {
            $p->publisherAccount->update(['status' => 'suspended']);
        }
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/admin/finance/payouts/'.$p->id)->assertOk()->assertJsonPath('data.can_manage', false);
        $this->postJson($this->url($p), ['version' => 1, 'action' => 'failed', 'reason' => 'Blocked'])->assertConflict();
        $this->assertDatabaseHas('payouts', ['id' => $p->id, 'status' => 'pending']);
        $this->assertDatabaseCount('payout_events', 0);
    }

    public function test_view_only_finance_permission_cannot_record_outcomes(): void
    {
        $p = $this->payout();
        $u = $this->admin();
        $u->syncRoles([]);
        $u->givePermissionTo('admin.finance.view');
        $this->actingAs($u, 'sanctum')->getJson('/api/admin/finance/payouts/'.$p->id)->assertOk()->assertJsonPath('data.can_manage', false);
        $this->postJson($this->url($p), ['version' => 1, 'action' => 'failed', 'reason' => 'Forbidden'])->assertForbidden();
    }

    public function test_payment_evidence_is_required_future_and_precreation_dates_are_rejected(): void
    {
        $p = $this->payout();
        $this->actingAs($this->admin(), 'sanctum')->postJson($this->url($p), ['version' => 1, 'action' => 'paid'])->assertUnprocessable()->assertJsonValidationErrors(['payment_method', 'external_reference', 'paid_at']);
        foreach (['2026-11-01T00:00:00Z', '2026-10-01T00:00:00Z'] as $date) {
            $this->postJson($this->url($p), ['version' => 1, 'action' => 'paid', 'payment_method' => 'bank_transfer', 'external_reference' => 'REF', 'paid_at' => $date])->assertUnprocessable()->assertJsonValidationErrors('paid_at');
        }
        $this->assertDatabaseCount('payout_events', 0);
    }

    public function test_duplicate_payment_reference_is_rejected_without_a_second_payment(): void
    {
        $paid = $this->payout('paid');
        $paid->update(['payment_method' => 'bank_transfer', 'external_reference' => 'BANK-UNIQUE']);
        $p = $this->payout();
        $this->actingAs($this->admin(), 'sanctum')->postJson($this->url($p), ['version' => 1, 'action' => 'paid', 'payment_method' => 'bank_transfer', 'external_reference' => 'BANK-UNIQUE', 'paid_at' => '2026-10-29T00:00:00Z'])->assertUnprocessable()->assertJsonValidationErrors('external_reference');
        $this->assertSame('pending', $p->fresh()->status);
    }

    public function test_preparation_pays_whole_cents_and_preserves_fractional_remainder(): void
    {
        $publisher = PublisherAccount::factory()->create();
        PublisherLedgerEntry::factory()->create(['publisher_account_id' => $publisher->id, 'server_id' => null, 'type' => 'pending_earning', 'amount_micros' => 50001234, 'created_at' => '2026-09-30']);
        $this->artisan('publishers:prepare-payouts')->assertSuccessful();
        $this->assertDatabaseHas('payouts', ['publisher_account_id' => $publisher->id, 'amount_micros' => 50000000, 'provider' => 'manual']);
        $this->assertSame(1234, app(PublisherBalanceService::class)->rawAvailableMicros($publisher));
        $this->assertDatabaseHas('payout_events', ['action' => 'created', 'actor_name' => 'System']);
    }

    public function test_deleted_publishers_do_not_get_new_automatic_payouts(): void
    {
        $p = PublisherAccount::factory()->create();
        PublisherLedgerEntry::factory()->create(['publisher_account_id' => $p->id, 'type' => 'pending_earning', 'amount_micros' => 60000000, 'created_at' => '2026-09-01']);
        $p->user->delete();
        $this->artisan('publishers:prepare-payouts')->assertSuccessful();
        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_retry_checks_the_current_minimum_before_reserving(): void
    {
        $p = $this->payout('failed');
        config(['publisher.minimum_payout_micros' => 70000000]);
        $this->actingAs($this->admin(), 'sanctum')->postJson($this->url($p), ['version' => 1, 'action' => 'retry'])->assertConflict();
        $this->assertSame('failed', $p->fresh()->status);
        $this->assertDatabaseCount('payout_events', 0);
    }

    public function test_new_reservation_covers_carried_earnings_after_legacy_paid_commitments(): void
    {
        $publisher = PublisherAccount::factory()->create();
        $august = PublisherLedgerEntry::factory()->create(['publisher_account_id' => $publisher->id, 'type' => 'payable', 'amount_micros' => 30000000, 'created_at' => '2026-08-10']);
        $september = PublisherLedgerEntry::factory()->create(['publisher_account_id' => $publisher->id, 'type' => 'payable', 'amount_micros' => 40000000, 'created_at' => '2026-09-10']);
        Payout::factory()->create(['publisher_account_id' => $publisher->id, 'status' => 'paid', 'amount_micros' => 20000000]);
        $this->artisan('publishers:prepare-payouts')->assertSuccessful();
        $pending = Payout::where('status', 'pending')->sole();
        $this->assertSame(50000000, $pending->amount_micros);
        $this->assertSame('2026-08-01', $pending->period_start->toDateString());
        $this->assertSame('2026-09-30', $pending->period_end->toDateString());
        $this->assertDatabaseHas('payout_earnings', ['payout_id' => $pending->id, 'publisher_ledger_entry_id' => $august->id, 'amount_micros' => 10000000]);
        $this->assertDatabaseHas('payout_earnings', ['payout_id' => $pending->id, 'publisher_ledger_entry_id' => $september->id, 'amount_micros' => 40000000]);
    }
}
