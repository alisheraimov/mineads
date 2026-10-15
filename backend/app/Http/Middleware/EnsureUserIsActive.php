<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuspended()) {
            return response()->json([
                'message' => 'This account has been suspended.',
            ], 403);
        }

        return $next($request);
    }
}
