<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->stateless()
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->stateless()->user();

        $user = User::query()
            ->where('email', $googleUser->getEmail())
            ->orWhere('google_id', $googleUser->getId())
            ->first();

        if (! $user) {
            $user = User::query()->create([
                'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: 'MineAds User',
                'email' => $googleUser->getEmail(),
                'email_verified_at' => now(),
                'password' => Str::password(32),
                'role' => 'advertiser',
                'status' => 'active',
                'google_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
            ]);
        } else {
            $user->forceFill([
                'google_id' => $user->google_id ?: $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
                'email_verified_at' => $user->email_verified_at ?: now(),
            ])->save();
        }

        if ($user->isSuspended()) {
            return redirect()->away($this->frontendUrl('/login?error=suspended'));
        }

        $token = $user->createToken('web')->plainTextToken;

        return redirect()->away($this->frontendUrl('/auth/callback?token='.urlencode($token)));
    }

    private function frontendUrl(string $path): string
    {
        return rtrim((string) config('services.frontend.url'), '/').$path;
    }
}
