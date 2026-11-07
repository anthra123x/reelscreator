<?php

use App\Modules\Publishing\Controllers\PublishController;
use Illuminate\Support\Facades\Route;

Route::post('clips/{id}/publish', [PublishController::class, 'publish']);

Route::prefix('publications')->group(function () {
    Route::get('/', [PublishController::class, 'index']);
    Route::post('{id}/retry', [PublishController::class, 'retry']);
});
