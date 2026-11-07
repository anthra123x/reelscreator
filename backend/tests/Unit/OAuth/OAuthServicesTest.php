<?php

namespace Tests\Unit\OAuth;

use App\Modules\SocialAccounts\Services\InstagramOAuthService;
use App\Modules\SocialAccounts\Services\TikTokOAuthService;
use App\Modules\SocialAccounts\Services\YouTubeOAuthService;
use Tests\TestCase;

class OAuthServicesTest extends TestCase
{
    public function test_tiktok_authorization_url_generation(): void
    {
        $service = new TikTokOAuthService;
        $url = $service->getAuthorizationUrl('test_state_123', 'verifier_abc');

        $this->assertStringContainsString('https://www.tiktok.com/v2/auth/authorize/', $url);
        $this->assertStringContainsString('test_state_123', $url);
        $this->assertStringContainsString('video.publish', $url);
    }

    public function test_tiktok_exchange_code_mock(): void
    {
        config(['social.mock_mode' => true]);

        $service = new TikTokOAuthService;
        $tokens = $service->exchangeCode('auth_code_xyz');

        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertArrayHasKey('refresh_token', $tokens);
        $this->assertStringStartsWith('mock_tt_', $tokens['access_token']);
    }

    public function test_instagram_authorization_url_generation(): void
    {
        $service = new InstagramOAuthService;
        $url = $service->getAuthorizationUrl('state_ig_456');

        $this->assertStringContainsString('facebook.com', $url);
        $this->assertStringContainsString('instagram_content_publish', $url);
        $this->assertStringContainsString('state_ig_456', $url);
    }

    public function test_instagram_exchange_code_mock(): void
    {
        config(['social.mock_mode' => true]);

        $service = new InstagramOAuthService;
        $tokens = $service->exchangeCode('ig_code_123');

        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertStringStartsWith('mock_ig_', $tokens['access_token']);
    }

    public function test_youtube_authorization_url_generation(): void
    {
        $service = new YouTubeOAuthService;
        $url = $service->getAuthorizationUrl('state_yt_789');

        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString('youtube.upload', $url);
        $this->assertStringContainsString('state_yt_789', $url);
    }

    public function test_youtube_exchange_code_mock(): void
    {
        config(['social.mock_mode' => true]);

        $service = new YouTubeOAuthService;
        $tokens = $service->exchangeCode('yt_code_123');

        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertStringStartsWith('mock_yt_', $tokens['access_token']);
    }
}
