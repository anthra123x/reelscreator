<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Models\Publication;
use App\Modules\SocialAccounts\Services\SocialAccountService;
use App\Modules\Videos\DTOs\VideoIngestDTO;
use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\VideoIngestService;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Str;
use Mockery;
use PDO;
use Tests\TestCase;

class AutonomousDecompositionTest extends TestCase
{
    private object $mockConn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.mock_mode' => true]);
        $this->mockConn = $this->setupMockPostgresConnection();
    }

    private function setupMockPostgresConnection(array $initialTables = []): object
    {
        $pdoMock = new class extends PDO
        {
            public function __construct() {}
        };

        $mockConn = new class($pdoMock, 'reelforge', '', ['server_version' => '16.0']) extends PostgresConnection
        {
            public array $tables = [
                'users' => [],
                'videos' => [],
                'clips' => [],
                'social_accounts' => [],
                'publications' => [],
                'publishing_logs' => [],
            ];

            public function select($query, $bindings = [], $useReadPdo = true): array
            {
                if (preg_match('/from\s+"?([a-z_]+)"?/i', $query, $m)) {
                    $table = $m[1];
                    if (isset($this->tables[$table])) {
                        $rows = $this->tables[$table];

                        // Handle where in
                        if (preg_match('/where\s+"?([a-z_]+)"?\s+in\s*\(([^)]+)\)/i', $query, $inMatch)) {
                            $inCol = $inMatch[1];
                            $inVals = $bindings;
                            $filtered = array_filter($rows, fn ($r) => in_array($r[$inCol] ?? null, $inVals, false));

                            return array_values($filtered);
                        }

                        if (str_contains($query, 'where') && ! empty($bindings)) {
                            $filtered = [];
                            foreach ($rows as $row) {
                                $match = true;
                                foreach ($bindings as $b) {
                                    if (! in_array($b, array_values($row), false)) {
                                        $match = false;
                                        break;
                                    }
                                }
                                if ($match) {
                                    $filtered[] = $row;
                                }
                            }
                            if (str_contains($query, 'limit 1')) {
                                return ! empty($filtered) ? [reset($filtered)] : [];
                            }

                            return array_values($filtered);
                        }
                        if (str_contains($query, 'limit 1')) {
                            return ! empty($rows) ? [reset($rows)] : [];
                        }

                        return array_values($rows);
                    }
                }

                return [];
            }

            public function insert($query, $bindings = []): bool
            {
                if (preg_match('/insert into\s+"?([a-z_]+)"?\s*\(([^)]+)\)\s*values/i', $query, $m)) {
                    $table = $m[1];
                    $cols = array_map(fn ($c) => trim(str_replace('"', '', $c)), explode(',', $m[2]));
                    $row = [];
                    foreach ($cols as $idx => $col) {
                        $row[$col] = $bindings[$idx] ?? null;
                    }
                    if (empty($row['id'])) {
                        $row['id'] = (string) Str::uuid();
                    }
                    $this->tables[$table][] = $row;
                }

                return true;
            }

            public function update($query, $bindings = []): int
            {
                if (preg_match('/update\s+"?([a-z_]+)"?\s+set\s+(.+?)(?:\s+where\s+(.+))?$/i', $query, $m)) {
                    $table = $m[1];
                    if (isset($this->tables[$table]) && ! empty($this->tables[$table])) {
                        $lastIdx = count($this->tables[$table]) - 1;
                        if ($lastIdx >= 0 && ! empty($bindings)) {
                            $setParts = explode(',', $m[2]);
                            $bIdx = 0;
                            foreach ($setParts as $part) {
                                if (preg_match('/"([^"]+)"\s*=/i', trim($part), $cm)) {
                                    $this->tables[$table][$lastIdx][$cm[1]] = $bindings[$bIdx] ?? null;
                                    $bIdx++;
                                }
                            }
                        }
                    }
                }

                return 1;
            }

            public function delete($query, $bindings = []): int
            {
                if (preg_match('/delete from\s+"?([a-z_]+)"?/i', $query, $m)) {
                    $table = $m[1];
                    if (isset($this->tables[$table])) {
                        $count = count($this->tables[$table]);
                        $this->tables[$table] = [];

                        return $count;
                    }
                }

                return 1;
            }
        };

        foreach ($initialTables as $table => $rows) {
            $mockConn->tables[$table] = $rows;
        }

        $this->app['config']->set('database.default', 'pgsql');
        $this->app['db']->setDefaultConnection('pgsql');
        $this->app['db']->purge('pgsql');
        $this->app['db']->purge('sqlite');
        $this->app['db']->extend('pgsql', fn () => $mockConn);
        $this->app['db']->extend('sqlite', fn () => $mockConn);

        return $mockConn;
    }

    /**
     * Test 1: POST /api/videos/{id}/decompose endpoint generates 9:16 clips (1080x1920)
     * and dispatches publications to connected accounts with HTTP 200.
     */
    public function test_post_videos_id_decompose_endpoint_returns_200_with_expected_payload(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $videoId = (string) Str::uuid();
        $fakeVideo = new Video([
            'source_url' => 'https://example.com/interview.mp4',
            'title' => 'Autonomous Interview',
            'status' => Video::STATUS_READY,
        ]);
        $fakeVideo->id = $videoId;

        $fakeClips = collect([
            new Clip([
                'video_id' => $videoId,
                'title' => 'Autonomous Interview - Reel #1',
                'start_time' => 0.0,
                'end_time' => 30.0,
                'duration' => 30.0,
                'status' => Clip::STATUS_READY,
                'metadata' => ['width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16'],
            ]),
            new Clip([
                'video_id' => $videoId,
                'title' => 'Autonomous Interview - Reel #2',
                'start_time' => 30.0,
                'end_time' => 60.0,
                'duration' => 30.0,
                'status' => Clip::STATUS_READY,
                'metadata' => ['width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16'],
            ]),
        ]);

        $fakePublications = collect([
            new Publication(['clip_id' => (string) Str::uuid(), 'status' => 'pending']),
            new Publication(['clip_id' => (string) Str::uuid(), 'status' => 'pending']),
        ]);

        $mockService->shouldReceive('findById')->with($videoId)->once()->andReturn($fakeVideo);
        $mockService->shouldReceive('decomposeVideo')
            ->once()
            ->andReturn([
                'video' => $fakeVideo,
                'clips' => $fakeClips,
                'publications' => $fakePublications,
                'published_platforms' => ['tiktok', 'instagram', 'youtube'],
                'status' => 'ready',
            ]);

        $response = $this->postJson("/api/videos/{$videoId}/decompose", [
            'auto_publish' => true,
            'platforms' => ['tiktok', 'instagram', 'youtube'],
            'caption' => 'Key insights #tech',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'video' => ['id', 'source_url', 'title', 'status'],
                'clips' => [
                    '*' => ['title', 'start_time', 'end_time', 'duration', 'metadata'],
                ],
                'publications',
                'published_platforms',
                'status',
            ])
            ->assertJson([
                'message' => 'Video decomposed and reels dispatched for publication',
                'status' => 'ready',
                'published_platforms' => ['tiktok', 'instagram', 'youtube'],
            ]);

        $data = $response->json();
        $this->assertCount(2, $data['clips']);
        foreach ($data['clips'] as $clip) {
            $this->assertEquals(1080, $clip['metadata']['width']);
            $this->assertEquals(1920, $clip['metadata']['height']);
            $this->assertEquals('9:16', $clip['metadata']['aspect_ratio']);
            $this->assertLessThanOrEqual(60.0, $clip['duration']);
        }
    }

    /**
     * Test 2: POST /api/videos/decompose ingests and decomposes video in one call with HTTP 201.
     */
    public function test_post_videos_decompose_single_call_returns_201(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $fakeVideo = new Video([
            'source_url' => 'https://example.com/keynote.mp4',
            'title' => 'Keynote Speech',
            'status' => Video::STATUS_READY,
        ]);
        $fakeVideo->id = (string) Str::uuid();

        $fakeClips = collect([
            new Clip([
                'video_id' => $fakeVideo->id,
                'title' => 'Keynote Speech - Reel #1',
                'start_time' => 0.0,
                'end_time' => 30.0,
                'duration' => 30.0,
                'status' => Clip::STATUS_READY,
                'metadata' => ['width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16'],
            ]),
        ]);

        $fakePublications = collect([
            new Publication(['clip_id' => (string) Str::uuid(), 'status' => 'pending']),
        ]);

        $mockService->shouldReceive('create')->once()->andReturn($fakeVideo);
        $mockService->shouldReceive('decomposeVideo')->once()->andReturn([
            'video' => $fakeVideo,
            'clips' => $fakeClips,
            'publications' => $fakePublications,
            'published_platforms' => ['tiktok', 'instagram'],
            'status' => 'ready',
        ]);

        $response = $this->postJson('/api/videos/decompose', [
            'source_url' => 'https://example.com/keynote.mp4',
            'title' => 'Keynote Speech',
            'strategy' => 'fixed_interval',
            'interval_seconds' => 30,
            'vertical_mode' => 'blur_padding',
            'auto_publish' => true,
            'platforms' => ['tiktok', 'instagram'],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'video',
                'clips',
                'publications',
                'published_platforms',
                'status',
            ])
            ->assertJson([
                'message' => 'Video decomposed and reels dispatched for publication',
                'status' => 'ready',
                'published_platforms' => ['tiktok', 'instagram'],
            ]);
    }

    /**
     * Test 3: Auto-publish flag on/off behavior in HTTP endpoints.
     */
    public function test_auto_publish_flag_disabled_returns_empty_publications(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $videoId = (string) Str::uuid();
        $fakeVideo = new Video(['source_url' => 'https://example.com/draft.mp4', 'status' => Video::STATUS_READY]);
        $fakeVideo->id = $videoId;

        $fakeClips = collect([
            new Clip([
                'video_id' => $videoId,
                'title' => 'Draft - Reel #1',
                'start_time' => 0.0,
                'end_time' => 30.0,
                'duration' => 30.0,
                'status' => Clip::STATUS_READY,
                'metadata' => ['width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16'],
            ]),
        ]);

        $mockService->shouldReceive('findById')->with($videoId)->once()->andReturn($fakeVideo);
        $mockService->shouldReceive('decomposeVideo')->once()->andReturn([
            'video' => $fakeVideo,
            'clips' => $fakeClips,
            'publications' => [],
            'published_platforms' => [],
            'status' => 'ready',
        ]);

        $response = $this->postJson("/api/videos/{$videoId}/decompose", [
            'auto_publish' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'ready',
                'publications' => [],
                'published_platforms' => [],
            ]);
    }

    /**
     * Test 4: Decompose endpoint returns 404 for nonexistent video.
     */
    public function test_decompose_nonexistent_video_returns_404(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $missingId = (string) Str::uuid();
        $mockService->shouldReceive('findById')->with($missingId)->once()->andReturn(null);

        $response = $this->postJson("/api/videos/{$missingId}/decompose");
        $response->assertStatus(404)
            ->assertJson(['message' => 'Video not found']);
    }

    /**
     * Test 5: Validation errors return 422 for malformed parameters.
     */
    public function test_decompose_validation_failures_return_422(): void
    {
        $response = $this->postJson('/api/videos/decompose', [
            'source_url' => 'invalid-protocol://path',
            'platforms' => ['myspace'],
            'interval_seconds' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['source_url', 'platforms.0', 'interval_seconds']);
    }

    /**
     * Test 6: VideoIngestService::decomposeVideo genuine 9:16 slicing logic with uniform intervals and max duration <= 60s.
     */
    public function test_service_decompose_video_generates_9x16_clips_and_respects_shorts_cap(): void
    {
        $user = new User(['name' => 'Test User', 'email' => 'service-test@reelforge.internal']);
        $user->id = (string) Str::uuid();

        $video = new Video([
            'source_url' => 'https://example.com/full-podcast.mp4',
            'title' => 'Full Podcast',
            'status' => Video::STATUS_PENDING,
            'metadata' => [
                'interval_seconds' => 75, // Requesting 75s
                'max_duration_seconds' => 90, // Exceeds 60s
                'vertical_mode' => 'blur_padding',
                'auto_publish' => false,
            ],
        ]);
        $video->id = (string) Str::uuid();
        $video->user_id = $user->id;

        $service = app(VideoIngestService::class);
        $result = $service->decomposeVideo($video, [
            'video_duration' => 180.0,
            'auto_publish' => false,
        ]);

        $this->assertEquals(Video::STATUS_READY, $result['status']);
        $this->assertNotEmpty($result['clips']);

        // Every slice must be capped at <= 60.0s for YouTube Shorts compliance
        foreach ($result['clips'] as $clip) {
            $this->assertLessThanOrEqual(60.0, $clip->duration, 'Clips must never exceed 60.0s for YouTube Shorts compliance');
            $this->assertGreaterThan(0.0, $clip->duration);
            $this->assertEquals(1080, $clip->metadata['width']);
            $this->assertEquals(1920, $clip->metadata['height']);
            $this->assertEquals('9:16', $clip->metadata['aspect_ratio']);
            $this->assertEquals(Clip::STATUS_READY, $clip->status);
        }

        // Verify uniform intervals: start times should increase by sliceDuration (60.0s)
        $this->assertEquals(0.0, $result['clips'][0]->start_time);
        $this->assertEquals(60.0, $result['clips'][1]->start_time);
        $this->assertEquals(120.0, $result['clips'][2]->start_time);
    }

    /**
     * Test 7: SocialAccountService::getActivePlatforms returns connected or supported fallback.
     */
    public function test_social_account_service_get_active_platforms(): void
    {
        $service = app(SocialAccountService::class);
        $userId = (string) Str::uuid();

        // When no accounts connected, defaults to ['tiktok', 'instagram', 'youtube']
        $defaultPlatforms = $service->getActivePlatforms($userId);
        $this->assertEquals(['tiktok', 'instagram', 'youtube'], $defaultPlatforms);
    }

    /**
     * Test 8: VideoIngestDTO parses auto_publish, platforms, and caption.
     */
    public function test_video_ingest_dto_parses_autonomous_attributes(): void
    {
        $dto = VideoIngestDTO::fromArray([
            'source_url' => 'https://example.com/vid.mp4',
            'auto_publish' => true,
            'platforms' => ['tiktok', 'youtube'],
            'caption' => 'Auto caption test',
        ]);

        $this->assertTrue($dto->autoPublish);
        $this->assertEquals(['tiktok', 'youtube'], $dto->platforms);
        $this->assertEquals('Auto caption test', $dto->caption);

        $array = $dto->toArray();
        $this->assertTrue($array['auto_publish']);
        $this->assertEquals(['tiktok', 'youtube'], $array['platforms']);
        $this->assertEquals('Auto caption test', $array['caption']);

        // Test false flag
        $dtoDisabled = VideoIngestDTO::fromArray([
            'source_url' => 'https://example.com/vid.mp4',
            'auto_publish' => false,
        ]);
        $this->assertFalse($dtoDisabled->autoPublish);
    }
}
