<?php

use App\Http\Controllers\Api\MemoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('jwt')->group(function (): void {
    Route::get('/memories', [MemoryController::class, 'index']);
    Route::post('/memories', [MemoryController::class, 'store']);
    Route::get('/memories/{id}', [MemoryController::class, 'show']);
    Route::put('/memories/{id}', [MemoryController::class, 'update']);
    Route::delete('/memories/{id}', [MemoryController::class, 'destroy']);
});
