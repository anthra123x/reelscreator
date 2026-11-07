<?php

namespace Tests\Feature;

use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\VideoIngestService;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class InternalWebhookAuthAndFailureTest extends TestCase
{
    public function test_internal_webhook_auth_success_with_internal_secret_header(): void
    {
        config(['services.rust_engine.internal_secret' => 'super_secret_token_123']);

        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $id = (string) Str::uuid();
        $video = new Video(['status' => Video::STATUS_READY]);
        $video->id = $id;
        $video->setRelation('clips', collect());

        $mockService->shouldReceive('handleWebhookCallback')
            ->with($id, Mockery::any())
            ->once()
            ->andReturn($video);

        $response = $this->withHeaders([
            'X-Internal-Secret' => 'super_secret_token_123',
        ])->postJson("/api/internal/jobs/{$id}/callback", [
            'job_id' => $id,
            'status' => 'completed',
            'clips' => [],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'video_id' => $video->id,
                'video_status' => Video::STATUS_READY,
            ]);
    }

    public function test_internal_webhook_auth_success_with_webhook_secret_header(): void
    {
        config(['services.rust_engine.internal_secret' => 'super_secret_token_123']);

        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $id = (string) Str::uuid();
        $video = new Video(['status' => Video::STATUS_READY]);
        $video->id = $id;
        $video->setRelation('clips', collect());

        $mockService->shouldReceive('handleWebhookCallback')
            ->with($id, Mockery::any())
            ->once()
            ->andReturn($video);

        $response = $this->withHeaders([
            'X-Webhook-Secret' => 'super_secret_token_123',
        ])->postJson("/api/internal/jobs/{$id}/callback", [
            'job_id' => $id,
            'status' => 'completed',
            'clips' => [],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'video_id' => $video->id,
            ]);
    }

    public function test_internal_webhook_auth_rejects_missing_secret(): void
    {
        config(['services.rust_engine.internal_secret' => 'super_secret_token_123']);

        $id = (string) Str::uuid();
        $response = $this->postJson("/api/internal/jobs/{$id}/callback", [
            'job_id' => $id,
            'status' => 'completed',
            'clips' => [],
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized internal webhook callback',
            ]);
    }

    public function test_internal_webhook_auth_rejects_invalid_secret(): void
    {
        config(['services.rust_engine.internal_secret' => 'super_secret_token_123']);

        $id = (string) Str::uuid();
        $response = $this->withHeaders([
            'X-Internal-Secret' => 'wrong_secret_value',
        ])->postJson("/api/internal/jobs/{$id}/callback", [
            'job_id' => $id,
            'status' => 'completed',
            'clips' => [],
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Unauthorized internal webhook callback',
            ]);
    }

    public function test_internal_webhook_reconciliation_handles_failed_status(): void
    {
        config(['services.rust_engine.internal_secret' => null]);

        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $id = (string) Str::uuid();
        $video = new Video([
            'status' => Video::STATUS_FAILED,
            'error_message' => 'FFmpeg cutter crashed with SIGKILL',
        ]);
        $video->id = $id;
        $video->setRelation('clips', collect());

        $mockService->shouldReceive('handleWebhookCallback')
            ->with($id, Mockery::on(function ($payload) {
                return ($payload['status'] ?? '') === 'failed'
                    && ($payload['error'] ?? '') === 'FFmpeg cutter crashed with SIGKILL';
            }))
            ->once()
            ->andReturn($video);

        $response = $this->postJson("/api/internal/jobs/{$video->id}/callback", [
            'job_id' => $video->id,
            'status' => 'failed',
            'error' => 'FFmpeg cutter crashed with SIGKILL',
            'timestamp' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'video_id' => $video->id,
                'video_status' => Video::STATUS_FAILED,
            ]);
    }

    public function test_video_ingest_service_reconciliation_logic_transitions_video(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class)->makePartial();

        $video = new Video(['status' => Video::STATUS_PROCESSING]);
        $video->id = (string) Str::uuid();

        $mockService->shouldReceive('findById')->with($video->id)->andReturn($video);

        $result = $mockService->handleWebhookCallback($video->id, [
            'status' => 'failed',
            'error' => 'yt-dlp download timed out after 180 seconds',
        ]);

        $this->assertEquals(Video::STATUS_FAILED, $result->status);
        $this->assertEquals('yt-dlp download timed out after 180 seconds', $result->error_message);
    }
}
