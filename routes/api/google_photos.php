<?php

use App\Http\Controllers\Api\GooglePhotosController;
use Illuminate\Support\Facades\Route;

// Public OAuth callback multiplexer (Gmail Login + Google Photos Picker)
Route::get('/integrations/google-photos/callback', [GooglePhotosController::class, 'callback']);

Route::middleware('jwt')->group(function (): void {
    Route::get('/integrations/google-photos/status', [GooglePhotosController::class, 'status']);
    Route::get('/integrations/google-photos/authorization-url', [GooglePhotosController::class, 'authorizationUrl']);
    Route::post('/integrations/google-photos/authorization-url', [GooglePhotosController::class, 'authorizationUrl']);
    Route::post('/integrations/google-photos/sessions', [GooglePhotosController::class, 'createSession']);
    Route::get('/integrations/google-photos/sessions/{sessionId}', [GooglePhotosController::class, 'pollSession']);
    Route::post('/integrations/google-photos/sessions/{sessionId}/import', [GooglePhotosController::class, 'importSession']);
    Route::post('/integrations/google-photos/disconnect', [GooglePhotosController::class, 'disconnect']);

    Route::post('/capsules/{id}/google-photos/sessions', [GooglePhotosController::class, 'createSession']);
    Route::get('/capsules/{id}/google-photos/sessions/{sessionId}', [GooglePhotosController::class, 'pollSession']);
    Route::post('/capsules/{id}/google-photos/sessions/{sessionId}/import', [GooglePhotosController::class, 'importSession']);

    Route::post('/google-photos/disconnect', [GooglePhotosController::class, 'disconnect']);
});
