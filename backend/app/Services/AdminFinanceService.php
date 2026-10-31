<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\User;
use App\Models\ValidatedImpression;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AdminFinanceService
{
    public function __construct(private readonly PublisherBalanceService $balances) {}

    public function range(array $input): array
    {
        $now = CarbonImmutable::now('UTC');
        $period = $input['period'] ?? 'month';
        $from = match ($period) {
            'today' => $now->startOfDay(), 'yesterday' => $now->subDay()->startOfDay(),
            'last_week' => $now->startOfWeek()->subWeek(), 'last_month' => $now->startOfMonth()->subMonth(),
            'custom' => CarbonImmutable::parse($input['from'], 'UTC'), default => $now->startOfMonth(),
        };
        $to = match ($period) {
            'yesterday' => $now->startOfDay(), 'last_week' => $now->startOfWeek(), 'last_month' => $now->startOfMonth(),
            'custom' => CarbonImmutable::parse($input['to'], 'UTC')->addDay()->min($now), default => $now,
        };
        abort_if($from->greaterThan($to) || $from->diffInDays($to) > 366, 422, 'Choose a valid range of 366 days or less.');

        return [$from, $to];
    }

    public function overview(array $input): array
    {
        [$from, $to] = $this->range($input);
        $q = ValidatedImpression::query()->where('validated_at', '>=', $from)->where('validated_at', '<', $to);
        $totals = (clone $q)->selectRaw('COUNT(*) as impressions, COALESCE(SUM(billable_micros),0) as spend, COALESCE(SUM(publisher_earning_micros),0) as earnings, COALESCE(SUM(platform_fee_micros),0) as revenue')->first();
        $workload = [];
        foreach (['pending', 'failed'] as $status) {
            $p = Payout::query()->where('currency', 'USD')->where('status', $status);
            $workload[] = ['status' => $status, 'count' => (clone $p)->count(), 'amount_micros' => (int) $p->sum('amount_micros')];
        }

        return [
            'metrics' => ['spend_micros' => (int) $totals->spend, 'earnings_micros' => (int) $totals->earnings, 'revenue_micros' => (int) $totals->revenue, 'impressions' => (int) $totals->impressions,
                'paid_micros' => (int) Payout::query()->where('currency', 'USD')->where('status', 'paid')->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->sum('amount_micros')],
            'balances' => $this->currentBalances(), 'workload' => $workload,
            'points' => $this->chart($from, $to, $input['interval'] ?? 'day', (bool) ($input['compare'] ?? false)),
            'period' => ['from' => $from->toISOString(), 'to' => $to->toISOString()], 'generated_at' => now('UTC')->toISOString(),
        ];
    }

    private function chart(CarbonImmutable $from, CarbonImmutable $to, string $interval, bool $compare): array
    {
        $seconds = max(1, $to->getTimestamp() - $from->getTimestamp());
        $previousFrom = $from->subSeconds($seconds);
        $buckets = [];
        for ($cursor = $from; $cursor->lt($to);) {
            $end = match ($interval) {
                'week' => $cursor->startOfWeek()->addWeek()->min($to),
                'month' => $cursor->startOfMonth()->addMonth()->min($to),
                default => $cursor->startOfDay()->addDay()->min($to),
            };
            $buckets[] = ['date' => $cursor->toISOString(), 'to' => $end->toISOString(),
                'previous_from' => $cursor->subSeconds($seconds)->toISOString(), 'previous_to' => $end->subSeconds($seconds)->toISOString(),
                'spend_micros' => 0, 'earnings_micros' => 0, 'revenue_micros' => 0, 'previous_spend_micros' => 0, 'previous_earnings_micros' => 0, 'previous_revenue_micros' => 0];
            $cursor = $end;
        }
        if ($buckets === []) {
            return [];
        }
        foreach ($compare ? ['', 'previous_'] : [''] as $prefix) {
            $case = 'CASE';
            $bindings = [];
            foreach ($buckets as $index => $bucket) {
                $case .= ' WHEN validated_at >= ? AND validated_at < ? THEN '.$index;
                $bindings[] = CarbonImmutable::parse($prefix ? $bucket['previous_from'] : $bucket['date']);
                $bindings[] = CarbonImmutable::parse($prefix ? $bucket['previous_to'] : $bucket['to']);
            }
            $case .= ' ELSE -1 END';
            $rows = ValidatedImpression::query()->where('validated_at', '>=', $prefix ? $previousFrom : $from)->where('validated_at', '<', $prefix ? $from : $to)
                ->selectRaw($case.' as bucket, SUM(billable_micros) as spend_micros, SUM(publisher_earning_micros) as earnings_micros, SUM(platform_fee_micros) as revenue_micros', $bindings)->groupBy('bucket')->get();
            foreach ($rows as $row) {
                if ((int) $row->bucket < 0) {
                    continue;
                }
                foreach (['spend_micros', 'earnings_micros', 'revenue_micros'] as $key) {
                    $buckets[(int) $row->bucket][$prefix.$key] = (int) $row->$key;
                }
            }
        }

        return $buckets;
    }

    public function currentBalances(): array
    {
        $released = DB::table('publisher_ledger_entries')->where('currency', 'USD')->where('type', 'payable')
            ->selectRaw('publisher_account_id, SUM(amount_micros) as amount')->groupBy('publisher_account_id');
        $committed = DB::table('payouts')->where('currency', 'USD')->whereIn('status', ['pending', 'processing', 'paid'])
            ->selectRaw('publisher_account_id, SUM(amount_micros) as amount')->groupBy('publisher_account_id');
        $available = DB::table('publisher_accounts as a')->leftJoinSub($released, 'released', 'released.publisher_account_id', '=', 'a.id')
            ->leftJoinSub($committed, 'committed', 'committed.publisher_account_id', '=', 'a.id')
            ->where('a.currency', 'USD')->selectRaw('a.id, a.user_id, COALESCE(released.amount,0) - COALESCE(committed.amount,0) as available');
        $sum = 0;
        $inconsistent = [];
        $inconsistentCount = 0;
        foreach ($available->cursor() as $a) {
            $amount = (int) $a->available;
            if ($amount < 0) {
                $inconsistentCount++;
                if (count($inconsistent) < 20) {
                    $inconsistent[] = ['user_id' => $a->user_id, 'available_micros' => $amount];
                }
            } else {
                $sum += $amount;
            }
        }

        return [
            'advertiser_funds_micros' => (int) DB::table('advertiser_ledger_entries')->where('currency', 'USD')->sum('amount_micros'),
            'awaiting_micros' => (int) DB::table('publisher_ledger_entries')->where('currency', 'USD')->where('type', 'pending_earning')->sum('amount_micros'),
            'held_micros' => (int) DB::table('publisher_ledger_entries as e')->join('servers as s', 's.id', '=', 'e.server_id')->where('e.currency', 'USD')->where('e.type', 'pending_earning')->where('s.status', 'suspended')->sum('e.amount_micros'),
            'available_micros' => $sum, 'reserved_micros' => (int) Payout::query()->where('currency', 'USD')->whereIn('status', ['pending', 'processing'])->sum('amount_micros'),
            'inconsistent_count' => $inconsistentCount, 'inconsistencies' => $inconsistent,
        ];
    }

    public function ledgerQuery(string $kind, array $input): Builder
    {
        $publisher = $kind === 'publisher';
        $accountTable = $publisher ? 'publisher_accounts' : 'advertiser_accounts';
        $accountKey = $publisher ? 'publisher_account_id' : 'advertiser_account_id';
        $related = $publisher ? 'servers' : 'campaigns';
        $relatedKey = $publisher ? 'server_id' : 'campaign_id';
        $q = DB::table($publisher ? 'publisher_ledger_entries as e' : 'advertiser_ledger_entries as e')
            ->join($accountTable.' as a', 'a.id', '=', 'e.'.$accountKey)->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin($related.' as r', 'r.id', '=', 'e.'.$relatedKey)->where('e.currency', 'USD');
        $this->dates($q, $input, 'e.created_at');
        if (! empty($input['account'])) {
            $q->where('u.id', $input['account']);
        }
        if (! empty($input['related'])) {
            $q->where('r.id', $input['related']);
        }
        if (! empty($input['type'])) {
            $q->where('e.type', $input['type']);
        }
        if ($publisher && ($input['held'] ?? false)) {
            $q->where('e.type', 'pending_earning')->where('r.status', 'suspended');
        }
        if (! empty($input['search'])) {
            $search = trim($input['search']);
            $id = preg_replace('/^(ALE|PLE)-/i', '', $search);
            $q->where(function (Builder $q) use ($search, $id): void {
                $q->where('e.id', ctype_digit($id) ? (int) $id : -1);
                if (preg_match('/^(?:EVT-|raw_impression_event:)(\d+)$/i', $search, $matches)) {
                    $q->orWhere(fn ($q) => $q->where('e.reference_type', 'raw_impression_event')->where('e.reference_id', $matches[1]));
                } elseif (preg_match('/^([^:]+):(\d+)$/', $search, $matches)) {
                    $q->orWhere(fn ($q) => $q->where('e.reference_type', $matches[1])->where('e.reference_id', $matches[2]));
                } elseif (ctype_digit($search)) {
                    $q->orWhere('e.reference_id', (int) $search);
                }
            });
        }

        return $q;
    }

    public function ledgerSummary(Builder $q, string $kind): array
    {
        $types = $kind === 'publisher' ? ['pending_earning', 'payable'] : ['deposit', 'spend'];
        $sign = $kind === 'publisher' ? '>' : '<';
        $row = (clone $q)->selectRaw('SUM(CASE WHEN e.type = ? AND e.amount_micros > 0 THEN e.amount_micros ELSE 0 END) as positive, SUM(CASE WHEN e.type = ? AND e.amount_micros '.$sign.' 0 THEN e.amount_micros ELSE 0 END) as second, SUM(e.amount_micros) as net', $types)->first();
        if ($kind === 'publisher') {
            $released = (int) $row->second;

            return ['earned_micros' => (int) $row->positive + $released, 'released_micros' => $released, 'awaiting_micros' => (int) $row->positive];
        }

        return ['deposits_micros' => (int) $row->positive, 'spend_micros' => -(int) $row->second, 'net_micros' => (int) $row->net];
    }

    public function ledgerRows(Builder $q, string $kind): Builder
    {
        return $q->select(['e.id', 'e.type', 'e.amount_micros', 'e.currency', 'e.reference_type', 'e.reference_id', 'e.created_at', 'u.id as user_id', 'u.name as user_name', 'u.deleted_at as user_deleted_at', 'r.id as related_id', 'r.name as related_name', 'r.deleted_at as related_deleted_at'])
            ->when($kind === 'publisher', fn ($q) => $q->addSelect(['e.settled_at', 'r.status as server_status']));
    }

    public function ledgerRow(object $e): array
    {
        return ['id' => (int) $e->id, 'type' => $e->type, 'amount_micros' => (int) $e->amount_micros, 'currency' => $e->currency,
            'reference_type' => $e->reference_type, 'reference_id' => $e->reference_id ? (int) $e->reference_id : null, 'created_at' => $this->iso($e->created_at),
            'account' => ['id' => (int) $e->user_id, 'name' => $e->user_name, 'deleted' => (bool) $e->user_deleted_at],
            'related' => $e->related_id ? ['id' => (int) $e->related_id, 'name' => $e->related_name, 'deleted' => (bool) $e->related_deleted_at] : null,
            'settled_at' => $this->iso($e->settled_at ?? null), 'held' => $e->type === 'pending_earning' && ($e->server_status ?? null) === 'suspended'];
    }

    public function payoutQuery(array $input): \Illuminate\Database\Eloquent\Builder
    {
        $q = Payout::query()->with(['publisherAccount.user' => fn ($q) => $q->withTrashed()])->where('currency', 'USD');
        $this->dates($q, $input, ($input['date_field'] ?? 'created') === 'paid' ? 'paid_at' : 'created_at');
        $q->when(! empty($input['account']), fn ($q) => $q->whereHas('publisherAccount', fn ($q) => $q->where('user_id', $input['account'])));
        if (! empty($input['search'])) {
            $s = trim($input['search']);
            $id = preg_replace('/^PAY-/i', '', $s);
            $q->where(fn ($q) => $q->where('id', ctype_digit($id) ? (int) $id : -1)->orWhere('external_reference', $s)->orWhere('provider_payout_id', $s));
        }

        return $q;
    }

    public function payoutRow(Payout $payout): array
    {
        $p = $payout->publisherAccount;
        $u = $p?->user;

        return ['id' => $payout->id, 'amount_micros' => (int) $payout->amount_micros, 'currency' => $payout->currency, 'status' => $payout->status,
            'period_start' => $payout->period_start?->toISOString(), 'period_end' => $payout->period_end?->toISOString(),
            'created_at' => $payout->created_at->toISOString(), 'paid_at' => $payout->paid_at?->toISOString(), 'version' => (int) ($payout->version ?? 1),
            'provider' => $payout->provider, 'payment_method' => $payout->payment_method, 'external_reference' => $payout->external_reference ?? $payout->provider_payout_id,
            'reason' => $payout->reason, 'internal_note' => $payout->internal_note,
            'publisher' => $u ? ['id' => $u->id, 'name' => $u->name, 'deleted' => $u->trashed(), 'status' => $u->trashed() ? 'deleted' : ($u->status === 'suspended' || $p->status === 'suspended' ? 'suspended' : 'active')] : null];
    }

    public function payoutDetails(Payout $payout, User $viewer, int $page = 1): array
    {
        $row = $this->payoutRow($payout);
        $p = $payout->publisherAccount;
        $row['can_manage'] = $viewer->adminCan('admin.finance.manage') && ($row['publisher']['status'] ?? null) === 'active';
        $row['balances'] = [
            'available_micros' => $p ? $this->balances->rawAvailableMicros($p) : 0,
            'awaiting_micros' => $p ? (int) DB::table('publisher_ledger_entries')->where('publisher_account_id', $p->id)->where('currency', 'USD')->where('type', 'pending_earning')->sum('amount_micros') : 0,
            'reserved_micros' => $p ? (int) Payout::where('publisher_account_id', $p->id)->where('currency', 'USD')->where('status', 'pending')->sum('amount_micros') : 0,
        ];
        $row['minimum_micros'] = (int) config('publisher.minimum_payout_micros', 50000000);
        $events = PayoutEvent::where('payout_id', $payout->id)->latest('id')->paginate(10, ['id', 'action', 'actor_name', 'details', 'created_at'], 'page', $page);
        $row['history'] = $events->items();
        $row['history_meta'] = $this->meta($events);

        return $row;
    }

    private function dates(object $q, array $input, string $column): void
    {
        if (! empty($input['from'])) {
            $q->where($column, '>=', CarbonImmutable::parse($input['from'], 'UTC'));
        }
        if (! empty($input['to'])) {
            $q->where($column, '<', CarbonImmutable::parse($input['to'], 'UTC')->addDay());
        }
        $q->where($column, '<=', now('UTC'));
    }

    public function meta(object $p): array
    {
        return ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'from' => $p->firstItem(), 'to' => $p->lastItem()];
    }

    public function iso(?string $value): ?string
    {
        return $value ? CarbonImmutable::parse($value, 'UTC')->toISOString() : null;
    }

    public function exactMoney(int $micros): string
    {
        $sign = $micros < 0 ? '-' : '';
        $value = abs($micros);

        return $sign.intdiv($value, 1000000).'.'.str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
