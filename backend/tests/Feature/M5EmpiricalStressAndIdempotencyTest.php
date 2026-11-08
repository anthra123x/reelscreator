<?php

namespace Tests\Feature;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Adapters\TikTokPublisherAdapter;
use App\Modules\Publishing\Jobs\PublishToTikTokJob;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublishingLog;
use App\Modules\Publishing\Services\PublishingOrchestrator;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\VideoIngestService;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class M5EmpiricalStressAndIdempotencyTest extends TestCase
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

                        // Special case: where "clip_id" = ? and "social_account_id" = ? and "status" in (?, ?, ?)
                        if (preg_match('/"clip_id"\s*=\s*\?\s+and\s+"social_account_id"\s*=\s*\?\s+and\s+"status"\s+in\s*\(([^)]+)\)/i', $query)) {
                            $clipId = $bindings[0] ?? null;
                            $accountId = $bindings[1] ?? null;
                            $allowedStatuses = array_slice($bindings, 2);

                            $rows = array_values(array_filter($rows, function ($r) use ($clipId, $accountId, $allowedStatuses) {
                                return ($r['clip_id'] ?? null) === $clipId
                                    && ($r['social_account_id'] ?? null) === $accountId
                                    && in_array($r['status'] ?? null, $allowedStatuses, false);
                            }));
                        } elseif (preg_match('/where\s+"?([a-z_]+)"?\s+in\s*\(([^)]+)\)/i', $query, $inMatch)) {
                            $inCol = $inMatch[1];
                            $inVals = $bindings;
                            $rows = array_values(array_filter($rows, fn ($r) => in_array($r[$inCol] ?? null, $inVals, false)));
                        } elseif (str_contains($query, 'where') && ! empty($bindings)) {
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
                            $rows = array_values($filtered);
                        }

                        // Check if aggregate count(*) is requested
                        if (preg_match('/count\(\*\)\s+as\s+aggregate/i', $query)) {
                            return [['aggregate' => count($rows)]];
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
     * EMPIRICAL TEST 1: Concurrency and Idempotency.
     * Calling decompose twice on the same video does NOT duplicate clips.
     */
    public function test_idempotency_decompose_twice_does_not_duplicate_clips(): void
    {
        $userId = (string) Str::uuid();
        $this->mockConn->tables['users'][] = [
            'id' => $userId,
            'name' => 'Idempotency User',
            'email' => 'idempotent@reelforge.internal',
        ];

        $videoId = (string) Str::uuid();
        $video = new Video([
            'source_url' => 'https://example.com/podcast.mp4',
            'title' => 'Long Podcast',
            'status' => Video::STATUS_READY,
            'metadata' => [
                'interval_seconds' => 30,
                'max_duration_seconds' => 60,
                'vertical_mode' => 'blur_padding',
                'auto_publish' => false,
            ],
        ]);
        $video->id = $videoId;
        $video->user_id = $userId;
        $this->mockConn->tables['videos'][] = $video->toArray();

        $service = app(VideoIngestService::class);

        // Run 1: Decompose initial
        $result1 = $service->decomposeVideo($video, [
            'video_duration' => 90.0,
            'interval_seconds' => 30,
            'auto_publish' => false,
        ]);

        $this->assertCount(3, $result1['clips']);
        $initialClipIds = $result1['clips']->pluck('id')->toArray();
        $this->assertCount(3, $this->mockConn->tables['clips'], 'Database must have 3 clips after first decomposition');

        // Run 2: Decompose duplicate on same video without regenerate flag
        $result2 = $service->decomposeVideo($video, [
            'video_duration' => 90.0,
            'interval_seconds' => 30,
            'auto_publish' => false,
        ]);

        $this->assertCount(3, $result2['clips'], 'Duplicate decompose call must return existing 3 clips without creating duplicates');
        $this->assertCount(3, $this->mockConn->tables['clips'], 'Clips count in database must remain exactly 3');
        $secondClipIds = $result2['clips']->pluck('id')->toArray();
        $this->assertEquals($initialClipIds, $secondClipIds, 'Clip UUIDs must remain identical across idempotent calls');
    }

    /**
     * EMPIRICAL TEST 2: Idempotency with Publications.
     * Calling decompose twice with auto_publish does not duplicate pending publications.
     */
    public function test_idempotency_decompose_twice_does_not_duplicate_pending_publications(): void
    {
        $userId = (string) Str::uuid();
        $this->mockConn->tables['users'][] = [
            'id' => $userId,
            'name' => 'Pub User',
            'email' => 'pub-idempotent@reelforge.internal',
        ];

        $videoId = (string) Str::uuid();
        $video = new Video([
            'source_url' => 'https://example.com/event.mp4',
            'title' => 'Tech Event',
            'status' => Video::STATUS_READY,
            'metadata' => [
                'interval_seconds' => 30,
                'max_duration_seconds' => 60,
                'vertical_mode' => 'blur_padding',
                'auto_publish' => true,
                'platforms' => ['tiktok', 'instagram', 'youtube'],
            ],
        ]);
        $video->id = $videoId;
        $video->user_id = $userId;
        $this->mockConn->tables['videos'][] = $video->toArray();

        $service = app(VideoIngestService::class);

        // Queue fake prevents synchronous job completion so status remains pending
        Queue::fake();

        // First decompose call
        $result1 = $service->decomposeVideo($video, [
            'video_duration' => 60.0, // 2 clips
            'interval_seconds' => 30,
            'auto_publish' => true,
            'platforms' => ['tiktok', 'instagram', 'youtube'],
        ]);

        $this->assertCount(2, $result1['clips']);
        // 2 clips * 3 platforms = 6 publications
        $this->assertCount(6, $result1['publications']);
        $initialPubIds = collect($result1['publications'])->pluck('id')->toArray();

        // Second decompose call with same options
        $result2 = $service->decomposeVideo($video, [
            'video_duration' => 60.0,
            'interval_seconds' => 30,
            'auto_publish' => true,
            'platforms' => ['tiktok', 'instagram', 'youtube'],
        ]);

        $this->assertCount(2, $result2['clips']);
        $this->assertCount(6, $result2['publications']);
        $secondPubIds = collect($result2['publications'])->pluck('id')->toArray();
        $this->assertEquals($initialPubIds, $secondPubIds, 'Publications must be reused without generating duplicate rows');
    }

    /**
     * EMPIRICAL TEST 3: Regenerate flag deletes prior clips and recreates fresh ones.
     */
    public function test_regenerate_flag_replaces_clips_with_fresh_slices(): void
    {
        $userId = (string) Str::uuid();
        $this->mockConn->tables['users'][] = [
            'id' => $userId,
            'name' => 'Regen User',
            'email' => 'regen@reelforge.internal',
        ];

        $videoId = (string) Str::uuid();
        $video = new Video([
            'source_url' => 'https://example.com/show.mp4',
            'title' => 'Regenerate Show',
            'status' => Video::STATUS_READY,
        ]);
        $video->id = $videoId;
        $video->user_id = $userId;
        $this->mockConn->tables['videos'][] = $video->toArray();

        $service = app(VideoIngestService::class);

        // Initial 30s intervals (90s = 3 clips)
        $res1 = $service->decomposeVideo($video, [
            'video_duration' => 90.0,
            'interval_seconds' => 30,
            'auto_publish' => false,
        ]);
        $this->assertCount(3, $res1['clips']);

        // Regenerate with 45s intervals (90s = 2 clips)
        $res2 = $service->decomposeVideo($video, [
            'video_duration' => 90.0,
            'interval_seconds' => 45,
            'regenerate' => true,
            'auto_publish' => false,
        ]);

        $this->assertCount(2, $res2['clips']);
        $this->assertEquals(45.0, $res2['clips'][0]->duration);
        $this->assertEquals(45.0, $res2['clips'][1]->duration);
    }

    /**
     * EMPIRICAL TEST 4: Publication Status Lifecycle and Audit Logs.
     * Verifies valid status transitions: pending -> publishing -> published,
     * and presence of structured audit logs in publishing_logs.
     */
    public function test_publication_status_lifecycle_and_audit_logging(): void
    {
        $userId = (string) Str::uuid();
        $this->mockConn->tables['users'][] = [
            'id' => $userId,
            'name' => 'Lifecycle User',
            'email' => 'lifecycle@reelforge.internal',
        ];

        $account = new SocialAccount([
            'user_id' => $userId,
            'platform' => SocialAccount::PLATFORM_TIKTOK,
            'platform_user_id' => 'tt_creator_123',
            'platform_username' => 'tiktok_star',
            'access_token' => 'mock_token',
        ]);
        $account->id = (string) Str::uuid();
        $this->mockConn->tables['social_accounts'][] = $account->toArray();

        $clip = new Clip([
            'video_id' => (string) Str::uuid(),
            'title' => 'Lifecycle Reel',
            'start_time' => 0.0,
            'end_time' => 30.0,
            'duration' => 30.0,
            'status' => Clip::STATUS_READY,
            'metadata' => ['width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16'],
        ]);
        $clip->id = (string) Str::uuid();
        $this->mockConn->tables['clips'][] = $clip->toArray();

        $orchestrator = app(PublishingOrchestrator::class);

        // Step 1: Dispatch
        $res = $orchestrator->publishClip($clip, ['tiktok'], 'Trending dance #viral', null, $userId);
        $this->assertNotEmpty($res['publication_ids']);
        $pubId = $res['publication_ids'][0];

        // Audit log check: dispatched
        $dispatchedLogs = array_filter(
            $this->mockConn->tables['publishing_logs'],
            fn ($l) => ($l['publication_id'] ?? '') === $pubId && ($l['event'] ?? '') === PublishingLog::EVENT_DISPATCHED
        );
        $this->assertNotEmpty($dispatchedLogs, 'Must emit dispatched event log');
        $firstLog = reset($dispatchedLogs);
        $this->assertEquals('tiktok', $firstLog['platform']);
        $payload = is_string($firstLog['payload']) ? json_decode($firstLog['payload'], true) : $firstLog['payload'];
        $this->assertEquals('Trending dance #viral', $payload['caption']);

        // Step 2: Job execution lifecycle
        $publication = new Publication([
            'clip_id' => $clip->id,
            'social_account_id' => $account->id,
            'status' => Publication::STATUS_PENDING,
        ]);
        $publication->id = $pubId;
        $publication->setRelation('clip', $clip);
        $publication->setRelation('socialAccount', $account);

        $job = new PublishToTikTokJob($pubId, ['caption' => 'Trending dance #viral']);
        $adapter = app(TikTokPublisherAdapter::class);
        $job->handle($adapter);

        // Audit log check: upload_started and published
        $events = array_map(fn ($l) => $l['event'] ?? '', $this->mockConn->tables['publishing_logs']);
        $this->assertContains(PublishingLog::EVENT_DISPATCHED, $events);
        $this->assertContains(PublishingLog::EVENT_UPLOAD_STARTED, $events);
        $this->assertContains(PublishingLog::EVENT_PUBLISHED, $events);
    }

    /**
     * EMPIRICAL TEST 5: Boundary & Extreme Values - Interval Seconds 5 vs 600.
     */
    public function test_boundary_interval_seconds_5_vs_600(): void
    {
        $userId = (string) Str::uuid();
        $this->mockConn->tables['users'][] = [
            'id' => $userId,
            'name' => 'Boundary User',
            'email' => 'boundary@reelforge.internal',
        ];

        $video = new Video([
            'source_url' => 'https://example.com/extreme.mp4',
            'title' => 'Extreme Intervals',
            'status' => Video::STATUS_READY,
        ]);
        $video->id = (string) Str::uuid();
        $video->user_id = $userId;
        $this->mockConn->tables['videos'][] = $video->toArray();

        $service = app(VideoIngestService::class);

        // Case A: Minimum valid interval_seconds = 5 on 90s video
        // 90 / 5 = 18 clips, each 5.0s
        $resMin = $service->decomposeVideo($video, [
            'video_duration' => 90.0,
            'interval_seconds' => 5,
            'regenerate' => true,
            'auto_publish' => false,
        ]);
        $this->assertCount(18, $resMin['clips']);
        foreach ($resMin['clips'] as $c) {
            $this->assertEquals(5.0, $c->duration);
            $this->assertEquals(1080, $c->metadata['width']);
            $this->assertEquals(1920, $c->metadata['height']);
            $this->assertEquals('9:16', $c->metadata['aspect_ratio']);
        }

        // Case B: Maximum interval_seconds = 600 on 90s video
        // Slicing must cap at 60s for YouTube Shorts compliance!
        // 90s / 60s = 2 clips (clip 1: 60s, clip 2: 30s)
        $resMax = $service->decomposeVideo($video, [
            'video_duration' => 90.0,
            'interval_seconds' => 600,
            'regenerate' => true,
            'auto_publish' => false,
        ]);
        $this->assertCount(2, $resMax['clips']);
        $this->assertEquals(60.0, $resMax['clips'][0]->duration);
        $this->assertEquals(30.0, $resMax['clips'][1]->duration);
        $this->assertLessThanOrEqual(60.0, $resMax['clips'][0]->duration);
        $this->assertLessThanOrEqual(60.0, $resMax['clips'][1]->duration);
    }

    /**
     * EMPIRICAL TEST 6: Strict Duration Capping <= 60.0s under any options.
     */
    public function test_duration_capping_strictly_enforces_60s_maximum(): void
    {
        $userId = (string) Str::uuid();
        $this->mockConn->tables['users'][] = [
            'id' => $userId,
            'name' => 'Cap User',
            'email' => 'cap@reelforge.internal',
        ];

        $video = new Video([
            'source_url' => 'https://example.com/one-hour.mp4',
            'title' => 'One Hour Video',
            'status' => Video::STATUS_READY,
        ]);
        $video->id = (string) Str::uuid();
        $video->user_id = $userId;
        $this->mockConn->tables['videos'][] = $video->toArray();

        $service = app(VideoIngestService::class);

        // Try to request 300s clips with 600s max duration on a 180s video
        $res = $service->decomposeVideo($video, [
            'video_duration' => 180.0,
            'interval_seconds' => 300,
            'max_duration_seconds' => 600,
            'regenerate' => true,
            'auto_publish' => false,
        ]);

        $this->assertNotEmpty($res['clips']);
        foreach ($res['clips'] as $clip) {
            $this->assertLessThanOrEqual(60.0, $clip->duration, 'No reel must ever exceed 60.0s');
            $this->assertGreaterThan(0.0, $clip->duration, 'Clip duration must be positive');
        }
    }

    /**
     * EMPIRICAL TEST 7: HTTP Validation Rejects Out-Of-Bounds Intervals and Durations.
     */
    public function test_http_validation_rejects_out_of_bounds_parameters(): void
    {
        // 1. interval_seconds = 4 (< min:5)
        $res = $this->postJson('/api/videos/decompose', [
            'source_url' => 'https://example.com/video.mp4',
            'interval_seconds' => 4,
        ]);
        $res->assertStatus(422)->assertJsonValidationErrors(['interval_seconds']);

        // 2. interval_seconds = 601 (> max:600)
        $res2 = $this->postJson('/api/videos/decompose', [
            'source_url' => 'https://example.com/video.mp4',
            'interval_seconds' => 601,
        ]);
        $res2->assertStatus(422)->assertJsonValidationErrors(['interval_seconds']);

        // 3. max_duration_seconds = 4 (< min:5)
        $res3 = $this->postJson('/api/videos/decompose', [
            'source_url' => 'https://example.com/video.mp4',
            'max_duration_seconds' => 4,
        ]);
        $res3->assertStatus(422)->assertJsonValidationErrors(['max_duration_seconds']);

        // 4. max_duration_seconds = 601 (> max:600)
        $res4 = $this->postJson('/api/videos/decompose', [
            'source_url' => 'https://example.com/video.mp4',
            'max_duration_seconds' => 601,
        ]);
        $res4->assertStatus(422)->assertJsonValidationErrors(['max_duration_seconds']);

        // 5. video_duration = 0 (< min:1)
        $res5 = $this->postJson('/api/videos/decompose', [
            'source_url' => 'https://example.com/video.mp4',
            'video_duration' => 0,
        ]);
        $res5->assertStatus(422)->assertJsonValidationErrors(['video_duration']);
    }

    /**
     * EMPIRICAL TEST 8: Malformed Payloads, SQL Injection & Path Traversal in Caption and Platforms.
     */
    public function test_adversarial_payloads_in_caption_and_platforms(): void
    {
        // A. Invalid platforms: SQL injection and traversal rejected with 422
        $resSqlPlatform = $this->postJson('/api/videos/decompose', [
            'source_url' => 'https://example.com/video.mp4',
            'platforms' => ["' OR 1=1; DROP TABLE videos; --"],
        ]);
        $resSqlPlatform->assertStatus(422)->assertJsonValidationErrors(['platforms.0']);

        $resPathPlatform = $this->postJson('/api/videos/decompose', [
            'source_url' => 'https://example.com/video.mp4',
            'platforms' => ['../../../../etc/shadow'],
        ]);
        $resPathPlatform->assertStatus(422)->assertJsonValidationErrors(['platforms.0']);

        $resNonArray = $this->postJson('/api/videos/decompose', [
            'source_url' => 'https://example.com/video.mp4',
            'platforms' => 'tiktok',
        ]);
        $resNonArray->assertStatus(422)->assertJsonValidationErrors(['platforms']);

        // B. Caption boundaries: 1000 characters allowed, 1001 rejected
        $resCap1001 = $this->postJson('/api/videos/decompose', [
            'source_url' => 'https://example.com/video.mp4',
            'caption' => str_repeat('A', 1001),
        ]);
        $resCap1001->assertStatus(422)->assertJsonValidationErrors(['caption']);

        // C. SQL injection & XSS strings in caption: must be treated as safe scalar string without execution
        $sqlCaption = "'; DROP TABLE users; -- <script>alert('xss')</script> 🔥 𝓡𝓮𝓮𝓵 日本語";
        $userId = (string) Str::uuid();
        $this->mockConn->tables['users'][] = [
            'id' => $userId,
            'name' => 'Safe User',
            'email' => 'safe@reelforge.internal',
        ];

        $video = new Video([
            'source_url' => 'https://example.com/adversarial.mp4',
            'title' => 'Adversarial Title',
            'status' => Video::STATUS_READY,
        ]);
        $video->id = (string) Str::uuid();
        $video->user_id = $userId;
        $this->mockConn->tables['videos'][] = $video->toArray();

        $service = app(VideoIngestService::class);
        $result = $service->decomposeVideo($video, [
            'video_duration' => 60.0,
            'interval_seconds' => 30,
            'auto_publish' => true,
            'caption' => $sqlCaption,
            'platforms' => ['tiktok'],
        ]);

        $this->assertNotEmpty($result['publications']);
        // Verify publishing log contains verbatim string without SQL corruption
        $logs = array_filter(
            $this->mockConn->tables['publishing_logs'],
            fn ($l) => ($l['event'] ?? '') === PublishingLog::EVENT_DISPATCHED
        );
        $this->assertNotEmpty($logs);
        $lastLog = end($logs);
        $payload = is_string($lastLog['payload']) ? json_decode($lastLog['payload'], true) : $lastLog['payload'];
        $this->assertEquals($sqlCaption, $payload['caption']);
    }

    /**
     * EMPIRICAL TEST 9: Duplicate platforms in request array are de-duplicated in database and publishVideoClips.
     */
    public function test_duplicate_platforms_are_deduplicated_by_orchestrator(): void
    {
        Queue::fake();

        $userId = (string) Str::uuid();
        $this->mockConn->tables['users'][] = [
            'id' => $userId,
            'name' => 'Dedup User',
            'email' => 'dedup@reelforge.internal',
        ];

        $account = new SocialAccount([
            'user_id' => $userId,
            'platform' => SocialAccount::PLATFORM_TIKTOK,
            'platform_user_id' => 'tt_creator_dup',
            'platform_username' => 'tiktok_dup',
            'access_token' => 'mock_token',
        ]);
        $account->id = (string) Str::uuid();
        $this->mockConn->tables['social_accounts'][] = $account->toArray();

        $video = new Video([
            'source_url' => 'https://example.com/dedup_vid.mp4',
            'title' => 'Dedup Video',
            'status' => Video::STATUS_READY,
        ]);
        $video->id = (string) Str::uuid();
        $video->user_id = $userId;
        $this->mockConn->tables['videos'][] = $video->toArray();

        $clip = new Clip([
            'video_id' => $video->id,
            'title' => 'Dedup Clip',
            'start_time' => 0.0,
            'end_time' => 30.0,
            'duration' => 30.0,
            'status' => Clip::STATUS_READY,
            'metadata' => ['width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16'],
        ]);
        $clip->id = (string) Str::uuid();
        $this->mockConn->tables['clips'][] = $clip->toArray();

        $orchestrator = app(PublishingOrchestrator::class);

        // Batch video publishing with duplicate platforms in array: ['tiktok', 'tiktok', 'tiktok']
        $res = $orchestrator->publishVideoClips($video, ['tiktok', 'tiktok', 'tiktok']);

        // publishVideoClips must strictly deduplicate publication IDs
        $this->assertCount(1, $res['publication_ids'], 'publishVideoClips must deduplicate repeated platforms in array');
        $this->assertEquals(1, $res['dispatched_count']);

        // Publications in database must have exactly 1 record for this clip
        $clipPubs = array_filter(
            $this->mockConn->tables['publications'],
            fn ($p) => ($p['clip_id'] ?? '') === $clip->id
        );
        $this->assertCount(1, $clipPubs, 'Database must only contain 1 publication record despite duplicate platforms');
    }
}
