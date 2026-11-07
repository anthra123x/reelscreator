<?php

use App\Modules\Clips\Controllers\ClipController;
use Illuminate\Support\Facades\Route;

Route::get('videos/{videoId}/clips', [ClipController::class, 'indexByVideo']);

Route::prefix('clips')->group(function () {
    Route::get('/', [ClipController::class, 'index']);
    Route::get('{id}', [ClipController::class, 'show']);
    Route::patch('{id}', [ClipController::class, 'update']);
    Route::delete('{id}', [ClipController::class, 'destroy']);
});
