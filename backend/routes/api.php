<?php

use App\Http\Controllers\Advertiser\BillingController;
use App\Http\Controllers\Advertiser\CampaignController;
use App\Http\Controllers\Advertiser\CreativeController;
use App\Http\Controllers\Advertiser\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\PluginApiController;
use App\Http\Controllers\Publisher\DashboardController as PublisherDashboardController;
use App\Http\Controllers\Publisher\PayoutController as PublisherPayoutController;
use App\Http\Controllers\Publisher\ServerController as PublisherServerController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
    ]);
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::get('/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:10,1');
    Route::get('/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
        Route::put('/password', [AuthController::class, 'updatePassword'])->middleware('throttle:6,1');
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/email/verification-notification', [AuthController::class, 'sendVerificationEmail'])
            ->middleware('throttle:6,1');
        Route::post('/onboarding', [AuthController::class, 'completeOnboarding']);
    });
});

Route::prefix('plugin/v1')->group(function () {
    Route::post('/activate', [PluginApiController::class, 'activate']);
    Route::post('/heartbeat', [PluginApiController::class, 'heartbeat']);
    Route::post('/ads/request', [PluginApiController::class, 'requestAds']);
    Route::post('/impressions', [PluginApiController::class, 'impressions']);
});

Route::prefix('advertiser')
    ->middleware(['auth:sanctum', 'active', 'advertiser'])
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'show']);
        Route::get('/billing', [BillingController::class, 'show']);
        Route::get('/creatives/options', [CreativeController::class, 'options']);
        Route::get('/creatives', [CreativeController::class, 'index']);
        Route::post('/creatives', [CreativeController::class, 'store']);
        Route::get('/creatives/{creative}', [CreativeController::class, 'show'])->whereNumber('creative');
        Route::put('/creatives/{creative}', [CreativeController::class, 'update'])->whereNumber('creative');
        Route::patch('/creatives/{creative}/pause', [CreativeController::class, 'pause'])->whereNumber('creative');
        Route::delete('/creatives/{creative}', [CreativeController::class, 'destroy'])->whereNumber('creative');
        Route::get('/campaigns/options', [CampaignController::class, 'options']);
        Route::get('/campaigns', [CampaignController::class, 'index']);
        Route::post('/campaigns', [CampaignController::class, 'store']);
        Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign');
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->whereNumber('campaign');
        Route::patch('/campaigns/{campaign}/status', [CampaignController::class, 'status'])->whereNumber('campaign');
    });

Route::prefix('publisher')
    ->middleware(['auth:sanctum', 'active', 'publisher'])
    ->group(function () {
        Route::get('/dashboard', [PublisherDashboardController::class, 'show']);
        Route::get('/payouts', [PublisherPayoutController::class, 'show']);
        Route::get('/servers', [PublisherServerController::class, 'index']);
        Route::post('/servers', [PublisherServerController::class, 'store']);
        Route::get('/servers/{server}', [PublisherServerController::class, 'show'])->whereNumber('server');
        Route::get('/servers/{server}/performance', [PublisherServerController::class, 'performance'])->whereNumber('server');
        Route::post('/servers/{server}/setup-token', [PublisherServerController::class, 'regenerate'])->whereNumber('server');
        Route::patch('/servers/{server}/status', [PublisherServerController::class, 'status'])->whereNumber('server');
        Route::delete('/servers/{server}', [PublisherServerController::class, 'destroy'])->whereNumber('server');
    });
