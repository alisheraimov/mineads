<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AvatarController extends Controller
{
    public function store(Request $request): UserResource
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
        ]);

        $path = $request->file('avatar')->store('avatars', 'public');
        abort_if($path === false, 500, 'Unable to store your profile picture.');

        try {
            [$user, $previousPath] = DB::transaction(function () use ($request, $path): array {
                $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
                $previousPath = $user->avatar_path;
                $user->forceFill(['avatar_path' => $path])->save();

                return [$user, $previousPath];
            });
        } catch (Throwable $error) {
            Storage::disk('public')->delete($path);
            throw $error;
        }

        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }

        return UserResource::make($user->load(['advertiserAccount', 'publisherAccount']));
    }

    public function destroy(Request $request): UserResource
    {
        [$user, $previousPath] = DB::transaction(function () use ($request): array {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $previousPath = $user->avatar_path;
            $user->forceFill(['avatar_path' => null])->save();

            return [$user, $previousPath];
        });

        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }

        return UserResource::make($user->load(['advertiserAccount', 'publisherAccount']));
    }
}
