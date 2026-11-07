<?php

use App\Modules\SocialAccounts\Controllers\OAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('social-accounts')->group(function () {
    Route::get('/', [OAuthController::class, 'index']);
    Route::post('connect/{platform}', [OAuthController::class, 'connect']);
    Route::get('callback/{platform}', [OAuthController::class, 'callback']);
    Route::post('mock-connect', [OAuthController::class, 'mockConnect']);
    Route::delete('{id}', [OAuthController::class, 'destroy']);
});
