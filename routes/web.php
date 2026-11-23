<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health']);
Route::get('/metrics', [HealthController::class, 'metrics']);

// Public legal pages (required to publish the Google OAuth app used by Google Photos)
Route::get('/sobre', [LegalController::class, 'about'])->name('legal.about');
Route::get('/privacidade', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/termos', [LegalController::class, 'terms'])->name('legal.terms');
Route::redirect('/privacy', '/privacidade', 301);
Route::redirect('/terms', '/termos', 301);

// API documentation shortcuts (same addresses the Node backend used); the docs themselves are /docs/api and /docs/api.json
Route::redirect('/docs', '/docs/api');
Route::redirect('/openapi.json', '/docs/api.json');

// Private uploaded images (avatars, guardian photos, generic uploads) protected by JWT + ownership check
Route::get('/uploads/{path}', [SpaController::class, 'serveUpload'])
    ->where('path', '.*')
    ->middleware('jwt:allow-query');

// React 19 SPA shell for all web routes (excluding API, uploads, health, docs and Laravel's /up)
Route::get('/{any?}', [SpaController::class, 'index'])
    ->where('any', '^(?!api(?:/|$)|uploads(?:/|$)|up(?:/|$)|health(?:/|$)|metrics(?:/|$)|docs(?:/|$)|openapi\.json$).*$');
