<?php

use App\Http\Controllers\Api\CapsuleController;
use Illuminate\Support\Facades\Route;

Route::middleware('jwt')->group(function (): void {
    Route::get('/capsules', [CapsuleController::class, 'index']);
    Route::post('/capsules', [CapsuleController::class, 'store']);
    Route::get('/capsules/{id}', [CapsuleController::class, 'show']);
    Route::put('/capsules/{id}', [CapsuleController::class, 'update']);
    Route::delete('/capsules/{id}', [CapsuleController::class, 'destroy']);
});
