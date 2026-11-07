<?php

namespace Tests\Feature;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Adapters\InstagramPublisherAdapter;
use App\Modules\Publishing\Adapters\TikTokPublisherAdapter;
use App\Modules\Publishing\Adapters\YouTubePublisherAdapter;
use App\Modules\Publishing\Jobs\PublishToInstagramJob;
use App\Modules\Publishing\Jobs\PublishToTikTokJob;
use App\Modules\Publishing\Jobs\PublishToYouTubeJob;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublishingLog;
use App\Modules\Publishing\Services\PublishingOrchestrator;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\SocialAccounts\Services\InstagramOAuthService;
use App\Modules\SocialAccounts\Services\TikTokOAuthService;
use App\Modules\SocialAccounts\Services\YouTubeOAuthService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class PublishingResilienceAndStructuredLoggingTest extends TestCase
{
    private object $mockConn;

    protected function setUp(): void
    {
        parent::setUp();
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

                            return $filtered;
                        }
                        if (str_contains($query, 'limit 1')) {
                            return ! empty($rows) ? [reset($rows)] : [];
                        }

                        return $rows;
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
                        // Update the matching row or last row
                        $lastIdx = count($this->tables[$table]) - 1;
                        if ($lastIdx >= 0 && ! empty($bindings)) {
                            // Extract column assignments
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

    public function test_rate_limiter_social_publishing_configuration(): void
    {
        $limiter = RateLimiter::limiter('social-publishing');
        $this->assertNotNull($limiter, 'RateLimiter for social-publishing must be registered');

        $fakeJob = new class
        {
            public string $publicationId = 'pub-uuid-1234';
        };

        $result = $limiter($fakeJob);
        $this->assertInstanceOf(Limit::class, $result);
        $this->assertEquals(30, $result->maxAttempts);
        $this->assertEquals('pub-uuid-1234', $result->key);

        // Fallback to 'global' when publicationId is null
        $emptyJob = new class {};
        $globalResult = $limiter($emptyJob);
        $this->assertEquals('global', $globalResult->key);
    }

    public function test_publish_clip_records_structured_dispatched_logs(): void
    {
        Queue::fake();

        $userId = (string) Str::uuid();
        $this->mockConn->tables['users'][] = [
            'id' => $userId,
            'name' => 'Operator',
            'email' => 'operator@reelforge.internal',
        ];

        $clip = new Clip([
            'title' => 'Viral Reel Clip',
            'duration' => 30.0,
            'status' => Clip::STATUS_READY,
        ]);
        $clip->id = (string) Str::uuid();

        $orchestrator = new PublishingOrchestrator(
            new TikTokPublisherAdapter(new TikTokOAuthService),
            new InstagramPublisherAdapter(new InstagramOAuthService),
            new YouTubePublisherAdapter(new YouTubeOAuthService)
        );

        $result = $orchestrator->publishClip(
            $clip,
            ['tiktok', 'instagram', 'youtube'],
            caption: 'Structured event log check #viral',
            userId: $userId
        );

        $this->assertEquals('queued', $result['status']);
        $this->assertCount(3, $result['publication_ids']);

        // Assert 3 dispatched logs were recorded in publishing_logs table
        $logs = array_filter(
            $this->mockConn->tables['publishing_logs'],
            fn ($l) => ($l['event'] ?? '') === PublishingLog::EVENT_DISPATCHED
        );
        $this->assertCount(3, $logs, 'Must record 3 dispatched logs for the 3 platforms');

        foreach ($logs as $log) {
            $this->assertEquals(PublishingLog::EVENT_DISPATCHED, $log['event']);
            $this->assertContains($log['platform'], ['tiktok', 'instagram', 'youtube']);
            $this->assertNull($log['error_message']);

            $payload = is_string($log['payload']) ? json_decode($log['payload'], true) : $log['payload'];
            $this->assertEquals('Structured event log check #viral', $payload['caption']);
        }
    }

    public function test_tiktok_job_execution_with_chunking_and_structured_logs(): void
    {
        $pubId = (string) Str::uuid();
        $clipId = (string) Str::uuid();
        $accId = (string) Str::uuid();

        $this->mockConn->tables['clips'][] = [
            'id' => $clipId,
            'title' => 'Chunked Video',
            'duration' => 25.0,
            'file_path' => 'clips/test.mp4',
            'status' => Clip::STATUS_READY,
        ];
        $this->mockConn->tables['social_accounts'][] = [
            'id' => $accId,
            'platform' => SocialAccount::PLATFORM_TIKTOK,
            'platform_username' => 'tiktok_tester',
            'access_token' => 'mock_token',
        ];
        $this->mockConn->tables['publications'][] = [
            'id' => $pubId,
            'clip_id' => $clipId,
            'social_account_id' => $accId,
            'status' => Publication::STATUS_PENDING,
            'error_message' => null,
            'platform_post_id' => null,
        ];

        config(['social.mock_mode' => true]);

        $job = new PublishToTikTokJob($pubId, [
            'caption' => 'Chunked upload test',
            'simulate_chunking' => true,
            'total_chunks' => 3,
            'chunk_size' => 1048576,
        ]);

        $adapter = new TikTokPublisherAdapter(new TikTokOAuthService);
        $job->handle($adapter);

        $allLogs = $this->mockConn->tables['publishing_logs'];

        // 1. Assert upload_started event was logged
        $startedLogs = array_filter($allLogs, fn ($l) => ($l['event'] ?? '') === PublishingLog::EVENT_UPLOAD_STARTED);
        $this->assertCount(1, $startedLogs, 'Must log upload_started event');

        // 2. Assert chunk_uploaded events were logged (3 chunks)
        $chunkLogs = array_values(array_filter($allLogs, fn ($l) => ($l['event'] ?? '') === PublishingLog::EVENT_CHUNK_UPLOADED));
        $this->assertCount(3, $chunkLogs, 'Must log 3 chunk_uploaded events');

        $chunk1Payload = is_string($chunkLogs[0]['payload']) ? json_decode($chunkLogs[0]['payload'], true) : $chunkLogs[0]['payload'];
        $this->assertEquals(1, $chunk1Payload['chunk_index']);
        $this->assertEquals(3, $chunk1Payload['total_chunks']);
        $this->assertEquals(1048576, $chunk1Payload['bytes_uploaded']);

        $chunk3Payload = is_string($chunkLogs[2]['payload']) ? json_decode($chunkLogs[2]['payload'], true) : $chunkLogs[2]['payload'];
        $this->assertEquals(3, $chunk3Payload['chunk_index']);
        $this->assertEquals(3145728, $chunk3Payload['bytes_uploaded']);

        // 3. Assert published event was logged
        $publishedLogs = array_filter($allLogs, fn ($l) => ($l['event'] ?? '') === PublishingLog::EVENT_PUBLISHED);
        $this->assertCount(1, $publishedLogs, 'Must log published event');
    }

    public function test_job_retry_attempted_and_exhausted_failure_logs(): void
    {
        $pubId = (string) Str::uuid();
        $clipId = (string) Str::uuid();
        $accId = (string) Str::uuid();

        $this->mockConn->tables['clips'][] = [
            'id' => $clipId,
            'title' => 'Failure Video',
            'duration' => 20.0,
            'file_path' => 'clips/test.mp4',
            'status' => Clip::STATUS_READY,
        ];
        $this->mockConn->tables['social_accounts'][] = [
            'id' => $accId,
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'platform_username' => 'ig_tester',
            'access_token' => 'mock_token',
        ];
        $this->mockConn->tables['publications'][] = [
            'id' => $pubId,
            'clip_id' => $clipId,
            'social_account_id' => $accId,
            'status' => Publication::STATUS_PENDING,
            'error_message' => null,
            'platform_post_id' => null,
        ];

        config(['social.mock_mode' => true]);
        $adapter = new InstagramPublisherAdapter(new InstagramOAuthService);

        // Attempt 1: logs retry_attempted and releases with delay = pow(2, 1) * 10 = 20s
        $jobAttempt1 = new class($pubId, ['simulate_network_failure' => true]) extends PublishToInstagramJob
        {
            public int $mockAttempts = 1;

            public bool $released = false;

            public int $releaseDelay = 0;

            public function attempts(): int
            {
                return $this->mockAttempts;
            }

            public function release($delay = 0)
            {
                $this->released = true;
                $this->releaseDelay = $delay;
            }
        };

        $jobAttempt1->handle($adapter);
        $this->assertTrue($jobAttempt1->released);
        $this->assertEquals(20, $jobAttempt1->releaseDelay);

        $retryLogs = array_filter($this->mockConn->tables['publishing_logs'], fn ($l) => ($l['event'] ?? '') === PublishingLog::EVENT_RETRY_ATTEMPTED);
        $this->assertCount(1, $retryLogs);
        $retryLog = reset($retryLogs);
        $retryPayload = is_string($retryLog['payload']) ? json_decode($retryLog['payload'], true) : $retryLog['payload'];
        $this->assertEquals(1, $retryPayload['attempt']);
        $this->assertEquals(20, $retryPayload['next_retry_delay_seconds']);
        $this->assertStringContainsString('Simulated network failure', $retryLog['error_message']);

        // Attempt 3: Exhausted ($tries = 3), marks failed and logs 'failed' event
        $jobAttempt3 = new class($pubId, ['simulate_network_failure' => true]) extends PublishToInstagramJob
        {
            public int $mockAttempts = 3;

            public function attempts(): int
            {
                return $this->mockAttempts;
            }
        };

        $jobAttempt3->handle($adapter);

        $failedLogs = array_filter($this->mockConn->tables['publishing_logs'], fn ($l) => ($l['event'] ?? '') === PublishingLog::EVENT_FAILED);
        $this->assertCount(1, $failedLogs);
        $failedLog = reset($failedLogs);
        $failedPayload = is_string($failedLog['payload']) ? json_decode($failedLog['payload'], true) : $failedLog['payload'];
        $this->assertEquals(3, $failedPayload['attempt']);
        $this->assertStringContainsString('Simulated network failure', $failedLog['error_message']);
    }

    public function test_youtube_job_handles_failed_method_gracefully(): void
    {
        $pubId = (string) Str::uuid();
        $clipId = (string) Str::uuid();
        $accId = (string) Str::uuid();

        $this->mockConn->tables['clips'][] = [
            'id' => $clipId,
            'title' => 'YT Video',
            'duration' => 20.0,
            'file_path' => 'clips/test.mp4',
            'status' => Clip::STATUS_READY,
        ];
        $this->mockConn->tables['social_accounts'][] = [
            'id' => $accId,
            'platform' => SocialAccount::PLATFORM_YOUTUBE,
            'platform_username' => 'yt_tester',
            'access_token' => 'mock_token',
        ];
        $this->mockConn->tables['publications'][] = [
            'id' => $pubId,
            'clip_id' => $clipId,
            'social_account_id' => $accId,
            'status' => Publication::STATUS_PUBLISHING,
            'error_message' => null,
            'platform_post_id' => null,
        ];

        $job = new PublishToYouTubeJob($pubId);
        $job->failed(new \RuntimeException('Catastrophic worker queue timeout'));

        $failedLogs = array_filter($this->mockConn->tables['publishing_logs'], fn ($l) => ($l['event'] ?? '') === PublishingLog::EVENT_FAILED);
        $this->assertCount(1, $failedLogs);
        $failedLog = reset($failedLogs);
        $this->assertEquals('Catastrophic worker queue timeout', $failedLog['error_message']);
    }

    public function test_retry_publication_preserves_initial_caption_and_emits_dispatched_event(): void
    {
        Queue::fake();

        $pubId = (string) Str::uuid();
        $clipId = (string) Str::uuid();
        $accId = (string) Str::uuid();

        $this->mockConn->tables['clips'][] = [
            'id' => $clipId,
            'title' => 'Default Clip Title',
            'duration' => 20.0,
            'file_path' => 'clips/test.mp4',
            'status' => Clip::STATUS_READY,
        ];
        $this->mockConn->tables['social_accounts'][] = [
            'id' => $accId,
            'platform' => SocialAccount::PLATFORM_TIKTOK,
            'platform_username' => 'tiktok_tester',
            'access_token' => 'mock_token',
        ];
        $this->mockConn->tables['publications'][] = [
            'id' => $pubId,
            'clip_id' => $clipId,
            'social_account_id' => $accId,
            'status' => Publication::STATUS_FAILED,
            'error_message' => 'Previous network timeout',
            'platform_post_id' => null,
        ];

        // Seed initial dispatched log containing custom caption
        $this->mockConn->tables['publishing_logs'][] = [
            'id' => (string) Str::uuid(),
            'publication_id' => $pubId,
            'clip_id' => $clipId,
            'social_account_id' => $accId,
            'platform' => SocialAccount::PLATFORM_TIKTOK,
            'event' => PublishingLog::EVENT_DISPATCHED,
            'status' => Publication::STATUS_PENDING,
            'payload' => json_encode(['caption' => 'Custom Preserved Caption #reels']),
            'error_message' => null,
        ];

        $orchestrator = new PublishingOrchestrator(
            new TikTokPublisherAdapter(new TikTokOAuthService),
            new InstagramPublisherAdapter(new InstagramOAuthService),
            new YouTubePublisherAdapter(new YouTubeOAuthService)
        );

        $retried = $orchestrator->retryPublication($pubId);
        $this->assertEquals(Publication::STATUS_PENDING, $retried->status);

        // Find retry dispatched log
        $dispatchedLogs = array_filter(
            $this->mockConn->tables['publishing_logs'],
            fn ($l) => ($l['event'] ?? '') === PublishingLog::EVENT_DISPATCHED && ($l['publication_id'] ?? '') === $pubId
        );
        $this->assertCount(2, $dispatchedLogs, 'Expected 2 dispatched logs (original + retry)');

        $lastDispatched = end($dispatchedLogs);
        $payload = is_string($lastDispatched['payload']) ? json_decode($lastDispatched['payload'], true) : $lastDispatched['payload'];
        $this->assertTrue($payload['retry']);
        $this->assertEquals('Custom Preserved Caption #reels', $payload['caption']);
    }
}
