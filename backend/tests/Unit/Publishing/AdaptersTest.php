<?php

namespace Tests\Unit\Publishing;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Adapters\AbstractPublisherAdapter;
use App\Modules\Publishing\Adapters\InstagramPublisherAdapter;
use App\Modules\Publishing\Adapters\TikTokPublisherAdapter;
use App\Modules\Publishing\Adapters\YouTubePublisherAdapter;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\SocialAccounts\Services\InstagramOAuthService;
use App\Modules\SocialAccounts\Services\TikTokOAuthService;
use App\Modules\SocialAccounts\Services\YouTubeOAuthService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdaptersTest extends TestCase
{
    public function test_all_adapters_extend_abstract_publisher_adapter(): void
    {
        $tiktok = new TikTokPublisherAdapter(new TikTokOAuthService);
        $instagram = new InstagramPublisherAdapter(new InstagramOAuthService);
        $youtube = new YouTubePublisherAdapter(new YouTubeOAuthService);

        $this->assertInstanceOf(AbstractPublisherAdapter::class, $tiktok);
        $this->assertInstanceOf(AbstractPublisherAdapter::class, $instagram);
        $this->assertInstanceOf(AbstractPublisherAdapter::class, $youtube);
    }

    public function test_tiktok_publisher_adapter_delegates_to_mock_when_in_mock_mode(): void
    {
        config(['social.mock_mode' => true]);

        $adapter = new TikTokPublisherAdapter(new TikTokOAuthService);
        $clip = new Clip(['duration' => 20.0, 'title' => 'Dance Clip']);
        $account = new SocialAccount(['platform' => 'tiktok', 'access_token' => 'mock_token']);

        $result = $adapter->publish($clip, $account);
        $this->assertTrue($result->success);
        $this->assertStringStartsWith('mock_tiktok_', $result->platformPostId);
    }

    public function test_instagram_publisher_adapter_delegates_to_mock_when_in_mock_mode(): void
    {
        config(['social.mock_mode' => true]);

        $adapter = new InstagramPublisherAdapter(new InstagramOAuthService);
        $clip = new Clip(['duration' => 15.0, 'title' => 'Reel Moment']);
        $account = new SocialAccount(['platform' => 'instagram', 'access_token' => 'mock_token']);

        $result = $adapter->publish($clip, $account);
        $this->assertTrue($result->success);
        $this->assertStringStartsWith('mock_instagram_', $result->platformPostId);
    }

    public function test_youtube_publisher_adapter_appends_shorts_tag(): void
    {
        config(['social.mock_mode' => true]);

        $adapter = new YouTubePublisherAdapter(new YouTubeOAuthService);
        $clip = new Clip(['duration' => 45.0, 'title' => 'Top 5 Coding Tips']);
        $account = new SocialAccount(['platform' => 'youtube', 'access_token' => 'mock_token']);

        $result = $adapter->publish($clip, $account);
        $this->assertTrue($result->success);
        $this->assertStringStartsWith('mock_youtube_', $result->platformPostId);
    }

    public function test_youtube_publisher_adapter_rejects_clips_over_60_seconds(): void
    {
        $adapter = new YouTubePublisherAdapter(new YouTubeOAuthService);
        $clip = new Clip(['duration' => 61.5, 'title' => 'Long Short']);
        $account = new SocialAccount(['platform' => 'youtube', 'access_token' => 'mock_token']);

        $result = $adapter->publish($clip, $account);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('YouTube Shorts duration cannot exceed 60 seconds', $result->errorMessage);
        $this->assertStringContainsString('PlatformConstraintViolation', $result->errorMessage);
    }

    public function test_adapters_reject_non_positive_duration_clips(): void
    {
        $adapter = new TikTokPublisherAdapter(new TikTokOAuthService);
        $clip = new Clip(['duration' => 0.0]);
        $account = new SocialAccount(['platform' => 'tiktok', 'access_token' => 'mock_token']);

        $result = $adapter->publish($clip, $account);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid clip duration: 0s', $result->errorMessage);
    }

    public function test_youtube_live_flow_formats_metadata_correctly(): void
    {
        config(['social.mock_mode' => false]);
        config(['social.youtube.client_id' => 'real_id']);

        Http::fake([
            'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response([
                'id' => 'yt_video_12345',
            ], 200),
        ]);

        $adapter = new YouTubePublisherAdapter(new YouTubeOAuthService);
        $clip = new Clip(['duration' => 45.0, 'title' => 'Top 5 Coding Tips']);
        $account = new SocialAccount([
            'platform' => 'youtube',
            'access_token' => 'valid_bearer_token',
            'platform_user_id' => 'yt_channel_1',
        ]);

        $result = $adapter->publish($clip, $account, ['caption' => 'Check this out']);
        $this->assertTrue($result->success);
        $this->assertEquals('yt_video_12345', $result->platformPostId);
        $this->assertStringContainsString('#Shorts', $result->metadata['title']);
    }
}
