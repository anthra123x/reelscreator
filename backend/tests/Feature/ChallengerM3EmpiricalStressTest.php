<?php

namespace Tests\Feature;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Adapters\MockSocialAdapter;
use App\Modules\Publishing\Adapters\TikTokPublisherAdapter;
use App\Modules\Publishing\Adapters\YouTubePublisherAdapter;
use App\Modules\Publishing\DTOs\PublishResult;
use App\Modules\Publishing\Jobs\PublishToTikTokJob;
use App\Modules\Publishing\Jobs\PublishToYouTubeJob;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublishingLog;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\SocialAccounts\Services\TikTokOAuthService;
use Carbon\Carbon;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class ChallengerM3EmpiricalStressTest extends TestCase
{
    private object $mockConn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockConn = $this->setupMockPostgresConnection();
    }

    private function setupMockPostgresConnection(): object
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

        $this->app['config']->set('database.default', 'pgsql');
        $this->app['db']->setDefaultConnection('pgsql');
        $this->app['db']->purge('pgsql');
        $this->app['db']->purge('sqlite');
        $this->app['db']->extend('pgsql', fn () => $mockConn);
        $this->app['db']->extend('sqlite', fn () => $mockConn);

        return $mockConn;
    }

    /**
     * 1. STRESS TEST: MockSocialAdapter YouTube Shorts Duration Boundaries
     */
    public function test_mock_social_adapter_youtube_shorts_boundaries(): void
    {
        $adapter = new MockSocialAdapter('youtube');
        $account = new SocialAccount([
            'platform' => SocialAccount::PLATFORM_YOUTUBE,
            'access_token' => 'mock_token',
        ]);

        // Exact boundary: 60.0 seconds must succeed
        $clip60 = new Clip(['duration' => 60.0]);
        $res60 = $adapter->publish($clip60, $account);
        $this->assertTrue($res60->success, '60.0s clip must succeed on YouTube Shorts');

        // Sub-boundary: 59.999 seconds must succeed
        $clip59 = new Clip(['duration' => 59.999]);
        $res59 = $adapter->publish($clip59, $account);
        $this->assertTrue($res59->success, '59.999s clip must succeed on YouTube Shorts');

        // Over boundary: 60.0001 seconds must fail
        $clip60_0001 = new Clip(['duration' => 60.0001]);
        $res60_0001 = $adapter->publish($clip60_0001, $account);
        $this->assertFalse($res60_0001->success, '60.0001s clip must be rejected for YouTube Shorts');
        $this->assertStringContainsString('PlatformConstraintViolation', $res60_0001->errorMessage);
        $this->assertEquals(60.0, $res60_0001->metadata['max_duration']);

        // Extreme duration: 300.0s must fail
        $clip300 = new Clip(['duration' => 300.0]);
        $res300 = $adapter->publish($clip300, $account);
        $this->assertFalse($res300->success);
        $this->assertStringContainsString('YouTube Shorts duration cannot exceed 60 seconds', $res300->errorMessage);

        // Zero and negative durations must be rejected by clip validation
        $clip0 = new Clip(['duration' => 0.0]);
        $res0 = $adapter->publish($clip0, $account);
        $this->assertFalse($res0->success);
        $this->assertStringContainsString('Invalid clip duration: 0s', $res0->errorMessage);

        $clipNegative = new Clip(['duration' => -15.5]);
        $resNeg = $adapter->publish($clipNegative, $account);
        $this->assertFalse($resNeg->success);
        $this->assertStringContainsString('Invalid clip duration: -15.5s', $resNeg->errorMessage);
    }

    /**
     * 1b. STRESS TEST: MockSocialAdapter Instagram Reels Duration Boundaries
     */
    public function test_mock_social_adapter_instagram_reels_boundaries(): void
    {
        $adapter = new MockSocialAdapter('instagram');
        $account = new SocialAccount([
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'access_token' => 'mock_token',
        ]);

        // Exact boundary: 90.0s must succeed
        $clip90 = new Clip(['duration' => 90.0]);
        $res90 = $adapter->publish($clip90, $account);
        $this->assertTrue($res90->success, '90.0s clip must succeed on Instagram Reels');

        // Over boundary: 90.001s must fail
        $clip90_001 = new Clip(['duration' => 90.001]);
        $res90_001 = $adapter->publish($clip90_001, $account);
        $this->assertFalse($res90_001->success);
        $this->assertStringContainsString('PlatformConstraintViolation: Instagram Reels duration cannot exceed 90 seconds', $res90_001->errorMessage);
    }

    /**
     * 1c. STRESS TEST: simulate_token_expired and Expired Account Auto-Refresh Matrix
     */
    public function test_mock_social_adapter_token_simulation_and_account_expiry(): void
    {
        $adapter = new MockSocialAdapter('tiktok');
        $clip = new Clip(['duration' => 20.0]);

        // 1. Explicit simulate_token_expired flag returns TokenExpiredException
        $accountActive = new SocialAccount([
            'platform' => 'tiktok',
            'access_token' => 'valid_token',
            'expires_at' => Carbon::now()->addDays(5),
        ]);
        $resSimExpired = $adapter->publish($clip, $accountActive, ['simulate_token_expired' => true]);
        $this->assertFalse($resSimExpired->success);
        $this->assertStringContainsString('TokenExpiredException: OAuth access token has expired', $resSimExpired->errorMessage);
        $this->assertSame('token_expired', $resSimExpired->metadata['error_type']);

        // 2. Expired account with auto-refresh (default): refreshes token and succeeds
        $accountExpired = new SocialAccount([
            'platform' => 'tiktok',
            'platform_username' => 'creator_normal',
            'access_token' => 'expired_token_123',
            'expires_at' => Carbon::now()->subHours(2),
        ]);
        $this->assertTrue($accountExpired->isExpired());
        $resAutoRefresh = $adapter->publish($clip, $accountExpired);
        $this->assertTrue($resAutoRefresh->success, 'Expired account must automatically refresh and succeed');
        $this->assertNotEquals('expired_token_123', $accountExpired->access_token);
        $this->assertFalse($accountExpired->isExpired());

        // 3. Expired account with skip_token_refresh flag: must return failure
        $accountExpiredNoRefresh = new SocialAccount([
            'platform' => 'tiktok',
            'access_token' => 'expired_token_no_refresh',
            'expires_at' => Carbon::now()->subMinutes(30),
        ]);
        $resSkipRefresh = $adapter->publish($clip, $accountExpiredNoRefresh, ['skip_token_refresh' => true]);
        $this->assertFalse($resSkipRefresh->success);
        $this->assertStringContainsString('TokenExpiredException: OAuth access token has expired', $resSkipRefresh->errorMessage);

        // 4. Expired account with simulated refresh failure (fail_refresh username)
        $accountFailRefresh = new SocialAccount([
            'platform' => 'tiktok',
            'platform_username' => 'fail_refresh_user_bot',
            'access_token' => 'dead_token',
            'expires_at' => Carbon::now()->subDay(),
        ]);
        $resRefreshFail = $adapter->publish($clip, $accountFailRefresh);
        $this->assertFalse($resRefreshFail->success);
        $this->assertStringContainsString('TokenExpiredException: OAuth access token has expired and refresh failed', $resRefreshFail->errorMessage);
    }

    /**
     * 1d. STRESS TEST: simulate_network_failure and simulate_chunking
     */
    public function test_mock_social_adapter_network_failure_and_chunking(): void
    {
        $adapter = new MockSocialAdapter('tiktok');
        $clip = new Clip(['duration' => 30.0]);
        $account = new SocialAccount([
            'platform' => 'tiktok',
            'access_token' => 'valid_token',
        ]);

        // simulate_network_failure
        $resNetFail = $adapter->publish($clip, $account, ['simulate_network_failure' => true]);
        $this->assertFalse($resNetFail->success);
        $this->assertStringContainsString('Simulated network failure: connection timed out while uploading to tiktok', $resNetFail->errorMessage);
        $this->assertTrue($resNetFail->metadata['retryable']);
        $this->assertTrue($resNetFail->metadata['simulated']);

        // simulate_chunking with 6 chunks of 2MB each
        $resChunk = $adapter->publish($clip, $account, [
            'simulate_chunking' => true,
            'chunk_size' => 2097152,
            'total_chunks' => 6,
        ]);
        $this->assertTrue($resChunk->success);
        $this->assertTrue($resChunk->metadata['chunked_upload']);
        $this->assertSame(6, $resChunk->metadata['total_chunks']);
        $this->assertSame(2097152, $resChunk->metadata['chunk_size']);
        $this->assertCount(6, $resChunk->metadata['chunks']);
        $this->assertEquals(2097152 * 6, $resChunk->metadata['chunks'][5]['bytes_uploaded']);
    }

    /**
     * 2. STRESS TEST: Event Logging in publishing_logs & Payload JSONB Resilience
     */
    public function test_publishing_logs_persistence_and_jsonb_adversarial_payload(): void
    {
        $pubId = (string) Str::uuid();
        $clipId = (string) Str::uuid();
        $accId = (string) Str::uuid();

        // Complex adversarial payload including unicode, emojis, html/xss, math boundaries
        $adversarialPayload = [
            'attempt' => 1,
            'max_tries' => 3,
            'emojis' => '🎬🔥🚀✨💯🎉',
            'unicode' => '日本語 العربية / עִבְרִית / español: áéíóú ñ',
            'xss' => '<script>alert("xss")</script>',
            'sql_injection' => "'; DROP TABLE publishing_logs; --",
            'nested' => [
                'metadata' => [
                    'float_val' => 3.14159265,
                    'int_negative' => -42,
                    'null_field' => null,
                    'bool_field' => true,
                ],
            ],
        ];

        $log = PublishingLog::create([
            'publication_id' => $pubId,
            'clip_id' => $clipId,
            'social_account_id' => $accId,
            'platform' => 'tiktok',
            'event' => PublishingLog::EVENT_UPLOAD_STARTED,
            'status' => Publication::STATUS_PUBLISHING,
            'payload' => $adversarialPayload,
            'error_message' => null,
        ]);

        $this->assertNotNull($log->id);
        $this->assertEquals($pubId, $log->publication_id);
        $this->assertEquals($clipId, $log->clip_id);
        $this->assertEquals($accId, $log->social_account_id);
        $this->assertEquals('tiktok', $log->platform);
        $this->assertEquals(PublishingLog::EVENT_UPLOAD_STARTED, $log->event);

        // Assert JSONB payload casting roundtrip
        $this->assertIsArray($log->payload);
        $this->assertEquals('🎬🔥🚀✨💯🎉', $log->payload['emojis']);
        $this->assertEquals("'; DROP TABLE publishing_logs; --", $log->payload['sql_injection']);
        $this->assertEquals(3.14159265, $log->payload['nested']['metadata']['float_val']);
        $this->assertTrue($log->payload['nested']['metadata']['bool_field']);
    }

    /**
     * 3. STRESS TEST: Queue Jobs Retry Logic and Exponential Backoff Lifecycle
     */
    public function test_queue_job_retry_logic_and_backoff_lifecycle(): void
    {
        $pubId = (string) Str::uuid();
        $clipId = (string) Str::uuid();
        $accId = (string) Str::uuid();

        $this->mockConn->tables['clips'][] = [
            'id' => $clipId,
            'title' => 'Challenger Video',
            'duration' => 25.0,
            'file_path' => 'clips/stress.mp4',
            'status' => Clip::STATUS_READY,
        ];
        $this->mockConn->tables['social_accounts'][] = [
            'id' => $accId,
            'platform' => SocialAccount::PLATFORM_TIKTOK,
            'platform_username' => 'stress_tester',
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
        $adapter = new TikTokPublisherAdapter(new TikTokOAuthService);

        // Contract assertions on job
        $sampleJob = new PublishToTikTokJob($pubId);
        $this->assertSame(3, $sampleJob->tries);
        $this->assertSame(300, $sampleJob->timeout);
        $this->assertEquals([20, 40, 80], $sampleJob->backoff());
        $middleware = $sampleJob->middleware();
        $this->assertNotEmpty($middleware);

        // Attempt 1: Must release with delay pow(2, 1) * 10 = 20s
        $jobAttempt1 = new class($pubId, ['simulate_network_failure' => true]) extends PublishToTikTokJob
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
        $this->assertTrue($jobAttempt1->released, 'Attempt 1 must release the job');
        $this->assertEquals(20, $jobAttempt1->releaseDelay, 'Attempt 1 backoff must be 20 seconds');

        // Check retry_attempted log for attempt 1
        $retryLogs1 = array_filter(
            $this->mockConn->tables['publishing_logs'],
            fn ($l) => ($l['event'] ?? '') === PublishingLog::EVENT_RETRY_ATTEMPTED
        );
        $this->assertCount(1, $retryLogs1);
        $payload1 = is_string(reset($retryLogs1)['payload']) ? json_decode(reset($retryLogs1)['payload'], true) : reset($retryLogs1)['payload'];
        $this->assertEquals(1, $payload1['attempt']);
        $this->assertEquals(20, $payload1['next_retry_delay_seconds']);

        // Attempt 2: Must release with delay pow(2, 2) * 10 = 40s
        $jobAttempt2 = new class($pubId, ['simulate_network_failure' => true]) extends PublishToTikTokJob
        {
            public int $mockAttempts = 2;

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

        $jobAttempt2->handle($adapter);
        $this->assertTrue($jobAttempt2->released, 'Attempt 2 must release the job');
        $this->assertEquals(40, $jobAttempt2->releaseDelay, 'Attempt 2 backoff must be 40 seconds');

        // Attempt 3: Exhausted ($tries = 3), must NOT release, must log failed event
        $jobAttempt3 = new class($pubId, ['simulate_network_failure' => true]) extends PublishToTikTokJob
        {
            public int $mockAttempts = 3;

            public bool $released = false;

            public function attempts(): int
            {
                return $this->mockAttempts;
            }

            public function release($delay = 0)
            {
                $this->released = true;
            }
        };

        $jobAttempt3->handle($adapter);
        $this->assertFalse($jobAttempt3->released, 'Attempt 3 (exhausted) must NOT release');

        $failedLogs = array_filter(
            $this->mockConn->tables['publishing_logs'],
            fn ($l) => ($l['event'] ?? '') === PublishingLog::EVENT_FAILED
        );
        $this->assertCount(1, $failedLogs);
        $failedLog = reset($failedLogs);
        $failedPayload = is_string($failedLog['payload']) ? json_decode($failedLog['payload'], true) : $failedLog['payload'];
        $this->assertEquals(3, $failedPayload['attempt']);
        $this->assertStringContainsString('Simulated network failure', $failedLog['error_message']);
    }

    /**
     * 3b. STRESS TEST: Unhandled Exception during PublishToYouTubeJob Queue Execution
     */
    public function test_queue_job_exception_recovery_and_release(): void
    {
        $pubId = (string) Str::uuid();
        $clipId = (string) Str::uuid();
        $accId = (string) Str::uuid();

        $this->mockConn->tables['clips'][] = [
            'id' => $clipId,
            'title' => 'Exception Video',
            'duration' => 25.0,
            'file_path' => 'clips/stress.mp4',
            'status' => Clip::STATUS_READY,
        ];
        $this->mockConn->tables['social_accounts'][] = [
            'id' => $accId,
            'platform' => SocialAccount::PLATFORM_YOUTUBE,
            'platform_username' => 'yt_exception_tester',
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

        // An adapter throwing an unhandled runtime exception
        $crashingAdapter = new class extends YouTubePublisherAdapter
        {
            public function __construct() {}

            public function publish(Clip $clip, SocialAccount $account, array $options = []): PublishResult
            {
                throw new \DomainException('Unexpected external socket corruption during YouTube upload');
            }
        };

        // Attempt 1 with exception: logs retry_attempted and releases with 20s
        $jobCrashAttempt1 = new class($pubId) extends PublishToYouTubeJob
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

        $jobCrashAttempt1->handle($crashingAdapter);
        $this->assertTrue($jobCrashAttempt1->released, 'Exception on attempt 1 must trigger release');
        $this->assertEquals(20, $jobCrashAttempt1->releaseDelay);

        $crashLogs = array_filter(
            $this->mockConn->tables['publishing_logs'],
            fn ($l) => ($l['event'] ?? '') === PublishingLog::EVENT_RETRY_ATTEMPTED && str_contains($l['error_message'] ?? '', 'Unexpected external socket corruption')
        );
        $this->assertCount(1, $crashLogs);
        $crashPayload = is_string(reset($crashLogs)['payload']) ? json_decode(reset($crashLogs)['payload'], true) : reset($crashLogs)['payload'];
        $this->assertEquals(\DomainException::class, $crashPayload['exception']);

        // Attempt 3 with exception: rethrows, marks failed, logs EVENT_FAILED
        $jobCrashAttempt3 = new class($pubId) extends PublishToYouTubeJob
        {
            public int $mockAttempts = 3;

            public function attempts(): int
            {
                return $this->mockAttempts;
            }
        };

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Unexpected external socket corruption during YouTube upload');
        $jobCrashAttempt3->handle($crashingAdapter);
    }
}
