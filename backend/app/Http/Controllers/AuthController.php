<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'] ?? Str::before($data['email'], '@'),
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'advertiser',
            'status' => 'active',
        ]);

        $user->sendEmailVerificationNotification();

        return $this->tokenResponse($user->fresh(['advertiserAccount', 'publisherAccount']), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', $data['email'])
            ->with(['advertiserAccount', 'publisherAccount'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'This account has been suspended.',
            ], 403);
        }

        return $this->tokenResponse($user);
    }

    public function sendVerificationEmail(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email already verified.',
            ]);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Verification email sent.',
        ]);
    }

    public function verifyEmail(Request $request, int $id, string $hash)
    {
        $user = User::query()->findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            abort(403);
        }

        if ($user->hasVerifiedEmail()) {
            return redirect()->away($this->frontendUrl('/choose-path?verified=1'));
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->away($this->frontendUrl('/choose-path?verified=1'));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink($data);

        return response()->json([
            'message' => 'If an account exists for this email, a password reset link will be sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => 'Password updated.',
        ]);
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make(
            $request->user()->load(['advertiserAccount', 'publisherAccount']),
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    public function updateProfile(Request $request): UserResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->update($data);

        return UserResource::make(
            $request->user()->fresh(['advertiserAccount', 'publisherAccount']),
        );
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        DB::transaction(function () use ($user, $currentToken, $data): void {
            $user->forceFill([
                'password' => $data['password'],
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()
                ->when($currentToken instanceof PersonalAccessToken, fn ($query) => $query->whereKeyNot($currentToken->getKey()))
                ->delete();
        });

        return response()->json(['message' => 'Password updated. Other sessions have been signed out.']);
    }

    public function completeOnboarding(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(['advertiser', 'publisher'])],
            'account_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user()->load(['advertiserAccount', 'publisherAccount']);

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Verify your email before choosing an account path.',
            ], 409);
        }

        if ($user->advertiserAccount || $user->publisherAccount) {
            return response()->json([
                'message' => 'This user already has an account type.',
            ], 422);
        }

        $user->forceFill(['role' => $data['role']])->save();
        $this->createCommercialAccount($user, $data['role'], $data['account_name'] ?? null);

        return response()->json([
            'user' => UserResource::make($user->fresh(['advertiserAccount', 'publisherAccount'])),
        ]);
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => UserResource::make($user),
        ], $status);
    }

    private function createCommercialAccount(User $user, string $role, ?string $accountName = null): void
    {
        $name = $accountName ?: $user->name;

        if ($role === 'advertiser') {
            $user->advertiserAccount()->firstOrCreate([], [
                'name' => $name,
                'status' => 'active',
                'currency' => 'USD',
            ]);

            return;
        }

        $user->publisherAccount()->firstOrCreate([], [
            'name' => $name,
            'status' => 'active',
            'currency' => 'USD',
        ]);
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('services.frontend.url'), '/').$path;
    }
}
