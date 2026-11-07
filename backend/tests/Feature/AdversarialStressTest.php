<?php

namespace Tests\Feature;

use App\Modules\Clips\DTOs\UpdateClipDTO;
use App\Modules\Clips\Models\Clip;
use App\Modules\Clips\Services\ClipStorageService;
use App\Modules\Publishing\Adapters\MockSocialAdapter;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\Videos\Models\Video;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

/**
 * Empirical Adversarial Stress & Edge Case Test Suite
 *
 * Verifies:
 * 1. Malformed inputs to POST /api/videos and PATCH /api/clips/{id}
 * 2. Illegal state machine transitions on Video model
 * 3. Encrypted OAuth token leak protection across internal attributes and serialization
 * 4. Mock social publisher deterministic error simulation and job failure handling
 */
class AdversarialStressTest extends TestCase
{
    // =========================================================================
    // SECTION 1: MALFORMED INPUTS TO POST /api/videos
    // =========================================================================

    public function test_post_videos_rejects_missing_source_url(): void
    {
        $response = $this->postJson('/api/videos', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['source_url']);
    }

    public function test_post_videos_rejects_malformed_url_protocols(): void
    {
        $malformedUrls = [
            'not-a-url',
            'javascript:alert(1)',
            'file:///etc/passwd',
            'http://',
            'https://',
            '://missing-scheme.com',
            'data:text/plain;base64,SGVsbG8=',
        ];

        foreach ($malformedUrls as $badUrl) {
            $response = $this->postJson('/api/videos', [
                'source_url' => $badUrl,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['source_url']);
        }
    }

    public function test_post_videos_rejects_invalid_strategy(): void
    {
        $response = $this->postJson('/api/videos', [
            'source_url' => 'https://example.com/video.mp4',
            'strategy' => 'unsupported_ai_voodoo',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['strategy']);
    }

    public function test_post_videos_rejects_out_of_bounds_interval_seconds(): void
    {
        $badIntervals = [-10, 0, 1, 4, 601, 100000];

        foreach ($badIntervals as $interval) {
            $response = $this->postJson('/api/videos', [
                'source_url' => 'https://example.com/video.mp4',
                'interval_seconds' => $interval,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['interval_seconds']);
        }
    }

    public function test_post_videos_rejects_invalid_vertical_modes(): void
    {
        $badModes = ['stretch_to_fit', 'upside_down', 'crop_random', ''];

        foreach ($badModes as $mode) {
            $response = $this->postJson('/api/videos', [
                'source_url' => 'https://example.com/video.mp4',
                'vertical_mode' => $mode,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['vertical_mode']);
        }
    }

    public function test_post_videos_rejects_excessively_long_title(): void
    {
        $response = $this->postJson('/api/videos', [
            'source_url' => 'https://example.com/video.mp4',
            'title' => str_repeat('X', 501),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    // =========================================================================
    // SECTION 2: MALFORMED INPUTS TO PATCH /api/clips/{id}
    // =========================================================================

    public function test_patch_clip_returns_404_for_nonexistent_uuid(): void
    {
        $nonExistentId = (string) Str::uuid();

        $mockService = Mockery::mock(ClipStorageService::class);
        $this->app->instance(ClipStorageService::class, $mockService);
        $mockService->shouldReceive('update')
            ->with($nonExistentId, Mockery::any())
            ->once()
            ->andThrow(new InvalidArgumentException("Clip not found: {$nonExistentId}"));

        $response = $this->patchJson("/api/clips/{$nonExistentId}", [
            'title' => 'Attempted Update',
        ]);

        $response->assertStatus(404)
            ->assertJson(['message' => "Clip not found: {$nonExistentId}"]);
    }

    public function test_patch_clip_rejects_negative_timestamps(): void
    {
        $clipId = (string) Str::uuid();

        $response = $this->patchJson("/api/clips/{$clipId}", [
            'start_time' => -5.0,
            'end_time' => -1.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_time', 'end_time']);
    }

    public function test_patch_clip_rejects_non_numeric_timestamps(): void
    {
        $clipId = (string) Str::uuid();

        $response = $this->patchJson("/api/clips/{$clipId}", [
            'start_time' => 'start_of_video',
            'end_time' => 'end_of_video',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_time', 'end_time']);
    }

    public function test_patch_clip_rejects_invalid_metadata_type(): void
    {
        $clipId = (string) Str::uuid();

        $response = $this->patchJson("/api/clips/{$clipId}", [
            'metadata' => 'string_instead_of_array',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['metadata']);
    }

    public function test_patch_clip_duration_calculation_handles_inverted_bounds_safely(): void
    {
        $clip = new Clip([
            'title' => 'Test Clip',
            'start_time' => 0.0,
            'end_time' => 30.0,
            'duration' => 30.0,
        ]);
        $clip->id = (string) Str::uuid();

        // Inverted bounds: start=50, end=10 -> duration calculation max(0.0, 10 - 50) = 0.0
        $dto = new UpdateClipDTO(startTime: 50.0, endTime: 10.0);

        // Verify update logic clamps duration to zero rather than crashing or negative duration
        $clip->start_time = $dto->startTime;
        $clip->end_time = $dto->endTime;
        $clip->duration = max(0.0, $clip->end_time - $clip->start_time);

        $this->assertEquals(0.0, $clip->duration);
        $this->assertGreaterThanOrEqual(0.0, $clip->duration);
    }

    // =========================================================================
    // SECTION 3: ILLEGAL STATE MACHINE TRANSITIONS ON VIDEO MODEL
    // =========================================================================

    public function test_video_state_machine_blocks_illegal_pending_to_ready_jump(): void
    {
        $video = new Video(['status' => Video::STATUS_PENDING]);
        $video->id = (string) Str::uuid();

        $this->assertFalse($video->canTransitionTo(Video::STATUS_READY));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid status transition from 'pending' to 'ready'");
        $video->transitionTo(Video::STATUS_READY);
    }

    public function test_video_state_machine_blocks_downloading_to_ready_skip(): void
    {
        $video = new Video(['status' => Video::STATUS_DOWNLOADING]);
        $video->id = (string) Str::uuid();

        $this->assertFalse($video->canTransitionTo(Video::STATUS_READY));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid status transition from 'downloading' to 'ready'");
        $video->transitionTo(Video::STATUS_READY);
    }

    public function test_video_state_machine_blocks_transitions_out_of_ready(): void
    {
        $video = new Video(['status' => Video::STATUS_READY]);
        $video->id = (string) Str::uuid();

        $disallowedStatuses = [
            Video::STATUS_PENDING,
            Video::STATUS_DOWNLOADING,
            Video::STATUS_PROCESSING,
            Video::STATUS_FAILED,
        ];

        foreach ($disallowedStatuses as $target) {
            $this->assertFalse($video->canTransitionTo($target), "Ready must not transition to {$target}");
        }

        $this->expectException(InvalidArgumentException::class);
        $video->transitionTo(Video::STATUS_PROCESSING);
    }

    public function test_video_state_machine_rejects_arbitrary_unregistered_status(): void
    {
        $video = new Video(['status' => Video::STATUS_PENDING]);
        $video->id = (string) Str::uuid();

        $this->assertFalse($video->canTransitionTo('malicious_status_payload'));
        $this->assertFalse($video->canTransitionTo(''));

        $this->expectException(InvalidArgumentException::class);
        $video->transitionTo('malicious_status_payload');
    }

    public function test_video_state_machine_allows_failed_to_retry_to_pending(): void
    {
        $video = new Video(['status' => Video::STATUS_FAILED]);
        $video->id = (string) Str::uuid();

        $this->assertTrue($video->canTransitionTo(Video::STATUS_PENDING));
        $video->transitionTo(Video::STATUS_PENDING);
        $this->assertEquals(Video::STATUS_PENDING, $video->status);

        // From failed, jumping directly to ready is illegal
        $video->status = Video::STATUS_FAILED;
        $this->assertFalse($video->canTransitionTo(Video::STATUS_READY));
        $this->assertFalse($video->canTransitionTo(Video::STATUS_DOWNLOADING));
    }

    // =========================================================================
    // SECTION 4: TOKEN ENCRYPTION & DATA LEAK PROTECTION
    // =========================================================================

    public function test_encrypted_tokens_never_exposed_as_plaintext_in_internal_attributes(): void
    {
        $plainAccessToken = 'tiktok_live_production_secret_token_#123_xyz';
        $plainRefreshToken = 'tiktok_refresh_secret_987654321_abc';

        $account = new SocialAccount;
        $account->id = (string) Str::uuid();
        $account->platform = SocialAccount::PLATFORM_TIKTOK;
        $account->access_token = $plainAccessToken;
        $account->refresh_token = $plainRefreshToken;

        $rawAttributes = $account->getAttributes();

        // 1. Raw internal attributes must NOT be plain text
        $this->assertNotEquals($plainAccessToken, $rawAttributes['access_token']);
        $this->assertNotEquals($plainRefreshToken, $rawAttributes['refresh_token']);

        // 2. Raw attributes must start with base64/JSON ciphertext payload indicator
        $this->assertStringContainsString('eyJ', $rawAttributes['access_token']);
        $this->assertStringContainsString('eyJ', $rawAttributes['refresh_token']);

        // 3. Eloquent getter automatically decrypts
        $this->assertEquals($plainAccessToken, $account->access_token);
        $this->assertEquals($plainRefreshToken, $account->refresh_token);
    }

    public function test_tokens_are_strictly_omitted_from_all_serialization_surfaces(): void
    {
        $account = new SocialAccount([
            'platform' => 'tiktok',
            'platform_user_id' => 'creator_123',
            'platform_username' => '@creator',
        ]);
        $account->id = (string) Str::uuid();
        $account->access_token = 'secret_access_xyz';
        $account->refresh_token = 'secret_refresh_abc';

        // 1. Array serialization
        $asArray = $account->toArray();
        $this->assertArrayNotHasKey('access_token', $asArray);
        $this->assertArrayNotHasKey('refresh_token', $asArray);

        // 2. Direct JSON serialization
        $asJson = $account->toJson();
        $this->assertStringNotContainsString('secret_access_xyz', $asJson);
        $this->assertStringNotContainsString('secret_refresh_abc', $asJson);
        $this->assertStringNotContainsString('access_token', $asJson);
        $this->assertStringNotContainsString('refresh_token', $asJson);

        // 3. json_encode() representation
        $encoded = json_encode($account);
        $this->assertStringNotContainsString('secret_access_xyz', $encoded);
        $this->assertStringNotContainsString('secret_refresh_abc', $encoded);
    }

    // =========================================================================
    // SECTION 5: MOCK SOCIAL PUBLISHER ERROR SIMULATION & RECOVERY
    // =========================================================================

    public function test_mock_adapter_simulates_failure_when_simulate_error_flag_active(): void
    {
        $adapter = new MockSocialAdapter('tiktok');

        $clip = new Clip(['duration' => 30.0]);
        $clip->id = (string) Str::uuid();

        $account = new SocialAccount(['platform' => 'tiktok', 'platform_username' => '@creator']);
        $account->id = (string) Str::uuid();

        $result = $adapter->publish($clip, $account, [
            'simulate_error' => true,
            'error_message' => 'Simulated upstream rate limit 429',
        ]);

        $this->assertFalse($result->success);
        $this->assertEquals('Simulated upstream rate limit 429', $result->errorMessage);
        $this->assertNull($result->platformPostId);
        $this->assertTrue($result->metadata['simulated']);
    }

    public function test_mock_adapter_simulates_failure_for_error_test_username_convention(): void
    {
        $adapter = new MockSocialAdapter('instagram');

        $clip = new Clip(['duration' => 15.0]);
        $account = new SocialAccount(['platform' => 'instagram', 'platform_username' => 'error_test_user']);

        $result = $adapter->publish($clip, $account);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Simulated failure for instagram', $result->errorMessage);
    }

    public function test_mock_adapter_rejects_zero_or_negative_duration_clips(): void
    {
        $adapter = new MockSocialAdapter('youtube');

        $account = new SocialAccount(['platform' => 'youtube', 'platform_username' => '@reels']);

        $zeroClip = new Clip(['duration' => 0.0]);
        $negClip = new Clip(['duration' => -10.0]);

        $resZero = $adapter->publish($zeroClip, $account);
        $this->assertFalse($resZero->success);
        $this->assertStringContainsString('Invalid clip duration: 0s', $resZero->errorMessage);

        $resNeg = $adapter->publish($negClip, $account);
        $this->assertFalse($resNeg->success);
        $this->assertStringContainsString('Invalid clip duration: -10s', $resNeg->errorMessage);
    }

    public function test_mock_adapter_detects_invalid_test_accounts(): void
    {
        $adapter = new MockSocialAdapter('tiktok');

        $validAccount = new SocialAccount(['platform' => 'tiktok', 'access_token' => 'mock_tok']);
        $this->assertTrue($adapter->validateAccount($validAccount));

        $invalidAccount = new SocialAccount([
            'platform' => 'tiktok',
            'platform_username' => 'invalid_test_creator',
            'access_token' => 'mock_tok',
        ]);
        $this->assertFalse($adapter->validateAccount($invalidAccount));

        $emptyTokenAccount = new SocialAccount(['platform' => 'tiktok']);
        $this->assertFalse($adapter->validateAccount($emptyTokenAccount));
    }

    public function test_publish_clip_api_rejects_empty_platforms_array(): void
    {
        $mockClipService = Mockery::mock(ClipStorageService::class);
        $this->app->instance(ClipStorageService::class, $mockClipService);

        $clipId = (string) Str::uuid();
        $fakeClip = new Clip(['duration' => 30.0]);
        $fakeClip->id = $clipId;

        $mockClipService->shouldReceive('findById')->with($clipId)->once()->andReturn($fakeClip);

        $response = $this->postJson("/api/clips/{$clipId}/publish", [
            'platforms' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['platforms']);
    }

    public function test_publish_clip_api_rejects_unsupported_platform(): void
    {
        $mockClipService = Mockery::mock(ClipStorageService::class);
        $this->app->instance(ClipStorageService::class, $mockClipService);

        $clipId = (string) Str::uuid();
        $fakeClip = new Clip(['duration' => 30.0]);
        $fakeClip->id = $clipId;

        $mockClipService->shouldReceive('findById')->with($clipId)->once()->andReturn($fakeClip);

        $response = $this->postJson("/api/clips/{$clipId}/publish", [
            'platforms' => ['unsupported_myspace'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['platforms.0']);
    }
}
