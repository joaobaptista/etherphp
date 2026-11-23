<?php

use App\Http\Controllers\Api\AdminController;
use Illuminate\Support\Facades\Route;

Route::middleware(['jwt', 'admin'])->group(function (): void {
    Route::get('/admin/metrics', [AdminController::class, 'metrics']);
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::patch('/admin/users/{id}/role', [AdminController::class, 'updateRole']);
    Route::delete('/admin/users/{id}', [AdminController::class, 'destroyUser']);
    Route::post('/admin/users/batch-delete', [AdminController::class, 'batchDeleteUsers']);
});
