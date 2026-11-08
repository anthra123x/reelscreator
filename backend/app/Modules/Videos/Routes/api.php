<?php

use App\Modules\Videos\Controllers\InternalJobCallbackController;
use App\Modules\Videos\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::prefix('videos')->group(function () {
    Route::post('/', [VideoController::class, 'store']);
    Route::post('decompose', [VideoController::class, 'ingestAndDecompose']);
    Route::post('{id}/decompose', [VideoController::class, 'decompose']);
    Route::get('/', [VideoController::class, 'index']);
    Route::get('{id}', [VideoController::class, 'show']);
    Route::get('{id}/status', [VideoController::class, 'status']);
    Route::delete('{id}', [VideoController::class, 'destroy']);
});

Route::post('internal/jobs/{id}/callback', [InternalJobCallbackController::class, 'handle']);
