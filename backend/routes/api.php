<?php

use App\Http\Controllers\Admin\CampaignController as AdminCampaignController;
use App\Http\Controllers\Admin\CreativeController as AdminCreativeController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\PublisherController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\UserController;
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

Route::prefix('admin')->middleware(['auth:sanctum', 'active', 'admin', 'verified'])->group(function () {
    Route::get('/publishers', [PublisherController::class, 'index']);
    Route::get('/servers', [ServerController::class, 'index']);
    Route::get('/servers/{id}', [ServerController::class, 'show'])->whereNumber('id');
    Route::get('/servers/{id}/activity', [ServerController::class, 'activity'])->whereNumber('id');
    Route::get('/servers/{id}/history', [ServerController::class, 'history'])->whereNumber('id');
    Route::post('/servers/{id}/moderate', [ServerController::class, 'moderate'])->whereNumber('id');
    Route::delete('/servers/{id}', [ServerController::class, 'destroy'])->whereNumber('id');
    Route::get('/creatives', [AdminCreativeController::class, 'index']);
    Route::get('/creatives/options', [AdminCreativeController::class, 'options']);
    Route::get('/creatives/{id}', [AdminCreativeController::class, 'show'])->whereNumber('id');
    Route::post('/creatives/{id}/moderate', [AdminCreativeController::class, 'moderate'])->whereNumber('id');
    Route::delete('/creatives/{id}', [AdminCreativeController::class, 'destroy'])->whereNumber('id');
    Route::get('/campaigns', [AdminCampaignController::class, 'index']);
    Route::get('/campaigns/advertisers', [AdminCampaignController::class, 'advertisers']);
    Route::get('/campaigns/{id}', [AdminCampaignController::class, 'show'])->whereNumber('id');
    Route::get('/campaigns/{id}/creatives', [AdminCampaignController::class, 'creatives'])->whereNumber('id');
    Route::delete('/campaigns/{id}', [AdminCampaignController::class, 'destroy'])->whereNumber('id');
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
    Route::post('/users/{id}/suspend', [UserController::class, 'suspend'])->whereNumber('id');
    Route::post('/users/{id}/reactivate', [UserController::class, 'reactivate'])->whereNumber('id');
    Route::patch('/users/{id}/role', [UserController::class, 'role'])->whereNumber('id');
    Route::delete('/users/{id}', [UserController::class, 'destroy'])->whereNumber('id');
    Route::get('/dashboard', [AdminDashboardController::class, 'show']);
    Route::get('/dashboard/performance', [AdminDashboardController::class, 'performance']);
});

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
        Route::put('/servers/{server}', [PublisherServerController::class, 'update'])->whereNumber('server');
        Route::post('/servers/{server}/resubmit', [PublisherServerController::class, 'resubmit'])->whereNumber('server');
        Route::get('/servers/{server}', [PublisherServerController::class, 'show'])->whereNumber('server');
        Route::get('/servers/{server}/performance', [PublisherServerController::class, 'performance'])->whereNumber('server');
        Route::post('/servers/{server}/setup-token', [PublisherServerController::class, 'regenerate'])->whereNumber('server');
        Route::patch('/servers/{server}/status', [PublisherServerController::class, 'status'])->whereNumber('server');
        Route::delete('/servers/{server}', [PublisherServerController::class, 'destroy'])->whereNumber('server');
    });
