<?php

namespace Tests\Unit\Publishing;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Adapters\MockSocialAdapter;
use App\Modules\SocialAccounts\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class MockSocialAdapterTest extends TestCase
{
    protected MockSocialAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new MockSocialAdapter('tiktok');
    }

    public function test_mock_adapter_publishes_clip_deterministically_without_credentials(): void
    {
        $clip = new Clip([
            'file_path' => 'media/clips/valid_clip.mp4',
            'duration' => 25.5,
            'title' => 'Sample Reel',
        ]);
        $clip->id = (string) Str::uuid();

        $account = new SocialAccount([
            'platform' => 'tiktok',
            'platform_username' => 'creator_test',
            'platform_user_id' => 'mock_tt_123',
            'access_token' => 'mock_token',
        ]);
        $account->id = (string) Str::uuid();

        $result = $this->adapter->publish($clip, $account, ['caption' => 'Test publication #fyp']);

        $this->assertTrue($result->success);
        $this->assertNotNull($result->platformPostId);
        $this->assertStringStartsWith('mock_tiktok_', $result->platformPostId);
        $this->assertNull($result->errorMessage);
        $this->assertTrue($result->metadata['simulated']);
    }

    public function test_mock_adapter_rejects_zero_duration_clip(): void
    {
        $clip = new Clip(['duration' => 0.0]);
        $account = new SocialAccount(['platform' => 'instagram']);

        $result = $this->adapter->publish($clip, $account);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Invalid clip duration', $result->errorMessage);
    }

    public function test_mock_adapter_simulates_error_when_flag_present(): void
    {
        $clip = new Clip(['duration' => 30.0]);
        $account = new SocialAccount(['platform' => 'youtube']);

        $result = $this->adapter->publish($clip, $account, [
            'simulate_error' => true,
            'error_message' => 'Simulated YouTube quota limit exceeded',
        ]);

        $this->assertFalse($result->success);
        $this->assertEquals('Simulated YouTube quota limit exceeded', $result->errorMessage);
    }

    public function test_mock_adapter_refresh_token_and_validate_account(): void
    {
        $account = new SocialAccount([
            'platform' => 'tiktok',
            'access_token' => 'old_mock_token',
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $this->assertTrue($this->adapter->validateAccount($account));
        $this->assertTrue($this->adapter->refreshToken($account));
        $this->assertNotEquals('old_mock_token', $account->access_token);
        $this->assertTrue($account->expires_at->isFuture());
    }

    public function test_mock_adapter_simulates_chunking(): void
    {
        $clip = new Clip(['duration' => 45.0, 'title' => 'Chunked Video']);
        $account = new SocialAccount(['platform' => 'tiktok', 'access_token' => 'mock_token']);

        $result = $this->adapter->publish($clip, $account, [
            'simulate_chunking' => true,
            'chunk_size' => 2097152, // 2MB
            'total_chunks' => 4,
        ]);

        $this->assertTrue($result->success);
        $this->assertTrue($result->metadata['chunked_upload']);
        $this->assertSame(4, $result->metadata['total_chunks']);
        $this->assertSame(2097152, $result->metadata['chunk_size']);
        $this->assertCount(4, $result->metadata['chunks']);
        $this->assertSame(1, $result->metadata['chunks'][0]['chunk_index']);
        $this->assertSame(2097152, $result->metadata['chunks'][0]['bytes_uploaded']);
        $this->assertSame(8388608, $result->metadata['chunks'][3]['bytes_uploaded']);
    }

    public function test_mock_adapter_simulates_token_expired(): void
    {
        $clip = new Clip(['duration' => 30.0]);
        $account = new SocialAccount(['platform' => 'tiktok', 'access_token' => 'mock_token']);

        $result = $this->adapter->publish($clip, $account, [
            'simulate_token_expired' => true,
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('TokenExpiredException', $result->errorMessage);
        $this->assertSame('token_expired', $result->metadata['error_type']);
    }

    public function test_mock_adapter_simulates_network_failure(): void
    {
        $clip = new Clip(['duration' => 25.0]);
        $account = new SocialAccount(['platform' => 'instagram', 'access_token' => 'mock_token']);

        $result = $this->adapter->publish($clip, $account, [
            'simulate_network_failure' => true,
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Simulated network failure', $result->errorMessage);
        $this->assertTrue($result->metadata['retryable']);
    }

    public function test_mock_adapter_enforces_youtube_shorts_duration_cap(): void
    {
        $adapter = new MockSocialAdapter('youtube');
        $account = new SocialAccount(['platform' => 'youtube', 'access_token' => 'mock_token']);

        // 75.0s clip exceeds 60s limit
        $clipExceeding = new Clip(['duration' => 75.0]);
        $result = $adapter->publish($clipExceeding, $account);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('YouTube Shorts duration cannot exceed 60 seconds', $result->errorMessage);
        $this->assertStringContainsString('PlatformConstraintViolation', $result->errorMessage);

        // 60.0s clip exactly at boundary is accepted
        $clipBoundary = new Clip(['duration' => 60.0]);
        $resultOk = $adapter->publish($clipBoundary, $account);
        $this->assertTrue($resultOk->success);
    }

    public function test_mock_adapter_enforces_instagram_reels_duration_cap(): void
    {
        $adapter = new MockSocialAdapter('instagram');
        $account = new SocialAccount(['platform' => 'instagram', 'access_token' => 'mock_token']);

        // 100.0s clip exceeds 90s limit
        $clipExceeding = new Clip(['duration' => 100.0]);
        $result = $adapter->publish($clipExceeding, $account);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Instagram Reels duration cannot exceed 90 seconds', $result->errorMessage);
        $this->assertStringContainsString('PlatformConstraintViolation', $result->errorMessage);

        // 90.0s clip is accepted
        $clipBoundary = new Clip(['duration' => 90.0]);
        $resultOk = $adapter->publish($clipBoundary, $account);
        $this->assertTrue($resultOk->success);
    }

    public function test_mock_adapter_handles_token_expiration_with_auto_refresh(): void
    {
        $clip = new Clip(['duration' => 15.0]);
        $account = new SocialAccount([
            'platform' => 'tiktok',
            'access_token' => 'expired_token',
            'expires_at' => Carbon::now()->subMinutes(10),
        ]);

        $this->assertTrue($account->isExpired());

        // Default behavior: automatically refreshes token and succeeds
        $result = $this->adapter->publish($clip, $account);
        $this->assertTrue($result->success);
        $this->assertNotEquals('expired_token', $account->access_token);
        $this->assertFalse($account->isExpired());

        // When skip_token_refresh option is passed, returns TokenExpiredException
        $account->expires_at = Carbon::now()->subMinutes(10);
        $resultSkipped = $this->adapter->publish($clip, $account, ['skip_token_refresh' => true]);
        $this->assertFalse($resultSkipped->success);
        $this->assertStringContainsString('TokenExpiredException', $resultSkipped->errorMessage);
    }

    public function test_mock_adapter_handles_failed_token_refresh(): void
    {
        $clip = new Clip(['duration' => 15.0]);
        $account = new SocialAccount([
            'platform' => 'tiktok',
            'platform_username' => 'fail_refresh_user',
            'access_token' => 'expired_token',
            'expires_at' => Carbon::now()->subMinutes(10),
        ]);

        $result = $this->adapter->publish($clip, $account);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('OAuth access token has expired and refresh failed', $result->errorMessage);
    }
}
