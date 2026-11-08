<?php

namespace App\Modules\SocialAccounts\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class InstagramOAuthService
{
    protected string $clientId;

    protected string $clientSecret;

    protected string $redirectUri;

    protected string $graphBase;

    public function __construct()
    {
        $this->clientId = (string) config('social.instagram.client_id', 'mock_ig_client_id');
        $this->clientSecret = (string) config('social.instagram.client_secret', 'mock_ig_client_secret');
        $this->redirectUri = (string) config('social.instagram.redirect_uri', 'https://reelscreator-api.vercel.app/api/social-accounts/callback/instagram');
        $this->graphBase = (string) config('social.instagram.graph_base', 'https://graph.facebook.com/v21.0');
    }

    public function getAuthorizationUrl(string $state, ?string $clientId = null): string
    {
        $params = http_build_query([
            'client_id' => $clientId ?: $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'instagram_basic,instagram_content_publish,pages_show_list',
            'response_type' => 'code',
            'state' => $state,
        ]);

        return "https://www.facebook.com/v21.0/dialog/oauth?{$params}";
    }

    public function exchangeCode(string $code, ?string $clientId = null, ?string $clientSecret = null): array
    {
        if (config('social.mock_mode')) {
            return [
                'access_token' => 'mock_ig_access_token_'.Str::random(32),
                'refresh_token' => 'mock_ig_refresh_token_'.Str::random(32),
                'expires_in' => 5184000, // 60 days
                'user_id' => 'mock_ig_user_'.Str::random(8),
                'username' => 'reels_creator',
            ];
        }

        $effectiveClientId = $clientId ?: $this->clientId;
        $effectiveClientSecret = $clientSecret ?: $this->clientSecret;

        $response = Http::get("{$this->graphBase}/oauth/access_token", [
            'client_id' => $effectiveClientId,
            'client_secret' => $effectiveClientSecret,
            'redirect_uri' => $this->redirectUri,
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Instagram OAuth exchange failed: '.$response->body());
        }

        return $response->json();
    }

    public function refreshToken(string $accessToken): array
    {
        if (config('social.mock_mode')) {
            return [
                'access_token' => 'mock_ig_refreshed_access_'.Str::random(32),
                'expires_in' => 5184000,
            ];
        }

        $response = Http::get("{$this->graphBase}/oauth/access_token", [
            'grant_type' => 'fb_extend_sso_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'fb_exchange_token' => $accessToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Instagram token refresh failed: '.$response->body());
        }

        return $response->json();
    }
}
