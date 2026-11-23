<?php

use App\Http\Controllers\Api\GuardianController;
use Illuminate\Support\Facades\Route;

Route::middleware('jwt')->group(function (): void {
    Route::get('/guardians', [GuardianController::class, 'index']);
    Route::post('/guardians', [GuardianController::class, 'store']);
    Route::get('/guardians/circle/connections', [GuardianController::class, 'connections']);
    Route::get('/guardians/{id}', [GuardianController::class, 'show']);
    Route::put('/guardians/{id}', [GuardianController::class, 'update']);
    Route::delete('/guardians/{id}', [GuardianController::class, 'destroy']);
    Route::post('/guardians/{id}/photo', [GuardianController::class, 'uploadPhoto']);
    Route::delete('/guardians/{id}/photo', [GuardianController::class, 'deletePhoto']);
});
