<?php

namespace Tests\Feature;

use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\VideoIngestService;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ChallengerWebhookStressTest extends TestCase
{
    private string $internalSecret = 'adversarial_test_secret_key_#987654321!';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.rust_engine.internal_secret' => $this->internalSecret]);
    }

    private function createInMemoryVideo(string $status = Video::STATUS_PROCESSING): Video
    {
        $video = new Video([
            'title' => 'Challenger Stress Test Video',
            'status' => $status,
        ]);
        $video->id = (string) Str::uuid();
        $video->setRelation('clips', collect());

        return $video;
    }

    /**
     * Adversarial Forgery Test 1: Callback with completely omitted secret headers must be rejected with 401.
     */
    public function test_forged_callback_omitted_secret_header_receives_401(): void
    {
        $video = $this->createInMemoryVideo(Video::STATUS_PROCESSING);

        $response = $this->postJson("/api/internal/jobs/{$video->id}/callback", [
            'job_id' => $video->id,
            'status' => 'failed',
            'error' => 'Simulated attack payload',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized internal webhook callback',
            ]);

        $this->assertEquals(Video::STATUS_PROCESSING, $video->status);
        $this->assertNull($video->error_message);
    }

    /**
     * Adversarial Forgery Test 2: Callback with invalid X-Internal-Secret header must be rejected with 401.
     */
    public function test_forged_callback_invalid_internal_secret_receives_401(): void
    {
        $video = $this->createInMemoryVideo(Video::STATUS_PROCESSING);

        $response = $this->withHeaders([
            'X-Internal-Secret' => 'malicious_attacker_token_forged',
        ])->postJson("/api/internal/jobs/{$video->id}/callback", [
            'job_id' => $video->id,
            'status' => 'failed',
            'error' => 'Attacker trying to fail the video',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized internal webhook callback',
            ]);

        $this->assertEquals(Video::STATUS_PROCESSING, $video->status);
    }

    /**
     * Adversarial Forgery Test 3: Callback with empty string secret header must be rejected with 401.
     */
    public function test_forged_callback_empty_secret_header_receives_401(): void
    {
        $video = $this->createInMemoryVideo(Video::STATUS_PROCESSING);

        $response = $this->withHeaders([
            'X-Internal-Secret' => '',
        ])->postJson("/api/internal/jobs/{$video->id}/callback", [
            'job_id' => $video->id,
            'status' => 'failed',
            'error' => 'Empty secret test',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized internal webhook callback',
            ]);
    }

    /**
     * Adversarial Forgery Test 4: Spoofed X-Internal-Secret with valid X-Webhook-Secret must still reject.
     */
    public function test_forged_internal_secret_takes_precedence_and_rejects_even_if_webhook_secret_set(): void
    {
        $video = $this->createInMemoryVideo(Video::STATUS_PROCESSING);

        $response = $this->withHeaders([
            'X-Internal-Secret' => 'invalid_internal_secret',
            'X-Webhook-Secret' => $this->internalSecret,
        ])->postJson("/api/internal/jobs/{$video->id}/callback", [
            'job_id' => $video->id,
            'status' => 'failed',
            'error' => 'Precedence attack',
        ]);

        $response->assertStatus(401);
    }

    /**
     * Adversarial Failure Test 5: Valid secret with failure payload successfully transitions video to failed
     * executing through the real VideoIngestService logic.
     */
    public function test_valid_failure_webhook_transitions_processing_video_to_failed(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class)->makePartial();
        $this->app->instance(VideoIngestService::class, $mockService);

        $video = $this->createInMemoryVideo(Video::STATUS_PROCESSING);
        $mockService->shouldReceive('findById')->with($video->id)->andReturn($video);

        $response = $this->withHeaders([
            'X-Internal-Secret' => $this->internalSecret,
        ])->postJson("/api/internal/jobs/{$video->id}/callback", [
            'job_id' => $video->id,
            'status' => 'failed',
            'error' => 'FFmpeg exited with error code 1: Invalid data found when processing input',
            'timestamp' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'video_id' => $video->id,
                'video_status' => Video::STATUS_FAILED,
            ]);

        $this->assertEquals(Video::STATUS_FAILED, $video->status);
        $this->assertEquals(
            'FFmpeg exited with error code 1: Invalid data found when processing input',
            $video->error_message
        );
    }

    /**
     * Adversarial Failure Test 6: Valid secret with cancelled status payload transitions video to failed.
     */
    public function test_valid_cancelled_webhook_transitions_video_to_failed(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class)->makePartial();
        $this->app->instance(VideoIngestService::class, $mockService);

        $video = $this->createInMemoryVideo(Video::STATUS_DOWNLOADING);
        $mockService->shouldReceive('findById')->with($video->id)->andReturn($video);

        $response = $this->withHeaders([
            'X-Webhook-Secret' => $this->internalSecret,
        ])->postJson("/api/internal/jobs/{$video->id}/callback", [
            'job_id' => $video->id,
            'status' => 'cancelled',
            'error_message' => 'Job was cancelled during download by user request',
            'timestamp' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'video_id' => $video->id,
                'video_status' => Video::STATUS_FAILED,
            ]);

        $this->assertEquals(Video::STATUS_FAILED, $video->status);
        $this->assertEquals(
            'Job was cancelled during download by user request',
            $video->error_message
        );
    }

    /**
     * Adversarial Failure Test 7: Failure payload with omitted error strings uses fallback message.
     */
    public function test_failure_webhook_with_omitted_error_fields_uses_default_message(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class)->makePartial();
        $this->app->instance(VideoIngestService::class, $mockService);

        $video = $this->createInMemoryVideo(Video::STATUS_PENDING);
        $mockService->shouldReceive('findById')->with($video->id)->andReturn($video);

        $response = $this->withHeaders([
            'X-Internal-Secret' => $this->internalSecret,
        ])->postJson("/api/internal/jobs/{$video->id}/callback", [
            'job_id' => $video->id,
            'status' => 'failed',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'video_status' => Video::STATUS_FAILED,
            ]);

        $this->assertEquals(Video::STATUS_FAILED, $video->status);
        $this->assertEquals('Video processing failed in Rust engine', $video->error_message);
    }

    /**
     * Adversarial Failure Test 8: Non-existent video ID returns 404 Not Found.
     */
    public function test_failure_webhook_for_nonexistent_video_returns_404(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class)->makePartial();
        $this->app->instance(VideoIngestService::class, $mockService);

        $randomUuid = (string) Str::uuid();
        $mockService->shouldReceive('findById')->with($randomUuid)->andReturn(null);

        $response = $this->withHeaders([
            'X-Internal-Secret' => $this->internalSecret,
        ])->postJson("/api/internal/jobs/{$randomUuid}/callback", [
            'job_id' => $randomUuid,
            'status' => 'failed',
            'error' => 'Some error',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'message' => "Video not found: {$randomUuid}",
            ]);
    }

    /**
     * Adversarial Idempotency Test 9: Duplicate failure webhook on already-failed video is idempotent.
     */
    public function test_duplicate_failure_webhook_on_already_failed_video_is_idempotent(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class)->makePartial();
        $this->app->instance(VideoIngestService::class, $mockService);

        $video = $this->createInMemoryVideo(Video::STATUS_FAILED);
        $video->error_message = 'Initial failure message';
        $mockService->shouldReceive('findById')->with($video->id)->andReturn($video);

        $response = $this->withHeaders([
            'X-Internal-Secret' => $this->internalSecret,
        ])->postJson("/api/internal/jobs/{$video->id}/callback", [
            'job_id' => $video->id,
            'status' => 'failed',
            'error' => 'Updated retry failure message',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'video_id' => $video->id,
                'video_status' => Video::STATUS_FAILED,
            ]);

        $this->assertEquals(Video::STATUS_FAILED, $video->status);
        $this->assertEquals('Updated retry failure message', $video->error_message);
    }

    /**
     * Adversarial Integrity Test 10: Late failure webhook on already-ready video is rejected safely.
     */
    public function test_late_failure_webhook_on_ready_video_is_rejected_safely(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class)->makePartial();
        $this->app->instance(VideoIngestService::class, $mockService);

        $video = $this->createInMemoryVideo(Video::STATUS_READY);
        $mockService->shouldReceive('findById')->with($video->id)->andReturn($video);

        $response = $this->withHeaders([
            'X-Internal-Secret' => $this->internalSecret,
        ])->postJson("/api/internal/jobs/{$video->id}/callback", [
            'job_id' => $video->id,
            'status' => 'failed',
            'error' => 'Late failure delivery',
        ]);

        // Controller catches InvalidArgumentException and returns 404 with error message
        $response->assertStatus(404)
            ->assertJsonStructure(['message']);

        $this->assertEquals(Video::STATUS_READY, $video->status);
    }
}
