<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublishingLog;
use App\Modules\Publishing\Services\PublishingOrchestrator;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\Videos\Models\Video;
use App\Modules\Videos\Services\VideoIngestService;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class ChallengerM5EmpiricalStressTest extends TestCase
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

                        // Extract "column in (?, ?, ...)" if present
                        $inCol = null;
                        $inVals = [];
                        $equalityBindings = $bindings;

                        if (preg_match('/"([a-z_]+)"\s+in\s*\(([^)]+)\)/i', $query, $inMatch)) {
                            $inCol = $inMatch[1];
                            $inCount = substr_count($inMatch[2], '?') ?: count(explode(',', $inMatch[2]));
                            $inVals = array_slice($bindings, -$inCount);
                            $equalityBindings = array_slice($bindings, 0, -$inCount);
                        }

                        $filtered = [];
                        foreach ($rows as $row) {
                            $match = true;

                            // Check in clause
                            if ($inCol !== null) {
                                if (! in_array($row[$inCol] ?? null, $inVals, false)) {
                                    $match = false;
                                }
                            }

                            // Check other equality bindings
                            if ($match && ! empty($equalityBindings)) {
                                foreach ($equalityBindings as $b) {
                                    if (! in_array($b, array_values($row), false)) {
                                        $match = false;
                                        break;
                                    }
                                }
                            }

                            if ($match) {
                                $filtered[] = $row;
                            }
                        }

                        if (str_contains(strtolower($query), 'count(*)')) {
                            return [(object) ['aggregate' => count($filtered)]];
                        }

                        if (str_contains($query, 'limit 1')) {
                            return ! empty($filtered) ? [reset($filtered)] : [];
                        }

                        return array_values($filtered);
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
     * EMPIRICAL CHALLENGE 1.1: 9:16 Vertical Geometry across all vertical modes.
     * Must produce width=1080, height=1920, aspect_ratio='9:16' for blur_padding, center_crop, and letterbox.
     */
    public function test_decomposition_strictly_enforces_9x16_geometry_for_all_vertical_modes(): void
    {
        $modes = ['blur_padding', 'center_crop', 'letterbox'];
        $service = app(VideoIngestService::class);

        foreach ($modes as $mode) {
            $video = new Video([
                'source_url' => "https://example.com/{$mode}-test.mp4",
                'title' => "Test {$mode}",
                'status' => Video::STATUS_PENDING,
                'metadata' => [
                    'vertical_mode' => $mode,
                    'interval_seconds' => 30,
                    'max_duration_seconds' => 60,
                ],
            ]);
            $video->id = (string) Str::uuid();

            $result = $service->decomposeVideo($video, [
                'video_duration' => 60.0,
                'vertical_mode' => $mode,
                'auto_publish' => false,
                'regenerate' => true,
            ]);

            $this->assertEquals(Video::STATUS_READY, $result['status']);
            $this->assertNotEmpty($result['clips'], "Clips must be generated for mode {$mode}");

            foreach ($result['clips'] as $clip) {
                $this->assertEquals(1080, $clip->metadata['width'], "Clip width must be 1080 for {$mode}");
                $this->assertEquals(1920, $clip->metadata['height'], "Clip height must be 1920 for {$mode}");
                $this->assertEquals('9:16', $clip->metadata['aspect_ratio'], "Clip aspect ratio must be 9:16 for {$mode}");
                $this->assertEquals($mode, $clip->metadata['vertical_mode'], "Clip vertical mode must match requested mode {$mode}");
            }
        }
    }

    /**
     * EMPIRICAL CHALLENGE 1.2: Enforce YouTube Shorts duration limit (<= 60.0s) on a long 300s video.
     * Even if interval_seconds=120 or max_duration=300 is requested, duration MUST NOT exceed 60.0s.
     */
    public function test_decomposition_caps_youtube_shorts_duration_at_60_seconds_on_300s_video(): void
    {
        $service = app(VideoIngestService::class);

        $video = new Video([
            'source_url' => 'https://example.com/long-seminar-300s.mp4',
            'title' => 'Long Seminar (300 seconds)',
            'status' => Video::STATUS_PENDING,
            'metadata' => [
                'interval_seconds' => 120, // Client tries to request 120s interval
                'max_duration_seconds' => 300, // Client tries to request 300s max duration
            ],
        ]);
        $video->id = (string) Str::uuid();

        $result = $service->decomposeVideo($video, [
            'video_duration' => 300.0,
            'interval_seconds' => 120,
            'max_duration_seconds' => 300,
            'auto_publish' => false,
        ]);

        $this->assertEquals(Video::STATUS_READY, $result['status']);
        $this->assertNotEmpty($result['clips']);

        // Since max_duration is capped at 60s, sliceDuration must be min(120, 60) = 60s
        // 300s / 60s = exactly 5 clips
        $this->assertCount(5, $result['clips'], '300s video sliced with 60s cap must yield exactly 5 clips');

        $expectedStart = 0.0;
        foreach ($result['clips'] as $clip) {
            $this->assertLessThanOrEqual(60.0, $clip->duration, 'Duration must never exceed 60.0s for YouTube Shorts');
            $this->assertGreaterThan(0.0, $clip->duration, 'Duration must be positive');
            $this->assertEquals($expectedStart, $clip->start_time, 'Start times must progress uniformly');
            $this->assertEquals($expectedStart + $clip->duration, $clip->end_time, 'End time must equal start_time + duration');
            $expectedStart += $clip->duration;
        }

        $this->assertEquals(300.0, $expectedStart, 'Total partitioned time must cover the 300.0s video duration');
    }

    /**
     * EMPIRICAL CHALLENGE 1.3: Negative and Zero Duration Stress Testing.
     * Tests division-by-zero prevention, zero interval, negative duration, and boundary conditions.
     */
    public function test_decomposition_handles_zero_and_negative_durations_without_division_by_zero(): void
    {
        $service = app(VideoIngestService::class);

        // Adversarial test cases: [interval, max_duration, video_duration]
        $adversarialInputs = [
            ['interval' => 0, 'max' => 0, 'duration' => 0.0],
            ['interval' => -30, 'max' => -60, 'duration' => -120.0],
            ['interval' => 0, 'max' => 60, 'duration' => 90.0],
            ['interval' => 30, 'max' => 0, 'duration' => 90.0],
            ['interval' => 30, 'max' => 60, 'duration' => 0.0],
            ['interval' => -100, 'max' => -50, 'duration' => 0.0],
        ];

        foreach ($adversarialInputs as $idx => $input) {
            $video = new Video([
                'source_url' => "https://example.com/edge-case-{$idx}.mp4",
                'title' => "Edge Case {$idx}",
                'status' => Video::STATUS_PENDING,
            ]);
            $video->id = (string) Str::uuid();

            // Must NOT throw DivisionByZeroError or unhandled exception
            $result = $service->decomposeVideo($video, [
                'interval_seconds' => $input['interval'],
                'max_duration_seconds' => $input['max'],
                'video_duration' => $input['duration'],
                'auto_publish' => false,
                'regenerate' => true,
            ]);

            $this->assertEquals(Video::STATUS_READY, $result['status']);
            $this->assertNotEmpty($result['clips'], "Must generate fallback clips for input set #{$idx}");

            foreach ($result['clips'] as $clip) {
                $this->assertGreaterThan(0.0, $clip->duration, "Clip duration must be strictly positive in input set #{$idx}");
                $this->assertLessThanOrEqual(60.0, $clip->duration, "Clip duration must not exceed 60s in input set #{$idx}");
                $this->assertEquals(1080, $clip->metadata['width']);
                $this->assertEquals(1920, $clip->metadata['height']);
                $this->assertEquals('9:16', $clip->metadata['aspect_ratio']);
            }
        }
    }

    /**
     * EMPIRICAL CHALLENGE 2.1: Auto-Publish Mock Account Auto-Provisioning Fallback.
     * When no social accounts exist in DB, verify that mock accounts are smoothly provisioned
     * for all 3 supported platforms (tiktok, instagram, youtube) and publications dispatched.
     */
    public function test_auto_publish_provisions_mock_accounts_when_no_accounts_exist(): void
    {
        // Ensure mock connection has 0 pre-existing social accounts
        $this->mockConn->tables['social_accounts'] = [];
        $this->mockConn->tables['publications'] = [];
        $this->mockConn->tables['publishing_logs'] = [];

        $user = new User(['name' => 'Zero Accounts User', 'email' => 'zero@reelforge.internal']);
        $user->id = (string) Str::uuid();

        $video = new Video([
            'source_url' => 'https://example.com/interview.mp4',
            'title' => 'Interview with Zero Accounts',
            'status' => Video::STATUS_PENDING,
        ]);
        $video->id = (string) Str::uuid();
        $video->user_id = $user->id;

        $service = app(VideoIngestService::class);
        $result = $service->decomposeVideo($video, [
            'video_duration' => 60.0,
            'interval_seconds' => 30,
            'auto_publish' => true, // Auto publish enabled
        ]);

        $this->assertEquals(Video::STATUS_READY, $result['status']);
        $this->assertCount(2, $result['clips'], '60s video with 30s interval should yield 2 clips');

        // Check published platforms
        $this->assertEquals(
            ['tiktok', 'instagram', 'youtube'],
            $result['published_platforms'],
            'Must auto-discover all 3 supported platforms as active fallback'
        );

        // 2 clips * 3 platforms = 6 publications
        $this->assertCount(6, $result['publications'], 'Must produce 6 publications (2 clips * 3 platforms)');

        // Verify mock social accounts were auto-created in social_accounts table
        $createdAccounts = $this->mockConn->tables['social_accounts'];
        $this->assertNotEmpty($createdAccounts, 'Social accounts table must have mock accounts provisioned');

        $platformsCreated = array_unique(array_column($createdAccounts, 'platform'));
        sort($platformsCreated);
        $this->assertEquals(['instagram', 'tiktok', 'youtube'], $platformsCreated);

        // Verify publications table
        $createdPubs = $this->mockConn->tables['publications'];
        $this->assertCount(6, $createdPubs);

        // Verify publishing_logs: each publication emits dispatched, plus upload_started and upload_completed in sync mode
        $createdLogs = $this->mockConn->tables['publishing_logs'];
        $dispatchedLogs = array_filter($createdLogs, fn ($l) => ($l['event'] ?? null) === PublishingLog::EVENT_DISPATCHED);
        $this->assertCount(6, $dispatchedLogs, 'Must have exactly 6 dispatched audit logs (1 per publication)');
        $this->assertGreaterThanOrEqual(6, count($createdLogs), 'Total publishing logs should be at least 6');
    }

    /**
     * EMPIRICAL CHALLENGE 2.2: Empty platforms array vs invalid platform names.
     */
    public function test_empty_platforms_array_and_platform_validation_behavior(): void
    {
        $video = new Video([
            'source_url' => 'https://example.com/test-platforms.mp4',
            'title' => 'Platforms Edge Case',
            'status' => Video::STATUS_PENDING,
        ]);
        $video->id = (string) Str::uuid();

        // Seed video into mock database so findById finds it for the HTTP route
        $this->mockConn->tables['videos'][] = [
            'id' => $video->id,
            'user_id' => (string) Str::uuid(),
            'source_url' => $video->source_url,
            'title' => $video->title,
            'status' => Video::STATUS_PENDING,
            'metadata' => '[]',
        ];

        $service = app(VideoIngestService::class);

        // Sub-test A: empty platforms [] in decomposeVideo options falls back to active platforms smoothly
        $result = $service->decomposeVideo($video, [
            'video_duration' => 30.0,
            'platforms' => [], // Empty platforms array
            'auto_publish' => true,
        ]);

        $this->assertEquals(
            ['tiktok', 'instagram', 'youtube'],
            $result['published_platforms'],
            'Empty platforms array in decomposeVideo should fall back to active/supported platforms'
        );
        $this->assertCount(3, $result['publications'], '1 clip * 3 fallback platforms = 3 publications');

        // Sub-test B: HTTP endpoint validation rejects unsupported/invalid platform names with HTTP 422
        $response = $this->postJson("/api/videos/{$video->id}/decompose", [
            'platforms' => ['myspace', 'friendster', 'facebook'],
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['platforms.0', 'platforms.1', 'platforms.2']);

        // Sub-test C: Direct PublishingOrchestrator::publishClip with strictly empty array throws InvalidArgumentException
        $orchestrator = app(PublishingOrchestrator::class);
        $clip = $result['clips'][0];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one social platform must be selected');
        $orchestrator->publishClip($clip, []);
    }

    /**
     * EMPIRICAL CHALLENGE 2.3: auto_publish = false generates clips without creating publications.
     */
    public function test_auto_publish_false_creates_clips_without_any_publications(): void
    {
        $this->mockConn->tables['publications'] = [];
        $this->mockConn->tables['publishing_logs'] = [];

        $video = new Video([
            'source_url' => 'https://example.com/no-publish.mp4',
            'title' => 'No Publish Video',
            'status' => Video::STATUS_PENDING,
        ]);
        $video->id = (string) Str::uuid();

        $service = app(VideoIngestService::class);
        $result = $service->decomposeVideo($video, [
            'video_duration' => 60.0,
            'interval_seconds' => 30,
            'auto_publish' => false,
        ]);

        $this->assertEquals(Video::STATUS_READY, $result['status']);
        $this->assertCount(2, $result['clips'], 'Clips must still be generated');
        $this->assertEmpty($result['publications'], 'Publications list must be empty when auto_publish=false');
        $this->assertEmpty($result['published_platforms'], 'Published platforms must be empty when auto_publish=false');

        // Database tables must have zero publications and zero logs
        $this->assertEmpty($this->mockConn->tables['publications'], 'No publication records should exist');
        $this->assertEmpty($this->mockConn->tables['publishing_logs'], 'No publishing logs should exist');
    }

    /**
     * EMPIRICAL CHALLENGE 2.4: handleWebhookCallback from Rust engine respects auto_publish.
     */
    public function test_webhook_callback_dispatches_publications_only_when_auto_publish_enabled(): void
    {
        $this->mockConn->tables['clips'] = [];
        $this->mockConn->tables['publications'] = [];
        $this->mockConn->tables['publishing_logs'] = [];

        $service = app(VideoIngestService::class);

        // Case A: video with auto_publish = true
        $videoA = new Video([
            'source_url' => 'https://example.com/axum-callback-a.mp4',
            'title' => 'Axum Callback Video A',
            'status' => Video::STATUS_PROCESSING,
            'metadata' => [
                'auto_publish' => true,
                'platforms' => ['tiktok', 'youtube'],
            ],
        ]);
        $videoA->id = (string) Str::uuid();
        $this->mockConn->tables['videos'][] = [
            'id' => $videoA->id,
            'user_id' => (string) Str::uuid(),
            'source_url' => $videoA->source_url,
            'title' => $videoA->title,
            'status' => Video::STATUS_PROCESSING,
            'metadata' => json_encode($videoA->metadata),
        ];

        $service->handleWebhookCallback($videoA->id, [
            'status' => 'completed',
            'clips' => [
                [
                    'clip_id' => (string) Str::uuid(),
                    'file_path' => "media/clips/{$videoA->id}/clip_001.mp4",
                    'title' => 'Axum Reel #1',
                    'start_time' => 0.0,
                    'end_time' => 30.0,
                    'duration' => 30.0,
                    'width' => 1080,
                    'height' => 1920,
                ],
            ],
        ]);

        $this->assertCount(2, $this->mockConn->tables['publications'], 'Should publish to tiktok and youtube');
        $dispatchedA = array_filter($this->mockConn->tables['publishing_logs'], fn ($l) => ($l['event'] ?? null) === PublishingLog::EVENT_DISPATCHED);
        $this->assertCount(2, $dispatchedA, 'Should emit exactly 2 dispatched logs for video A');

        // Case B: video with auto_publish = false
        $this->mockConn->tables['publications'] = [];
        $this->mockConn->tables['publishing_logs'] = [];

        $videoB = new Video([
            'source_url' => 'https://example.com/axum-callback-b.mp4',
            'title' => 'Axum Callback Video B',
            'status' => Video::STATUS_PROCESSING,
            'metadata' => [
                'auto_publish' => false,
            ],
        ]);
        $videoB->id = (string) Str::uuid();
        $this->mockConn->tables['videos'][] = [
            'id' => $videoB->id,
            'user_id' => (string) Str::uuid(),
            'source_url' => $videoB->source_url,
            'title' => $videoB->title,
            'status' => Video::STATUS_PROCESSING,
            'metadata' => json_encode($videoB->metadata),
        ];

        $service->handleWebhookCallback($videoB->id, [
            'status' => 'completed',
            'clips' => [
                [
                    'clip_id' => (string) Str::uuid(),
                    'file_path' => "media/clips/{$videoB->id}/clip_001.mp4",
                    'title' => 'Axum Reel #1',
                    'start_time' => 0.0,
                    'end_time' => 30.0,
                    'duration' => 30.0,
                    'width' => 1080,
                    'height' => 1920,
                ],
            ],
        ]);

        $this->assertEmpty($this->mockConn->tables['publications'], 'Should NOT publish when auto_publish=false');
        $this->assertEmpty($this->mockConn->tables['publishing_logs']);
    }

    /**
     * EMPIRICAL CHALLENGE 3.1: State Machine Transitions from diverse initial statuses.
     */
    public function test_decompose_recovers_and_transitions_from_failed_downloading_pending(): void
    {
        $service = app(VideoIngestService::class);
        $statuses = [
            Video::STATUS_PENDING,
            Video::STATUS_DOWNLOADING,
            Video::STATUS_PROCESSING,
            Video::STATUS_FAILED,
        ];

        foreach ($statuses as $initStatus) {
            $video = new Video([
                'source_url' => "https://example.com/status-{$initStatus}.mp4",
                'title' => "Transition Test {$initStatus}",
                'status' => $initStatus,
            ]);
            $video->id = (string) Str::uuid();

            $result = $service->decomposeVideo($video, [
                'video_duration' => 30.0,
                'auto_publish' => false,
                'regenerate' => true,
            ]);

            $this->assertEquals(Video::STATUS_READY, $result['status'], "Video starting at {$initStatus} must cleanly transition to ready");
            $this->assertEquals(Video::STATUS_READY, $result['video']->status);
        }
    }

    /**
     * EMPIRICAL CHALLENGE 3.2: Re-entrancy and Idempotency Guard in batch video publishing.
     * When publications are pending, scheduled, or publishing, re-triggering does NOT duplicate publications.
     */
    public function test_publish_video_clips_is_idempotent_when_publications_are_in_flight(): void
    {
        $this->mockConn->tables['publications'] = [];
        $this->mockConn->tables['publishing_logs'] = [];

        $user = new User(['name' => 'Idempotent User', 'email' => 'idempotent@reelforge.internal']);
        $user->id = (string) Str::uuid();

        $video = new Video([
            'source_url' => 'https://example.com/idempotency.mp4',
            'title' => 'Idempotency Video',
            'status' => Video::STATUS_PENDING,
        ]);
        $video->id = (string) Str::uuid();
        $video->user_id = $user->id;

        // Seed a clip
        $clip = new Clip([
            'video_id' => $video->id,
            'title' => 'Idempotent Clip #1',
            'start_time' => 0.0,
            'end_time' => 30.0,
            'duration' => 30.0,
            'status' => Clip::STATUS_READY,
            'metadata' => ['width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16'],
        ]);
        $clip->id = (string) Str::uuid();

        // Seed social accounts
        $accountTk = new SocialAccount([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_user_id' => 'tk_user_1',
            'platform_username' => 'tiktok_user',
            'access_token' => 'token_tk',
        ]);
        $accountTk->id = (string) Str::uuid();

        $this->mockConn->tables['social_accounts'][] = [
            'id' => $accountTk->id,
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_user_id' => 'tk_user_1',
            'platform_username' => 'tiktok_user',
            'access_token' => 'token_tk',
        ];

        // Seed an in-flight pending publication for this clip
        $pubExisting = new Publication([
            'clip_id' => $clip->id,
            'social_account_id' => $accountTk->id,
            'status' => Publication::STATUS_PENDING,
        ]);
        $pubExisting->id = (string) Str::uuid();

        $this->mockConn->tables['publications'][] = [
            'id' => $pubExisting->id,
            'clip_id' => $clip->id,
            'social_account_id' => $accountTk->id,
            'status' => Publication::STATUS_PENDING,
            'scheduled_at' => null,
            'published_at' => null,
            'error_message' => null,
        ];

        $orchestrator = app(PublishingOrchestrator::class);

        // Attempting to publish clip again while in-flight
        $res = $orchestrator->publishClip($clip, ['tiktok'], 'Test caption', null, $user->id);

        // Must reuse the existing publication ID
        $this->assertEquals([$pubExisting->id], $res['publication_ids']);
        $this->assertCount(1, $this->mockConn->tables['publications'], 'Must not create duplicate publication when in-flight');
    }
}
