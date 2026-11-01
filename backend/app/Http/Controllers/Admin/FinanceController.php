<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdvertiserAccount;
use App\Models\Payout;
use App\Models\PayoutEvent;
use App\Models\PublisherAccount;
use App\Models\User;
use App\Services\AdminFinanceService;
use App\Services\PayoutReservationService;
use App\Services\PublisherBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceController extends Controller
{
    public function __construct(private readonly AdminFinanceService $finance) {}

    private function authorize(Request $request, bool $manage = false): void
    {
        abort_unless($request->user()->adminCan('admin.finance.view') && (! $manage || $request->user()->adminCan('admin.finance.manage')), 403, 'Your role cannot access this financial action.');
    }

    private function filters(Request $request): array
    {
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : []), 'before_or_equal:today'],
            'account' => ['nullable', 'integer', 'min:1'], 'related' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'string', 'max:100'], 'search' => ['nullable', 'string', 'max:255'],
            'held' => ['sometimes', 'boolean'],
            'status' => ['nullable', Rule::in(['pending', 'paid', 'failed', 'cancelled'])],
            'date_field' => ['sometimes', Rule::in(['created', 'paid'])],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['sometimes', Rule::in([10, 25, 50])],
        ]);
        if (! empty($input['from']) && ! empty($input['to'])) {
            abort_if(CarbonImmutable::parse($input['from'])->diffInDays(CarbonImmutable::parse($input['to'])) >= 366, 422, 'Choose a date range of 366 days or less.');
        }

        return $input;
    }

    public function overview(Request $request): JsonResponse
    {
        $this->authorize($request);
        $input = $request->validate([
            'period' => ['sometimes', Rule::in(['today', 'yesterday', 'month', 'last_week', 'last_month', 'custom'])],
            'from' => ['required_if:period,custom', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['required_if:period,custom', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
            'interval' => ['sometimes', Rule::in(['day', 'week', 'month'])], 'compare' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->finance->overview($input)]);
    }

    public function ledger(Request $request, string $kind): JsonResponse|StreamedResponse
    {
        $this->authorize($request);
        abort_unless(in_array($kind, ['advertiser', 'publisher'], true), 404);
        $input = $this->filters($request);
        $q = $this->finance->ledgerQuery($kind, $input);
        $summary = $this->finance->ledgerSummary(clone $q, $kind);
        $q = $this->finance->ledgerRows($q, $kind)->orderByDesc('e.created_at')->orderByDesc('e.id');
        if ($request->route('export')) {
            return $this->csv($kind.'-ledger', function ($out) use ($q, $kind): void {
                fputcsv($out, ['Entry ID', 'Posted UTC', 'User ID', 'Name', 'Deleted user', 'Type', 'Related ID', 'Related name', 'Amount USD', 'Source type', 'Source ID', 'Released UTC', 'Held'], ',', '"', '');
                foreach ($q->cursor() as $record) {
                    $e = $this->finance->ledgerRow($record);
                    $this->writeCsv($out, [$kind === 'advertiser' ? 'ALE-'.$e['id'] : 'PLE-'.$e['id'], $e['created_at'], 'U-'.$e['account']['id'], $e['account']['name'], $e['account']['deleted'] ? 'Yes' : 'No', $e['type'], $e['related']['id'] ?? '', $e['related']['name'] ?? '', $this->finance->exactMoney($e['amount_micros']), $e['reference_type'], $e['reference_id'], $e['settled_at'], $e['held'] ? 'Yes' : 'No'], [8]);
                }
            });
        }
        $page = $q->paginate((int) ($input['per_page'] ?? 10));
        $table = $kind === 'publisher' ? 'publisher_ledger_entries' : 'advertiser_ledger_entries';
        $types = DB::table($table)->distinct()->orderBy('type')->pluck('type');

        return response()->json(['data' => $page->getCollection()->map(fn ($e) => $this->finance->ledgerRow($e)), 'summary' => $summary, 'types' => $types, 'meta' => $this->finance->meta($page), 'permissions' => $this->permissions($request), 'generated_at' => now('UTC')->toISOString()]);
    }

    public function payouts(Request $request): JsonResponse|StreamedResponse
    {
        $this->authorize($request);
        $input = $this->filters($request);
        $q = $this->finance->payoutQuery($input);
        $totals = [];
        foreach (['pending', 'failed', 'paid', 'cancelled'] as $status) {
            $s = (clone $q)->where('status', $status);
            $totals[$status] = ['count' => (clone $s)->count(), 'amount_micros' => (int) $s->sum('amount_micros')];
        }
        $q->when(! empty($input['status']), fn ($q) => $q->where('status', $input['status']))->latest('created_at')->latest('id');
        if ($request->route('export')) {
            return $this->csv('payouts', function ($out) use ($q): void {
                fputcsv($out, ['Payout ID', 'Publisher ID', 'Publisher', 'Publisher status', 'Amount USD', 'Earnings from UTC', 'Earnings through UTC', 'Status', 'Created UTC', 'Paid UTC', 'Payment method', 'External reference', 'Reason', 'Internal note'], ',', '"', '');
                foreach ($q->reorder('id')->lazyById(500) as $payout) {
                    $e = $this->finance->payoutRow($payout);
                    $this->writeCsv($out, ['PAY-'.$e['id'], $e['publisher']['id'] ?? '', $e['publisher']['name'] ?? '', $e['publisher']['status'] ?? '', $this->finance->exactMoney($e['amount_micros']), $e['period_start'], $e['period_end'], $e['status'], $e['created_at'], $e['paid_at'], $e['payment_method'], $e['external_reference'], $e['reason'], $e['internal_note']], [4]);
                }
            });
        }
        $page = $q->paginate((int) ($input['per_page'] ?? 10));

        return response()->json(['data' => $page->getCollection()->map(fn ($p) => $this->finance->payoutRow($p)), 'totals' => $totals, 'meta' => $this->finance->meta($page), 'permissions' => $this->permissions($request), 'generated_at' => now('UTC')->toISOString()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorize($request);
        $input = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);

        return response()->json(['data' => $this->finance->payoutDetails($this->finance->payoutQuery([])->findOrFail($id), $request->user(), $input['page'] ?? 1), 'permissions' => $this->permissions($request)]);
    }

    public function action(Request $request, int $id, PublisherBalanceService $balances, PayoutReservationService $reservations): JsonResponse
    {
        $this->authorize($request, true);
        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['paid', 'failed', 'cancelled', 'retry'])],
            'payment_method' => ['required_if:action,paid', 'nullable', Rule::in(['bank_transfer', 'paypal', 'other'])],
            'external_reference' => ['required_if:action,paid', 'nullable', 'string', 'max:255'],
            'paid_at' => ['required_if:action,paid', 'nullable', 'date_format:Y-m-d\\TH:i:s\\Z', 'before_or_equal:now'],
            'reason' => ['required_if:action,failed,cancelled', 'nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);
        try {
            DB::transaction(function () use ($request, $id, $input, $balances, $reservations): void {
                $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                abort_unless($actor->status === 'active' && $actor->email_verified_at && $actor->adminCan('admin.finance.view') && $actor->adminCan('admin.finance.manage'), 403, 'Your financial permissions changed.');
                $lookup = Payout::query()->findOrFail($id);
                $publisherId = $lookup->publisher_account_id;
                $publisherUserId = PublisherAccount::whereKey($publisherId)->value('user_id');
                $user = User::withTrashed()->lockForUpdate()->find($publisherUserId);
                $publisher = PublisherAccount::lockForUpdate()->findOrFail($publisherId);
                $payout = Payout::lockForUpdate()->findOrFail($id);
                abort_unless($user && ! $user->trashed() && $user->status === 'active' && $publisher->status === 'active', 409, 'This publisher is suspended or deleted. Payment actions are blocked.');
                abort_unless((int) $payout->version === (int) $input['version'], 409, 'This payout changed. Refresh before recording an outcome.');
                $action = $input['action'];
                abort_unless($payout->status === ($action === 'retry' ? 'failed' : 'pending'), 409, 'This action is not available for the current payout status.');
                $from = $payout->status;
                if ($action === 'retry') {
                    $available = $balances->rawAvailableMicros($publisher);
                    abort_if($available < $payout->amount_micros || $available < 0, 409, 'Insufficient released funds to reserve this payout. Refresh balances.');
                    abort_if($payout->amount_micros < (int) config('publisher.minimum_payout_micros', 50000000), 409, 'This payout is below the current minimum payout threshold.');
                    $payout->update(['status' => 'pending', 'reason' => null, 'version' => $payout->version + 1]);
                    $reservations->reserve($payout, $publisher);
                } elseif ($action === 'paid') {
                    $paidAt = CarbonImmutable::parse($input['paid_at'], 'UTC');
                    if ($paidAt->lt($payout->created_at)) {
                        throw ValidationException::withMessages(['paid_at' => 'Payment date cannot be before this payout was created.']);
                    }
                    $duplicate = Payout::where('payment_method', $input['payment_method'])->where('external_reference', trim($input['external_reference']))->whereKeyNot($id)->exists();
                    if ($duplicate) {
                        throw ValidationException::withMessages(['external_reference' => 'This payment reference is already recorded.']);
                    }
                    $payout->update(['status' => 'paid', 'payment_method' => $input['payment_method'], 'external_reference' => trim($input['external_reference']), 'paid_at' => $paidAt, 'internal_note' => $input['internal_note'] ?? null, 'reason' => null, 'version' => $payout->version + 1]);
                } else {
                    $payout->update(['status' => $action, 'reason' => trim($input['reason']), 'internal_note' => $input['internal_note'] ?? null, 'version' => $payout->version + 1]);
                }
                PayoutEvent::create(['payout_id' => $payout->id, 'actor_id' => $actor->id, 'actor_name' => $actor->name, 'action' => $action,
                    'details' => ['from' => $from, 'to' => $payout->status, 'amount_micros' => (int) $payout->amount_micros, 'reason' => $payout->reason, 'payment_method' => $payout->payment_method, 'external_reference' => $payout->external_reference, 'paid_at' => $payout->paid_at?->toISOString(), 'internal_note' => $payout->internal_note]]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['external_reference' => 'This payment reference is already recorded.']);
        }

        return response()->json(['data' => $this->finance->payoutDetails($this->finance->payoutQuery([])->findOrFail($id), $request->user()), 'message' => $input['action'] === 'retry' ? 'Payout reserved and returned to Pending.' : 'Payment outcome recorded.']);
    }

    public function options(Request $request): JsonResponse
    {
        $this->authorize($request);
        $input = $request->validate(['kind' => ['required', Rule::in(['advertiser', 'publisher'])], 'account' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:255']]);
        $publisher = $input['kind'] === 'publisher';
        $model = $publisher ? PublisherAccount::class : AdvertiserAccount::class;
        $q = $model::query()->with(['user' => fn ($q) => $q->withTrashed()]);
        if ($search = $input['search'] ?? null) {
            $needle = '%'.addcslashes($search, '%_\\').'%';
            $q->whereHas('user', fn ($q) => $q->withTrashed()->where(fn ($q) => $q->where('name', 'like', $needle)->orWhere('email', 'like', $needle)->orWhere('id', preg_match('/^(?:U-)?(\d+)$/i', $search, $m) ? (int) $m[1] : -1)));
        }
        $accounts = $q->orderByDesc('id')->limit(25)->get()->map(fn ($a) => ['id' => $a->user_id, 'name' => $a->user?->name ?? 'Deleted user', 'email' => $a->user?->email, 'deleted' => (bool) $a->user?->trashed()]);
        $selected = isset($input['account']) ? $model::with(['user' => fn ($q) => $q->withTrashed()])->where('user_id', $input['account'])->first() : null;
        $related = $selected ? ($publisher ? $selected->servers()->withTrashed() : $selected->campaigns()->withTrashed())->orderBy('name')->get(['id', 'name'])->toArray() : [];

        return response()->json(['data' => $accounts, 'selected_name' => $selected?->user?->name, 'related' => $related]);
    }

    private function permissions(Request $request): array
    {
        return ['users' => $request->user()->adminCan('admin.users.view'), 'campaigns' => $request->user()->adminCan('admin.campaigns.view'), 'servers' => $request->user()->adminCan('admin.servers.view'), 'manage' => $request->user()->adminCan('admin.finance.manage')];
    }

    private function csv(string $name, callable $write): StreamedResponse
    {
        return response()->streamDownload(function () use ($write): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $write($out);
            fclose($out);
        }, 'mineads-'.$name.'-'.now('UTC')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function writeCsv($out, array $cells, array $numericColumns): void
    {
        foreach ($cells as $index => $value) {
            if (is_string($value) && ! in_array($index, $numericColumns, true) && preg_match('/^[\s]*[=+@\-]/u', $value)) {
                $cells[$index] = "'".$value;
            }
        }
        fputcsv($out, $cells, ',', '"', '');
    }
}
