<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ReelForge Modular API Routes
|--------------------------------------------------------------------------
|
| Routes are segregated by domain module under app/Modules/.
|
*/

// Videos module
if (file_exists(app_path('Modules/Videos/Routes/api.php'))) {
    Route::group([], app_path('Modules/Videos/Routes/api.php'));
}

// Clips module
if (file_exists(app_path('Modules/Clips/Routes/api.php'))) {
    Route::group([], app_path('Modules/Clips/Routes/api.php'));
}

// SocialAccounts module
if (file_exists(app_path('Modules/SocialAccounts/Routes/api.php'))) {
    Route::group([], app_path('Modules/SocialAccounts/Routes/api.php'));
}

// Publishing module
if (file_exists(app_path('Modules/Publishing/Routes/api.php'))) {
    Route::group([], app_path('Modules/Publishing/Routes/api.php'));
}
