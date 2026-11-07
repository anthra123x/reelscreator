<?php

namespace Tests\Unit\Publishing;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Adapters\InstagramPublisherAdapter;
use App\Modules\Publishing\Adapters\MockSocialAdapter;
use App\Modules\Publishing\Adapters\TikTokPublisherAdapter;
use App\Modules\Publishing\Adapters\YouTubePublisherAdapter;
use App\Modules\Publishing\Services\PublishingOrchestrator;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\SocialAccounts\Services\InstagramOAuthService;
use App\Modules\SocialAccounts\Services\TikTokOAuthService;
use App\Modules\SocialAccounts\Services\YouTubeOAuthService;
use InvalidArgumentException;
use Tests\TestCase;

class PublishingOrchestratorTest extends TestCase
{
    protected PublishingOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orchestrator = new PublishingOrchestrator(
            new TikTokPublisherAdapter(new TikTokOAuthService),
            new InstagramPublisherAdapter(new InstagramOAuthService),
            new YouTubePublisherAdapter(new YouTubeOAuthService)
        );
    }

    public function test_get_adapter_resolves_mock_adapter_when_in_mock_mode(): void
    {
        config(['social.mock_mode' => true]);

        $account = new SocialAccount(['platform' => 'tiktok']);
        $adapter = $this->orchestrator->getAdapter($account);

        $this->assertInstanceOf(MockSocialAdapter::class, $adapter);
    }

    public function test_publish_clip_throws_exception_if_no_platforms_provided(): void
    {
        $clip = new Clip;
        $this->expectException(InvalidArgumentException::class);

        $this->orchestrator->publishClip($clip, []);
    }
}
