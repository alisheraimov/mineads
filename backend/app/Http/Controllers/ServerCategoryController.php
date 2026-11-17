<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class ServerCategoryController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => config('servers.categories')]);
    }
}
