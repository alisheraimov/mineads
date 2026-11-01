<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\AdminUserEvent;
use App\Models\AdvertiserLedgerEntry;
use App\Models\PublisherLedgerEntry;
use App\Models\User;
use App\Models\ValidatedImpression;
use App\Services\PublisherBalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', Rule::in(['advertiser', 'publisher', 'admin'])],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'deleted'])],
            'sort' => ['nullable', Rule::in(['created_at', 'name', 'email'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = User::withTrashed()->with('roles');
        if ($search = trim($input['search'] ?? '')) {
            $query->where(function ($query) use ($search): void {
                $query->whereLike('name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%');
                $id = preg_replace('/^U-/i', '', $search);
                if (ctype_digit($id)) {
                    $query->orWhere('id', $id);
                }
            });
        }
        if (! empty($input['role'])) {
            $query->where('role', $input['role']);
        }
        if (($input['status'] ?? null) === 'deleted') {
            $query->onlyTrashed();
        } elseif (! empty($input['status'])) {
            $query->whereNull('deleted_at')->where('status', $input['status']);
        }
        $users = $query->orderBy($input['sort'] ?? 'created_at', $input['direction'] ?? 'desc')->orderByDesc('id')
            ->paginate($input['per_page'] ?? 10);

        return response()->json([
            'data' => AdminUserResource::collection($users)->resolve($request),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total(), 'from' => $users->firstItem(), 'to' => $users->lastItem()],
            'roles' => $this->roles(),
            'can_create_admin' => $request->user()->hasRole('admin-owner'),
        ]);
    }

    public function show(Request $request, int $id, PublisherBalanceService $balances): JsonResponse
    {
        $this->authorizeView($request);
        $user = User::withTrashed()->with(['roles.permissions', 'advertiserAccount', 'publisherAccount'])->findOrFail($id);
        $finance = $request->user()->adminCan('admin.finance.view');
        $account = null;
        if ($advertiser = $user->advertiserAccount) {
            $account = [
                'type' => 'advertiser', 'status' => $advertiser->status,
                'active_campaigns' => $advertiser->campaigns()->where('status', 'active')->count(),
            ];
            if ($finance) {
                $ledger = AdvertiserLedgerEntry::query()->where('advertiser_account_id', $advertiser->id);
                $account['available_micros'] = (int) (clone $ledger)->sum('amount_micros');
                $account['lifetime_spend_micros'] = abs((int) (clone $ledger)->where('type', 'spend')->sum('amount_micros'));
            }
        } elseif ($publisher = $user->publisherAccount) {
            $account = [
                'type' => 'publisher', 'status' => $publisher->status,
                'servers' => $publisher->servers()->count(), 'active_servers' => $publisher->servers()->where('status', 'active')->count(),
            ];
            if ($finance) {
                $account['available_micros'] = $balances->availableMicros($publisher);
                $account['pending_micros'] = max(0, (int) PublisherLedgerEntry::query()->where('publisher_account_id', $publisher->id)->where('type', 'pending_earning')->sum('amount_micros'));
                $account['lifetime_earnings_micros'] = (int) ValidatedImpression::query()->where('publisher_account_id', $publisher->id)->sum('publisher_earning_micros');
            }
        }
        $protected = $this->protection($request->user(), $user);

        return response()->json(['data' => [
            ...AdminUserResource::make($user)->resolve($request),
            'permissions' => $user->role === 'admin' ? $user->getAllPermissions()->pluck('name')->values() : [],
            'account' => $account,
            'can_view_finance' => $finance,
            'can_manage' => $protected === null,
            'can_change_role' => $user->role === 'admin' && $protected === null,
            'protected_reason' => $protected,
            'history' => AdminUserEvent::query()->where('user_id', $id)->latest('id')->limit(20)->get(['id', 'action', 'details', 'created_at']),
        ], 'roles' => $this->roles()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('admin-owner'), 403, 'Only Owners can create admins.');
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'admin_role' => ['required', Rule::in(array_keys(config('admin.roles')))],
        ]);
        $user = DB::transaction(function () use ($request, $input): User {
            $this->lockAdmins($request);
            abort_unless($request->user()->fresh()->hasRole('admin-owner'), 403);
            if (User::withTrashed()->whereRaw('LOWER(email) = ?', [$input['email']])->exists()) {
                throw ValidationException::withMessages(['email' => 'This email address is already in use.']);
            }
            $user = User::query()->create([
                'name' => $input['name'], 'email' => $input['email'], 'password' => $input['password'],
                'role' => 'admin', 'status' => 'active', 'email_verified_at' => now(),
            ]);
            $user->assignRole($input['admin_role']);
            $this->record($request, $user, 'created', ['admin_role' => $input['admin_role']]);

            return $user;
        });

        return response()->json(['data' => AdminUserResource::make($user->load('roles'))->resolve($request)], 201);
    }

    public function suspend(Request $request, int $id): JsonResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->change($request, $id, function (User $user) use ($request, $input): void {
            if ($user->status !== 'active') {
                throw ValidationException::withMessages(['status' => 'Only active users can be suspended.']);
            }
            $user->forceFill(['status' => 'suspended', 'suspension_reason' => $input['reason'], 'suspended_at' => now(), 'suspended_by' => $request->user()->id])->save();
            $this->revokeSessions($user);
            $this->record($request, $user, 'suspended', ['reason' => $input['reason']]);
        });
    }

    public function reactivate(Request $request, int $id): JsonResponse
    {
        return $this->change($request, $id, function (User $user) use ($request): void {
            if ($user->status !== 'suspended') {
                throw ValidationException::withMessages(['status' => 'Only suspended users can be reactivated.']);
            }
            $user->forceFill(['status' => 'active', 'suspension_reason' => null, 'suspended_at' => null, 'suspended_by' => null])->save();
            $this->record($request, $user, 'reactivated');
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $input = $request->validate(['email' => ['required', 'string']]);

        return $this->change($request, $id, function (User $user) use ($request, $input): void {
            if ($input['email'] !== $user->email) {
                throw ValidationException::withMessages(['email' => 'Enter the user’s email address exactly to confirm deletion.']);
            }
            $this->revokeSessions($user);
            $user->delete();
            $this->record($request, $user, 'deleted');
        });
    }

    public function role(Request $request, int $id): JsonResponse
    {
        $input = $request->validate(['admin_role' => ['required', Rule::in(array_keys(config('admin.roles')))]]);

        return $this->change($request, $id, function (User $user) use ($request, $input): void {
            abort_unless($user->role === 'admin', 422, 'Commercial users cannot be promoted to admin.');
            $old = $user->getRoleNames()->all();
            $user->syncPermissions([]);
            $user->syncRoles([$input['admin_role']]);
            $this->revokeSessions($user);
            $this->record($request, $user, 'role_changed', ['before' => $old, 'after' => $input['admin_role']]);
        });
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->adminCan('admin.users.view'), 403, 'You do not have access to users and accounts.');
    }

    private function protection(User $actor, User $user): ?string
    {
        if ($user->trashed()) {
            return 'Deleted users are read-only.';
        }
        if ($actor->id === $user->id) {
            return 'You cannot change your own admin role, suspend or delete your own account.';
        }
        if ($user->role === 'admin' ? ! $actor->hasRole('admin-owner') : ! $actor->adminCan('admin.users.manage')) {
            return $user->role === 'admin' ? 'Only Owners can manage admin accounts.' : 'Your role has read-only access.';
        }
        if ($user->role === 'admin' && $user->status === 'active' && $user->hasVerifiedEmail() && $user->hasRole('admin-owner')
            && User::query()->where('role', 'admin')->where('status', 'active')->whereNotNull('email_verified_at')->role('admin-owner')->count() <= 1) {
            return 'Another active, verified Owner is required before this Owner can be changed, suspended or deleted.';
        }

        return null;
    }

    private function change(Request $request, int $id, \Closure $change): JsonResponse
    {
        $this->authorizeView($request);
        DB::transaction(function () use ($request, $id, $change): void {
            $this->lockAdmins($request);
            $user = User::withTrashed()->lockForUpdate()->findOrFail($id);
            $reason = $this->protection($request->user()->fresh(), $user);
            abort_if($reason !== null, 403, $reason ?? 'Access denied.');
            $change($user);
        });

        return response()->json(['message' => 'User updated.']);
    }

    private function lockAdmins(Request $request): void
    {
        User::query()->where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
        $actor = $request->user()->fresh();
        abort_unless($actor && $actor->status === 'active' && $actor->role === 'admin', 403);
    }

    private function record(Request $request, User $user, string $action, array $details = []): void
    {
        AdminUserEvent::query()->create(['user_id' => $user->id, 'actor_id' => $request->user()->id, 'action' => $action, 'details' => $details]);
    }

    private function revokeSessions(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    private function roles(): array
    {
        return collect(config('admin.roles'))->map(fn (array $role, string $key): array => [
            'value' => $key, ...$role,
            'permissions' => Role::query()->where('name', $key)->where('guard_name', 'web')->first()?->permissions->pluck('name')->values() ?? [],
        ])->values()->all();
    }
}
