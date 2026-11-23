<?php

use App\Http\Controllers\Api\VaultKeyController;
use App\Http\Controllers\Api\VaultMediaController;
use Illuminate\Support\Facades\Route;

Route::middleware('jwt')->group(function (): void {
    Route::get('/vault/keys', [VaultKeyController::class, 'show']);
    Route::post('/vault/keys', [VaultKeyController::class, 'store']);
    Route::put('/vault/keys', [VaultKeyController::class, 'update'])->middleware('throttle:vault-proof');

    Route::post('/vault/media/upload', [VaultMediaController::class, 'upload']);
    Route::get('/vault/media/{id}/meta', [VaultMediaController::class, 'meta']);
    Route::delete('/vault/media/{id}', [VaultMediaController::class, 'destroy']);
});

// Media streaming/rendering routes also accept ?token= for <img>/<video>/<audio> elements
Route::middleware('jwt:allow-query')->group(function (): void {
    Route::get('/vault/media/{id}/stream', [VaultMediaController::class, 'stream']);
    Route::get('/vault/media/{id}/poster', [VaultMediaController::class, 'poster']);
    Route::get('/vault/media/{id}/waveform', [VaultMediaController::class, 'waveform']);
    Route::get('/vault/media/{id}/live-video', [VaultMediaController::class, 'liveVideo']);
    Route::get('/vault/media/{id}/export', [VaultMediaController::class, 'export']);
    Route::get('/vault/media/{id}', [VaultMediaController::class, 'show']);
});
