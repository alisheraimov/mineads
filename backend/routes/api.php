<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PluginApiController;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});

Route::prefix('plugin/v1')->group(function () {
    Route::post('/activate', [PluginApiController::class, 'activate']);
    Route::post('/heartbeat', [PluginApiController::class, 'heartbeat']);
    Route::post('/ads/request', [PluginApiController::class, 'requestAds']);
    Route::post('/impressions', [PluginApiController::class, 'impressions']);
});
