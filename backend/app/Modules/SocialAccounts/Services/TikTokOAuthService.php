<?php

namespace App\Modules\SocialAccounts\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class TikTokOAuthService
{
    protected string $clientKey;

    protected string $clientSecret;

    protected string $redirectUri;

    protected string $apiBase;

    public function __construct()
    {
        $this->clientKey = (string) config('social.tiktok.client_key', 'mock_tiktok_client_key');
        $this->clientSecret = (string) config('social.tiktok.client_secret', 'mock_tiktok_client_secret');
        $this->redirectUri = (string) config('social.tiktok.redirect_uri', 'https://reelscreator-api.vercel.app/api/social-accounts/callback/tiktok');
        $this->apiBase = (string) config('social.tiktok.api_base', 'https://open.tiktokapis.com/v2');
    }

    public function getAuthorizationUrl(string $state, ?string $codeVerifier = null, ?string $clientKey = null): string
    {
        $codeChallenge = $codeVerifier ? hash('sha256', $codeVerifier) : 'mock_challenge';

        $params = http_build_query([
            'client_key' => $clientKey ?: $this->clientKey,
            'scope' => 'user.info.basic,video.upload,video.publish',
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return "https://www.tiktok.com/v2/auth/authorize/?{$params}";
    }

    public function exchangeCode(string $code, ?string $codeVerifier = null, ?string $clientKey = null, ?string $clientSecret = null): array
    {
        if (config('social.mock_mode')) {
            return [
                'access_token' => 'mock_tt_access_token_'.Str::random(32),
                'refresh_token' => 'mock_tt_refresh_token_'.Str::random(32),
                'expires_in' => 86400,
                'open_id' => 'mock_tt_user_'.Str::random(8),
                'username' => 'tiktok_creator',
            ];
        }

        $effectiveClientKey = $clientKey ?: $this->clientKey;
        $effectiveClientSecret = $clientSecret ?: $this->clientSecret;

        $response = Http::asForm()->post("{$this->apiBase}/oauth/token/", [
            'client_key' => $effectiveClientKey,
            'client_secret' => $effectiveClientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $codeVerifier ?? '',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('TikTok OAuth exchange failed: '.$response->body());
        }

        return $response->json();
    }

    public function refreshToken(string $refreshToken): array
    {
        if (config('social.mock_mode')) {
            return [
                'access_token' => 'mock_tt_refreshed_access_'.Str::random(32),
                'refresh_token' => 'mock_tt_refreshed_token_'.Str::random(32),
                'expires_in' => 86400,
            ];
        }

        $response = Http::asForm()->post("{$this->apiBase}/oauth/token/", [
            'client_key' => $this->clientKey,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('TikTok token refresh failed: '.$response->body());
        }

        return $response->json();
    }
}
