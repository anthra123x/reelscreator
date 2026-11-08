<?php

namespace App\Modules\SocialAccounts\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class YouTubeOAuthService
{
    protected string $clientId;

    protected string $clientSecret;

    protected string $redirectUri;

    protected string $apiBase;

    public function __construct()
    {
        $this->clientId = (string) config('social.youtube.client_id', 'mock_yt_client_id');
        $this->clientSecret = (string) config('social.youtube.client_secret', 'mock_yt_client_secret');
        $this->redirectUri = (string) config('social.youtube.redirect_uri', 'https://reelscreator-api.vercel.app/api/social-accounts/callback/youtube');
        $this->apiBase = (string) config('social.youtube.api_base', 'https://www.googleapis.com/youtube/v3');
    }

    public function getAuthorizationUrl(string $state, ?string $clientId = null): string
    {
        $params = http_build_query([
            'client_id' => $clientId ?: $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/userinfo.profile',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return "https://accounts.google.com/o/oauth2/v2/auth?{$params}";
    }

    public function exchangeCode(string $code, ?string $clientId = null, ?string $clientSecret = null): array
    {
        if (config('social.mock_mode')) {
            return [
                'access_token' => 'mock_yt_access_token_'.Str::random(32),
                'refresh_token' => 'mock_yt_refresh_token_'.Str::random(32),
                'expires_in' => 3600,
                'user_id' => 'mock_yt_user_'.Str::random(8),
                'username' => 'shorts_channel',
            ];
        }

        $effectiveClientId = $clientId ?: $this->clientId;
        $effectiveClientSecret = $clientSecret ?: $this->clientSecret;

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $effectiveClientId,
            'client_secret' => $effectiveClientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('YouTube OAuth exchange failed: '.$response->body());
        }

        return $response->json();
    }

    public function refreshToken(string $refreshToken): array
    {
        if (config('social.mock_mode')) {
            return [
                'access_token' => 'mock_yt_refreshed_access_'.Str::random(32),
                'expires_in' => 3600,
            ];
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('YouTube token refresh failed: '.$response->body());
        }

        return $response->json();
    }
}
