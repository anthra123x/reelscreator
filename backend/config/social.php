<?php

return [
    'mock_mode' => env('SOCIAL_MOCK_MODE', true),

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect_uri' => env('TIKTOK_REDIRECT_URI', 'http://localhost:8000/api/social-accounts/callback/tiktok'),
        'api_base' => env('TIKTOK_API_BASE', 'https://open.tiktokapis.com/v2'),
    ],

    'instagram' => [
        'client_id' => env('INSTAGRAM_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
        'redirect_uri' => env('INSTAGRAM_REDIRECT_URI', 'http://localhost:8000/api/social-accounts/callback/instagram'),
        'graph_base' => env('INSTAGRAM_GRAPH_BASE', 'https://graph.facebook.com/v21.0'),
    ],

    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect_uri' => env('YOUTUBE_REDIRECT_URI', 'http://localhost:8000/api/social-accounts/callback/youtube'),
        'api_base' => env('YOUTUBE_API_BASE', 'https://www.googleapis.com/youtube/v3'),
        'upload_base' => env('YOUTUBE_UPLOAD_BASE', 'https://www.googleapis.com/upload/youtube/v3'),
    ],
];
