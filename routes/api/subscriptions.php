<?php

use App\Http\Controllers\Api\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/subscriptions/plans', [SubscriptionController::class, 'plans']);
Route::post('/subscriptions/webhook', [SubscriptionController::class, 'webhook'])->middleware('throttle:120,1');

Route::middleware('jwt')->group(function (): void {
    Route::get('/subscriptions/current', [SubscriptionController::class, 'current']);
    Route::post('/subscriptions/checkout', [SubscriptionController::class, 'startCheckout'])->middleware('throttle:10,1');
    Route::get('/subscriptions/checkouts/{id}', [SubscriptionController::class, 'showCheckout'])->middleware('throttle:60,1');
    Route::post('/subscriptions/cancel', [SubscriptionController::class, 'cancelAutoRenew'])->middleware('throttle:10,1');
    Route::post('/subscriptions/simulate-payment', [SubscriptionController::class, 'simulatePayment']);
});
