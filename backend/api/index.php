<?php

// Forward Vercel serverless requests to Laravel public/index.php
// Prepare ephemeral writable /tmp filesystem paths for Laravel 12

$storagePath = $_ENV['LARAVEL_STORAGE_PATH'] ?? '/tmp/storage';
$_ENV['LARAVEL_STORAGE_PATH'] = $storagePath;
$_SERVER['LARAVEL_STORAGE_PATH'] = $storagePath;

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

require __DIR__ . '/../public/index.php';
