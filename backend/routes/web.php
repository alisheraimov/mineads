<?php

use App\Http\Controllers\ClickRedirectController;
use Illuminate\Support\Facades\Route;

Route::get('/c/{token}', ClickRedirectController::class)
    ->middleware('throttle:120,1')->name('clicks.redirect');

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'status' => 'ok',
    ]);
});
