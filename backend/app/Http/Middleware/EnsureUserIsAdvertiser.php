<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdvertiser
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'advertiser') {
            return response()->json([
                'message' => 'This endpoint is available to advertisers only.',
            ], 403);
        }

        return $next($request);
    }
}
