<?php

use App\Http\Controllers\Api\AdminController;
use Illuminate\Support\Facades\Route;

Route::middleware(['jwt', 'admin'])->group(function (): void {
    Route::get('/admin/metrics', [AdminController::class, 'metrics']);
    Route::get('/admin/users', [AdminController::class, 'users']);
    // Granting or removing admin: root only (a regular admin gets 403)
    Route::patch('/admin/users/{id}/role', [AdminController::class, 'updateRole'])->middleware('root');
    Route::delete('/admin/users/{id}', [AdminController::class, 'destroyUser']);
    Route::post('/admin/users/batch-delete', [AdminController::class, 'batchDeleteUsers']);
});
