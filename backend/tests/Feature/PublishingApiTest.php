<?php

namespace Tests\Feature;

use App\Modules\Clips\Models\Clip;
use App\Modules\Clips\Services\ClipStorageService;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Services\PublishingOrchestrator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PublishingApiTest extends TestCase
{
    public function test_publish_clip_queues_publication_and_returns_202(): void
    {
        $mockClipService = Mockery::mock(ClipStorageService::class);
        $mockOrchestrator = Mockery::mock(PublishingOrchestrator::class);

        $this->app->instance(ClipStorageService::class, $mockClipService);
        $this->app->instance(PublishingOrchestrator::class, $mockOrchestrator);

        $clipId = (string) Str::uuid();
        $fakeClip = new Clip(['title' => 'Viral Reel']);
        $fakeClip->id = $clipId;

        $mockClipService->shouldReceive('findById')->with($clipId)->once()->andReturn($fakeClip);

        $mockOrchestrator->shouldReceive('publishClip')
            ->once()
            ->andReturn([
                'publication_ids' => [(string) Str::uuid(), (string) Str::uuid()],
                'status' => 'queued',
            ]);

        $response = $this->postJson("/api/clips/{$clipId}/publish", [
            'platforms' => ['tiktok', 'instagram'],
            'caption' => 'Awesome clip #fyp',
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['publication_ids', 'status'])
            ->assertJson(['status' => 'queued']);
    }

    public function test_publish_clip_validation_failure_returns_422(): void
    {
        $mockClipService = Mockery::mock(ClipStorageService::class);
        $this->app->instance(ClipStorageService::class, $mockClipService);

        $clipId = (string) Str::uuid();
        $fakeClip = new Clip;
        $fakeClip->id = $clipId;

        $mockClipService->shouldReceive('findById')->with($clipId)->once()->andReturn($fakeClip);

        $response = $this->postJson("/api/clips/{$clipId}/publish", [
            'platforms' => ['unsupported_social_network'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['platforms.0']);
    }

    public function test_publish_clip_returns_404_when_clip_not_found(): void
    {
        $mockClipService = Mockery::mock(ClipStorageService::class);
        $this->app->instance(ClipStorageService::class, $mockClipService);

        $clipId = (string) Str::uuid();
        $mockClipService->shouldReceive('findById')->with($clipId)->once()->andReturn(null);

        $response = $this->postJson("/api/clips/{$clipId}/publish", [
            'platforms' => ['tiktok'],
        ]);

        $response->assertStatus(404);
    }

    public function test_get_publications_returns_paginated_list(): void
    {
        $mockOrchestrator = Mockery::mock(PublishingOrchestrator::class);
        $this->app->instance(PublishingOrchestrator::class, $mockOrchestrator);

        $paginator = new LengthAwarePaginator([], 0, 15, 1);
        $mockOrchestrator->shouldReceive('listPublications')->once()->andReturn($paginator);

        $response = $this->getJson('/api/publications');
        $response->assertStatus(200);
    }

    public function test_retry_publication_returns_202_accepted(): void
    {
        $mockOrchestrator = Mockery::mock(PublishingOrchestrator::class);
        $this->app->instance(PublishingOrchestrator::class, $mockOrchestrator);

        $pubId = (string) Str::uuid();
        $publication = new Publication(['status' => Publication::STATUS_PENDING]);
        $publication->id = $pubId;

        $mockOrchestrator->shouldReceive('retryPublication')->with($pubId)->once()->andReturn($publication);

        $response = $this->postJson("/api/publications/{$pubId}/retry");
        $response->assertStatus(202)
            ->assertJson(['status' => 'queued']);
    }
}
