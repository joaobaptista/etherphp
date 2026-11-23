<?php

use App\Http\Controllers\Api\IosController;
use Illuminate\Support\Facades\Route;

// Native iOS app (iCloud photos POC). In the app the server is just the site address (local or production); it adds /api/ios itself
Route::prefix('ios/v1')->group(function (): void {
    Route::post('/login', [IosController::class, 'login'])->middleware('throttle:login');

    Route::middleware('jwt')->group(function (): void {
        Route::get('/health', [IosController::class, 'health']);
        Route::put('/photos/{sha256}.jpg', [IosController::class, 'storePhoto'])
            ->where('sha256', '[A-Fa-f0-9]{64}')
            ->middleware('throttle:600,1');
    });
});
