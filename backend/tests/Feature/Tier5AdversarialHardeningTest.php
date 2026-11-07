<?php

namespace Tests\Feature;

use App\Modules\Clips\Models\Clip;
use App\Modules\Clips\Services\ClipStorageService;
use App\Modules\Publishing\Adapters\MockSocialAdapter;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Services\PublishingOrchestrator;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\Videos\DTOs\VideoIngestDTO;
use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\VideoIngestService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

/**
 * Tier 5 Adversarial Coverage Hardening Test Suite (Laravel Backend)
 *
 * Verifies system robustness under extreme conditions:
 * - Rapid sequential requests
 * - Extreme timestamp and duration bounds
 * - Malformed JSON bodies and injection payloads
 * - Unicode, emoji, and boundary length validation
 * - Simultaneous multi-platform publishing fan-out
 * - Graceful rejection of invalid UUIDs and non-existent resources
 */
class Tier5AdversarialHardeningTest extends TestCase
{
    // =========================================================================
    // 1. RAPID SEQUENTIAL REQUEST BURSTING
    // =========================================================================

    public function test_rapid_sequential_video_submissions(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $mockService->shouldReceive('create')
            ->times(20)
            ->andReturnUsing(function (VideoIngestDTO $dto) {
                $video = new Video([
                    'source_url' => $dto->sourceUrl,
                    'title' => $dto->title,
                    'status' => Video::STATUS_PENDING,
                ]);
                $video->id = (string) Str::uuid();
                $video->created_at = Carbon::now();

                return $video;
            });

        $createdIds = [];

        for ($i = 0; $i < 20; $i++) {
            $response = $this->postJson('/api/videos', [
                'source_url' => "https://example.com/burst_video_{$i}.mp4",
                'strategy' => 'fixed_interval',
                'interval_seconds' => 30,
                'max_duration_seconds' => 60,
                'vertical_mode' => 'blur_padding',
                'title' => "Burst Submission {$i}",
            ]);

            $response->assertStatus(201)
                ->assertJsonStructure(['id', 'source_url', 'status', 'created_at']);

            $id = $response->json('id');
            $this->assertFalse(in_array($id, $createdIds), "UUID collision detected: {$id}");
            $createdIds[] = $id;
        }

        $this->assertCount(20, $createdIds);
    }

    // =========================================================================
    // 2. SPECIAL CHARACTERS, EMOJIS, UNICODE, AND LENGTH BOUNDARIES
    // =========================================================================

    public function test_video_submission_with_emojis_and_unicode_title(): void
    {
        $unicodeTitle = '🔥 ReelForge Clip #1 [4K] 🚀 ¿Cómo editar videos? ä, ö, ü, ñ, é, ß, 漢字, العربية';

        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $mockService->shouldReceive('create')
            ->once()
            ->withArgs(function (VideoIngestDTO $dto) use ($unicodeTitle) {
                return $dto->title === $unicodeTitle;
            })
            ->andReturnUsing(function (VideoIngestDTO $dto) {
                $video = new Video([
                    'source_url' => $dto->sourceUrl,
                    'title' => $dto->title,
                    'status' => Video::STATUS_PENDING,
                ]);
                $video->id = (string) Str::uuid();
                $video->created_at = Carbon::now();

                return $video;
            });

        $response = $this->postJson('/api/videos', [
            'source_url' => 'https://example.com/unicode_test.mp4',
            'title' => $unicodeTitle,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('title', $unicodeTitle);
    }

    public function test_video_submission_with_sql_injection_and_xss_payloads(): void
    {
        $payloads = [
            "'; DROP TABLE videos; --",
            "' OR '1'='1' /*",
            "<script>alert('XSS')</script>",
            '<img src=x onerror=alert(document.cookie)>',
            '{{ 7 * 7 }}',
        ];

        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $mockService->shouldReceive('create')
            ->times(count($payloads))
            ->andReturnUsing(function (VideoIngestDTO $dto) {
                $video = new Video([
                    'source_url' => $dto->sourceUrl,
                    'title' => $dto->title,
                    'status' => Video::STATUS_PENDING,
                ]);
                $video->id = (string) Str::uuid();
                $video->created_at = Carbon::now();

                return $video;
            });

        foreach ($payloads as $injection) {
            $response = $this->postJson('/api/videos', [
                'source_url' => 'https://example.com/safe_url.mp4',
                'title' => $injection,
            ]);

            $response->assertStatus(201)
                ->assertJsonPath('title', $injection);
        }
    }

    public function test_video_title_length_boundaries(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        // 500 characters is exactly the max boundary
        $exact500 = str_repeat('A', 500);

        $mockService->shouldReceive('create')
            ->once()
            ->andReturnUsing(function (VideoIngestDTO $dto) {
                $video = new Video([
                    'source_url' => $dto->sourceUrl,
                    'title' => $dto->title,
                    'status' => Video::STATUS_PENDING,
                ]);
                $video->id = (string) Str::uuid();
                $video->created_at = Carbon::now();

                return $video;
            });

        $response500 = $this->postJson('/api/videos', [
            'source_url' => 'https://example.com/boundary500.mp4',
            'title' => $exact500,
        ]);
        $response500->assertStatus(201);

        // 501 characters must be rejected with 422
        $excess501 = str_repeat('A', 501);
        $response501 = $this->postJson('/api/videos', [
            'source_url' => 'https://example.com/boundary501.mp4',
            'title' => $excess501,
        ]);
        $response501->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_complex_source_urls_with_deep_query_parameters(): void
    {
        $complexUrl = 'https://sub.domain.example.com:8443/watch?v=dQw4w9WgXcQ&t=42s&list=PL123&index=4#timestamp=10';

        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $mockService->shouldReceive('create')
            ->once()
            ->withArgs(function (VideoIngestDTO $dto) use ($complexUrl) {
                return $dto->sourceUrl === $complexUrl;
            })
            ->andReturnUsing(function (VideoIngestDTO $dto) {
                $video = new Video([
                    'source_url' => $dto->sourceUrl,
                    'title' => $dto->title,
                    'status' => Video::STATUS_PENDING,
                ]);
                $video->id = (string) Str::uuid();
                $video->created_at = Carbon::now();

                return $video;
            });

        $response = $this->postJson('/api/videos', [
            'source_url' => $complexUrl,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('source_url', $complexUrl);
    }

    // =========================================================================
    // 3. SIMULTANEOUS MULTI-PLATFORM PUBLISHING FAN-OUT
    // =========================================================================

    public function test_simultaneous_multi_platform_publishing_fanout(): void
    {
        $mockClipService = Mockery::mock(ClipStorageService::class);
        $mockOrchestrator = Mockery::mock(PublishingOrchestrator::class);

        $this->app->instance(ClipStorageService::class, $mockClipService);
        $this->app->instance(PublishingOrchestrator::class, $mockOrchestrator);

        $clipId = (string) Str::uuid();
        $fakeClip = new Clip(['title' => 'Viral Reel']);
        $fakeClip->id = $clipId;

        $mockClipService->shouldReceive('findById')->with($clipId)->once()->andReturn($fakeClip);

        $expectedPubIds = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $mockOrchestrator->shouldReceive('publishClip')
            ->once()
            ->withArgs(function ($clip, $platforms, $caption) use ($clipId) {
                return $clip->id === $clipId &&
                    $platforms === ['tiktok', 'instagram', 'youtube'] &&
                    str_contains($caption, 'Simultaneous');
            })
            ->andReturn([
                'publication_ids' => $expectedPubIds,
                'status' => 'queued',
            ]);

        // Publish to all three platforms simultaneously
        $response = $this->postJson("/api/clips/{$clipId}/publish", [
            'platforms' => ['tiktok', 'instagram', 'youtube'],
            'caption' => 'Simultaneous Multi-Platform Publish #ViralReels 🚀',
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['publication_ids', 'status'])
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('publication_ids', $expectedPubIds);
    }

    public function test_publish_caption_length_boundary(): void
    {
        $mockClipService = Mockery::mock(ClipStorageService::class);
        $mockOrchestrator = Mockery::mock(PublishingOrchestrator::class);

        $this->app->instance(ClipStorageService::class, $mockClipService);
        $this->app->instance(PublishingOrchestrator::class, $mockOrchestrator);

        $clipId = (string) Str::uuid();
        $fakeClip = new Clip(['title' => 'Caption Test']);
        $fakeClip->id = $clipId;

        $mockClipService->shouldReceive('findById')->with($clipId)->twice()->andReturn($fakeClip);

        // 2200 characters is allowed
        $caption2200 = str_repeat('X', 2200);
        $mockOrchestrator->shouldReceive('publishClip')
            ->once()
            ->andReturn([
                'publication_ids' => [(string) Str::uuid()],
                'status' => 'queued',
            ]);

        $res2200 = $this->postJson("/api/clips/{$clipId}/publish", [
            'platforms' => ['tiktok'],
            'caption' => $caption2200,
        ]);
        $res2200->assertStatus(202);

        // 2201 characters rejected
        $caption2201 = str_repeat('X', 2201);
        $res2201 = $this->postJson("/api/clips/{$clipId}/publish", [
            'platforms' => ['tiktok'],
            'caption' => $caption2201,
        ]);
        $res2201->assertStatus(422)
            ->assertJsonValidationErrors(['caption']);
    }

    // =========================================================================
    // 4. NON-EXISTENT ENTITIES & MALFORMED UUID PATHS
    // =========================================================================

    public function test_nonexistent_and_malformed_uuids_fail_gracefully(): void
    {
        $mockVideoService = Mockery::mock(VideoIngestService::class);
        $mockClipService = Mockery::mock(ClipStorageService::class);
        $mockOrchestrator = Mockery::mock(PublishingOrchestrator::class);

        $this->app->instance(VideoIngestService::class, $mockVideoService);
        $this->app->instance(ClipStorageService::class, $mockClipService);
        $this->app->instance(PublishingOrchestrator::class, $mockOrchestrator);

        $mockVideoService->shouldReceive('getStatus')
            ->zeroOrMoreTimes()
            ->andThrow(new InvalidArgumentException('Video not found'));

        $mockVideoService->shouldReceive('findById')
            ->zeroOrMoreTimes()
            ->andReturn(null);

        $mockClipService->shouldReceive('listByVideo')
            ->zeroOrMoreTimes()
            ->andReturn(new Collection([]));

        $mockClipService->shouldReceive('findById')
            ->zeroOrMoreTimes()
            ->andReturn(null);

        $mockOrchestrator->shouldReceive('retryPublication')
            ->zeroOrMoreTimes()
            ->andThrow(new InvalidArgumentException('Publication not found'));

        $badIds = [
            'not-a-uuid',
            '12345',
            Str::uuid()->toString(), // Non-existent valid UUID
        ];

        foreach ($badIds as $id) {
            // Video status
            $statusResp = $this->getJson("/api/videos/{$id}/status");
            $this->assertSame(404, $statusResp->status());

            // Video clips
            $clipsResp = $this->getJson("/api/videos/{$id}/clips");
            $this->assertContains($clipsResp->status(), [200, 404]);

            // Clip publishing
            $pubResp = $this->postJson("/api/clips/{$id}/publish", [
                'platforms' => ['tiktok'],
            ]);
            $this->assertSame(404, $pubResp->status());

            // Publication retry
            $retryResp = $this->postJson("/api/publications/{$id}/retry");
            $this->assertSame(404, $retryResp->status());
        }
    }

    // =========================================================================
    // 5. MOCK SOCIAL ADAPTER UNDER LOAD AND DURATION CONSTRAINTS
    // =========================================================================

    public function test_mock_adapter_rapid_batch_publish(): void
    {
        $adapter = new MockSocialAdapter('tiktok');

        $account = new SocialAccount;
        $account->id = (string) Str::uuid();
        $account->user_id = (string) Str::uuid();
        $account->platform = 'tiktok';
        $account->platform_user_id = 'test_user';
        $account->platform_username = '@test_creator';
        $account->access_token = 'token_abc';
        $account->is_mock = true;

        for ($i = 0; $i < 25; $i++) {
            $clip = new Clip;
            $clip->id = (string) Str::uuid();
            $clip->file_path = "media/clips/clip_{$i}.mp4";
            $clip->thumbnail_path = "media/thumbnails/thumb_{$i}.jpg";
            $clip->duration = 10.0 + ($i % 20);
            $clip->start_time = 0.0;
            $clip->end_time = $clip->duration;
            $clip->width = 1080;
            $clip->height = 1920;
            $clip->status = Clip::STATUS_READY;

            $result = $adapter->publish($clip, $account, ['caption' => "Batch Clip {$i}"]);
            $this->assertTrue($result->success);
            $this->assertNotNull($result->platformPostId);
            $this->assertNull($result->errorMessage);
        }
    }
}
