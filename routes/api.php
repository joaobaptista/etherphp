<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health']);
Route::get('/metrics', [HealthController::class, 'metrics']);

require __DIR__.'/api/auth.php';
require __DIR__.'/api/capsules.php';
require __DIR__.'/api/memories.php';
require __DIR__.'/api/messages.php';
require __DIR__.'/api/guardians.php';
require __DIR__.'/api/vault.php';
require __DIR__.'/api/google_photos.php';
require __DIR__.'/api/subscriptions.php';
require __DIR__.'/api/notifications.php';
require __DIR__.'/api/admin.php';
require __DIR__.'/api/ios.php';
