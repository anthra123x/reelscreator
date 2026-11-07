<?php

// Forward Vercel serverless requests to Laravel public/index.php
// Prepare ephemeral writable /tmp filesystem paths for Laravel 12

$storagePath = '/tmp/storage';
$_ENV['LARAVEL_STORAGE_PATH'] = $storagePath;
$_SERVER['LARAVEL_STORAGE_PATH'] = $storagePath;

$bootstrapCache = '/tmp/bootstrap_cache';
if (!is_dir($bootstrapCache)) {
    @mkdir($bootstrapCache, 0777, true);
}
$_ENV['APP_SERVICES_CACHE'] = $bootstrapCache . '/services.php';
$_SERVER['APP_SERVICES_CACHE'] = $bootstrapCache . '/services.php';
$_ENV['APP_PACKAGES_CACHE'] = $bootstrapCache . '/packages.php';
$_SERVER['APP_PACKAGES_CACHE'] = $bootstrapCache . '/packages.php';

$requiredDirs = [
    $storagePath . '/framework/views',
    $storagePath . '/framework/cache/data',
    $storagePath . '/framework/sessions',
    $storagePath . '/logs',
    $storagePath . '/app/public',
];

foreach ($requiredDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

// Fallback REMOTE_ADDR if absent
if (empty($_SERVER['REMOTE_ADDR'])) {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

// Prevent Symfony/Laravel from stripping /api as the script base URL
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__ . '/../public/index.php';
