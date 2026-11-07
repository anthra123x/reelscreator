<?php

namespace Tests\Feature;

use App\Modules\Clips\Models\Clip;
use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\VideoIngestService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class VideosApiTest extends TestCase
{
    public function test_post_videos_validates_and_returns_201_created(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $fakeVideo = new Video([
            'source_url' => 'https://example.com/source.mp4',
            'title' => 'Sample Ingest',
            'status' => Video::STATUS_PENDING,
        ]);
        $fakeVideo->id = (string) Str::uuid();
        $fakeVideo->created_at = Carbon::now();

        $mockService->shouldReceive('create')
            ->once()
            ->andReturn($fakeVideo);

        $response = $this->postJson('/api/videos', [
            'source_url' => 'https://example.com/source.mp4',
            'strategy' => 'fixed_interval',
            'interval_seconds' => 30,
            'vertical_mode' => 'blur_padding',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'source_url',
                'status',
                'created_at',
            ])
            ->assertJson([
                'id' => $fakeVideo->id,
                'source_url' => 'https://example.com/source.mp4',
                'status' => 'pending',
            ]);
    }

    public function test_post_videos_validation_failure_returns_422(): void
    {
        $response = $this->postJson('/api/videos', [
            'source_url' => 'invalid-url',
            'strategy' => 'nonexistent_strategy',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['source_url', 'strategy']);
    }

    public function test_get_videos_returns_paginated_list(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $paginator = new LengthAwarePaginator([], 0, 15, 1);
        $mockService->shouldReceive('list')->once()->andReturn($paginator);

        $response = $this->getJson('/api/videos');
        $response->assertStatus(200);
    }

    public function test_get_video_by_id_returns_details(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $video = new Video(['source_url' => 'https://example.com/v.mp4', 'status' => 'ready']);
        $video->id = (string) Str::uuid();

        $mockService->shouldReceive('findById')->with($video->id)->once()->andReturn($video);

        $response = $this->getJson("/api/videos/{$video->id}");
        $response->assertStatus(200)
            ->assertJson(['id' => $video->id, 'status' => 'ready']);
    }

    public function test_get_video_by_id_returns_404_when_missing(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $mockService->shouldReceive('findById')->once()->andReturn(null);

        $response = $this->getJson('/api/videos/'.Str::uuid());
        $response->assertStatus(404);
    }

    public function test_get_video_status_returns_fast_polling_payload(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $id = (string) Str::uuid();
        $mockService->shouldReceive('getStatus')->with($id)->once()->andReturn([
            'id' => $id,
            'status' => 'processing',
            'progress_percent' => 65,
            'error_message' => null,
        ]);

        $response = $this->getJson("/api/videos/{$id}/status");
        $response->assertStatus(200)
            ->assertJson([
                'id' => $id,
                'status' => 'processing',
                'progress_percent' => 65,
                'error_message' => null,
            ]);
    }

    public function test_delete_video_returns_204_no_content(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $id = (string) Str::uuid();
        $mockService->shouldReceive('delete')->with($id)->once()->andReturn(true);

        $response = $this->deleteJson("/api/videos/{$id}");
        $response->assertStatus(204);
    }

    public function test_webhook_callback_returns_ok(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $id = (string) Str::uuid();
        $video = new Video(['status' => Video::STATUS_READY]);
        $video->id = $id;
        $video->setRelation('clips', collect([new Clip, new Clip]));

        $mockService->shouldReceive('handleWebhookCallback')->with($id, Mockery::any())->once()->andReturn($video);

        $response = $this->postJson("/api/internal/jobs/{$id}/callback", [
            'job_id' => $id,
            'status' => 'completed',
            'clips' => [],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'video_id' => $id,
                'clips_count' => 2,
            ]);
    }
}
