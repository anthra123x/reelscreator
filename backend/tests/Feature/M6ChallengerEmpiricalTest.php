<?php

namespace Tests\Feature;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Adapters\InstagramPublisherAdapter;
use App\Modules\Publishing\Adapters\TikTokPublisherAdapter;
use App\Modules\Publishing\Adapters\YouTubePublisherAdapter;
use App\Modules\Publishing\DTOs\PublishResult;
use App\Modules\Publishing\Models\Publication;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\SocialAccounts\Services\SocialAccountService;
use Carbon\Carbon;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class M6ChallengerEmpiricalTest extends TestCase
{
    private object $mockConn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.mock_mode' => true]);
        $this->mockConn = $this->setupMockTransactionalPostgresConnection([
            'users' => [
                [
                    'id' => '00000000-0000-0000-0000-000000000001',
                    'name' => 'Default Operator',
                    'email' => 'default@reelforge.internal',
                    'password' => bcrypt('password123'),
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ],
            'videos' => [],
            'clips' => [],
            'social_accounts' => [],
            'publications' => [],
            'publishing_logs' => [],
        ]);
    }

    private function setupMockTransactionalPostgresConnection(array $initialTables = []): object
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

            public array $transactionSnapshots = [];

            public ?\Closure $onInsertHook = null;

            public function beginTransaction(): void
            {
                $this->transactionSnapshots[] = $this->tables;
            }

            public function commit(): void
            {
                array_pop($this->transactionSnapshots);
            }

            public function rollBack($toLevel = null): void
            {
                if (! empty($this->transactionSnapshots)) {
                    $this->tables = array_pop($this->transactionSnapshots);
                }
            }

            public function transaction(\Closure $callback, $attempts = 1)
            {
                $this->beginTransaction();
                try {
                    $result = $callback($this);
                    $this->commit();

                    return $result;
                } catch (\Throwable $e) {
                    $this->rollBack();
                    throw $e;
                }
            }

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

                            if (preg_match('/count\(\*\)\s+as\s+aggregate/i', $query)) {
                                return [['aggregate' => count($filtered)]];
                            }

                            if (str_contains($query, 'limit 1')) {
                                return ! empty($filtered) ? [reset($filtered)] : [];
                            }

                            return array_values($filtered);
                        }

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
                if ($this->onInsertHook !== null) {
                    ($this->onInsertHook)($query, $bindings);
                }

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
                    if (empty($row['created_at'])) {
                        $row['created_at'] = now()->toISOString();
                    }
                    if (empty($row['updated_at'])) {
                        $row['updated_at'] = now()->toISOString();
                    }
                    $this->tables[$table][] = $row;
                }

                return true;
            }

            public function update($query, $bindings = []): int
            {
                if (preg_match('/update\s+"?([a-z_]+)"?\s+set\s+(.+?)(?:\s+where\s+(.+))?$/i', $query, $m)) {
                    $table = $m[1];
                    $whereClause = $m[3] ?? '';
                    if (isset($this->tables[$table]) && ! empty($this->tables[$table])) {
                        $targetIdx = count($this->tables[$table]) - 1;
                        if (preg_match('/"id"\s*=\s*\?/i', $whereClause) && ! empty($bindings)) {
                            $targetId = end($bindings);
                            foreach ($this->tables[$table] as $idx => $r) {
                                if (($r['id'] ?? null) === $targetId) {
                                    $targetIdx = $idx;
                                    break;
                                }
                            }
                        }

                        $setParts = explode(',', $m[2]);
                        $bIdx = 0;
                        foreach ($setParts as $part) {
                            if (preg_match('/"([^"]+)"\s*=/i', trim($part), $cm)) {
                                $this->tables[$table][$targetIdx][$cm[1]] = $bindings[$bIdx] ?? null;
                                $bIdx++;
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
     * EMPIRICAL TEST 1: Concurrency and Transactional Isolation:
     * Does platform: 'all' rollback cleanly if any step fails midway?
     */
    public function test_platform_all_rolls_back_atomically_if_step_fails_midway(): void
    {
        $service = app(SocialAccountService::class);
        $this->assertEmpty($this->mockConn->tables['social_accounts'], 'Precondition: table must start empty');

        // Configure onInsertHook: allow tiktok to insert, but throw exception on instagram!
        $this->mockConn->onInsertHook = function (string $query, array $bindings) {
            if (str_contains($query, 'social_accounts') && in_array('instagram', $bindings, true)) {
                throw new \RuntimeException('Simulated PostgreSQL write deadlock on instagram');
            }
        };

        $exceptionCaught = false;
        try {
            $service->simpleConnect('all', 'mock');
        } catch (\RuntimeException $e) {
            $exceptionCaught = true;
            $this->assertStringContainsString('Simulated PostgreSQL write deadlock on instagram', $e->getMessage());
        }

        $this->assertTrue($exceptionCaught, 'Expected RuntimeException to be thrown during simpleConnect');

        // Verification of atomicity & rollback: TikTok was processed first, but because Instagram failed,
        // DB::transaction must have executed rollBack(), leaving 0 social accounts in the database!
        $this->assertEmpty(
            $this->mockConn->tables['social_accounts'],
            'Atomicity failure: DB transaction did not roll back TikTok when Instagram failed!'
        );
    }

    /**
     * EMPIRICAL TEST 2: Concurrency & Referential Integrity:
     * Re-connecting an account while a publication job queries it preserves the foreign key UUID.
     */
    public function test_concurrent_reconnection_preserves_foreign_key_and_updates_credentials(): void
    {
        $service = app(SocialAccountService::class);

        // 1. Initial connection in mock mode
        $accounts = $service->simpleConnect('tiktok', 'mock');
        $this->assertCount(1, $accounts);
        $initialAccount = $accounts->first();
        $initialId = $initialAccount->id;
        $initialToken = $initialAccount->access_token;

        // 2. Simulate Publication created referencing this account
        $pubId = (string) Str::uuid();
        $clipId = (string) Str::uuid();
        $this->mockConn->tables['clips'][] = [
            'id' => $clipId,
            'video_id' => (string) Str::uuid(),
            'title' => 'Viral Reel',
            'file_url' => 'https://example.com/clips/viral.mp4',
            'duration' => 25.0,
            'start_time' => 0.0,
            'end_time' => 25.0,
            'status' => 'ready',
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];
        $this->mockConn->tables['publications'][] = [
            'id' => $pubId,
            'clip_id' => $clipId,
            'social_account_id' => $initialId,
            'status' => 'pending',
            'scheduled_at' => null,
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        // 3. Concurrently, operator re-connects TikTok with direct credentials
        $newDirectToken = 'direct_live_tt_token_998877';
        $newUsername = 'live_verified_creator';
        $reconnectedAccounts = $service->simpleConnect('tiktok', 'direct', [
            'access_token' => $newDirectToken,
            'username' => $newUsername,
        ]);

        $this->assertCount(1, $reconnectedAccounts);
        $updatedAccount = $reconnectedAccounts->first();

        // 4. Assert that the UUID primary key is strictly preserved
        $this->assertEquals($initialId, $updatedAccount->id, 'UUID must remain identical to preserve FK integrity');
        $this->assertEquals($newUsername, $updatedAccount->platform_username);

        // 5. Assert that when the Publication queries its socialAccount, the relationship resolves
        // with the updated credentials rather than breaking with a dangling foreign key
        $publication = Publication::with('socialAccount')->find($pubId);
        $this->assertNotNull($publication, 'Publication must exist');
        $this->assertNotNull($publication->socialAccount, 'Publication must still resolve socialAccount relation');
        $this->assertEquals($initialId, $publication->socialAccount->id);
        $this->assertEquals($newDirectToken, $publication->socialAccount->access_token);
    }

    /**
     * EMPIRICAL TEST 3: Direct Token Credential Injection (TikTok Live Dispatch):
     * When config('social.mock_mode') is false, mode 'direct' attempts live HTTP dispatch.
     */
    public function test_mode_direct_tiktok_attempts_live_dispatch_when_mock_mode_disabled(): void
    {
        config(['social.mock_mode' => false]);
        config(['social.tiktok.api_base' => 'https://open.tiktokapis.com/v2']);

        $service = app(SocialAccountService::class);
        $accounts = $service->simpleConnect('tiktok', 'direct', [
            'access_token' => 'live_tiktok_bearer_token_xyz123',
            'username' => 'tiktok_influencer',
        ]);
        $account = $accounts->first();

        $this->assertFalse($account->is_mock, 'Direct account must have is_mock = false');

        $clip = new Clip([
            'title' => 'TikTok Live Video',
            'file_path' => 'https://cdn.reelforge.internal/clips/tt_live.mp4',
            'duration' => 20.0,
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'data' => [
                    'publish_id' => 'v_pub_tiktok_live_success_99',
                ],
                'error' => [
                    'code' => 'ok',
                    'message' => '',
                ],
            ], 200),
        ]);

        $adapter = app(TikTokPublisherAdapter::class);
        $result = $adapter->publish($clip, $account, ['caption' => 'Live TikTok Test #FYP']);

        $this->assertTrue($result->success);
        $this->assertEquals('v_pub_tiktok_live_success_99', $result->platformPostId);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded);
        $req = $recorded[0][0];
        $this->assertEquals('https://open.tiktokapis.com/v2/post/publish/video/init/', $req->url());
        $this->assertEquals('Bearer live_tiktok_bearer_token_xyz123', $req->header('Authorization')[0] ?? null);
        $this->assertEquals('https://cdn.reelforge.internal/clips/tt_live.mp4', $req['source_info']['video_url']);
        $this->assertEquals('Live TikTok Test #FYP', $req['post_info']['title']);
    }

    /**
     * EMPIRICAL TEST 4: Direct Token Credential Injection (Instagram Live Dispatch):
     * When config('social.mock_mode') is false, mode 'direct' attempts 2-step media container & publish.
     */
    public function test_mode_direct_instagram_attempts_live_dispatch_when_mock_mode_disabled(): void
    {
        config(['social.mock_mode' => false]);
        config(['social.instagram.graph_base' => 'https://graph.facebook.com/v21.0']);

        $service = app(SocialAccountService::class);
        $accounts = $service->simpleConnect('instagram', 'direct', [
            'access_token' => 'live_instagram_graph_token_abc456',
            'username' => 'instagram_creator',
            'platform_user_id' => '17841400012345678',
        ]);
        $account = $accounts->first();

        $this->assertFalse($account->is_mock, 'Direct Instagram account must have is_mock = false');
        $this->assertEquals('17841400012345678', $account->platform_user_id);

        $clip = new Clip([
            'title' => 'Instagram Reel Live',
            'file_path' => 'https://cdn.reelforge.internal/clips/ig_live.mp4',
            'duration' => 45.0,
        ]);

        Http::fake([
            'https://graph.facebook.com/v21.0/17841400012345678/media' => Http::response([
                'id' => 'container_id_998877',
            ], 200),
            'https://graph.facebook.com/v21.0/17841400012345678/media_publish' => Http::response([
                'id' => 'ig_media_live_published_5544',
            ], 200),
        ]);

        $adapter = app(InstagramPublisherAdapter::class);
        $result = $adapter->publish($clip, $account, ['caption' => 'Live IG Reel #viral']);

        $this->assertTrue($result->success);
        $this->assertEquals('ig_media_live_published_5544', $result->platformPostId);

        Http::assertSent(function ($request) {
            if ($request->url() === 'https://graph.facebook.com/v21.0/17841400012345678/media') {
                return $request['access_token'] === 'live_instagram_graph_token_abc456' &&
                    $request['media_type'] === 'REELS' &&
                    $request['video_url'] === 'https://cdn.reelforge.internal/clips/ig_live.mp4';
            }
            if ($request->url() === 'https://graph.facebook.com/v21.0/17841400012345678/media_publish') {
                return $request['access_token'] === 'live_instagram_graph_token_abc456' &&
                    $request['creation_id'] === 'container_id_998877';
            }

            return false;
        });
    }

    /**
     * EMPIRICAL TEST 5: Direct Token Credential Injection (YouTube Live Dispatch):
     * When config('social.mock_mode') is false, mode 'direct' attempts live resumable upload init with #Shorts.
     */
    public function test_mode_direct_youtube_attempts_live_dispatch_when_mock_mode_disabled(): void
    {
        config(['social.mock_mode' => false]);
        config(['social.youtube.upload_base' => 'https://www.googleapis.com/upload/youtube/v3']);

        $service = app(SocialAccountService::class);
        $accounts = $service->simpleConnect('youtube', 'direct', [
            'access_token' => 'live_youtube_oauth_token_yt789',
            'username' => 'shorts_official_channel',
        ]);
        $account = $accounts->first();

        $this->assertFalse($account->is_mock, 'Direct YouTube account must have is_mock = false');

        $clip = new Clip([
            'title' => 'Epic YouTube Short',
            'file_path' => 'https://cdn.reelforge.internal/clips/yt_live.mp4',
            'duration' => 30.0,
        ]);

        Http::fake([
            'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response([
                'id' => 'yt_short_video_live_id_1122',
            ], 200, ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=resumable_session_1']),
        ]);

        $adapter = app(YouTubePublisherAdapter::class);
        $result = $adapter->publish($clip, $account, ['caption' => 'Check out this short']);

        $this->assertTrue($result->success);
        $this->assertEquals('yt_short_video_live_id_1122', $result->platformPostId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://www.googleapis.com/upload/youtube/v3/videos') &&
                $request->hasHeader('Authorization', 'Bearer live_youtube_oauth_token_yt789') &&
                str_contains($request['snippet']['title'], '#Shorts');
        });
    }

    /**
     * EMPIRICAL TEST 6: Mock Mode Override:
     * When config('social.mock_mode') is true, direct token accounts STILL delegate to mock adapter.
     */
    public function test_mode_direct_delegates_to_mock_when_mock_mode_is_true(): void
    {
        config(['social.mock_mode' => true]);

        $service = app(SocialAccountService::class);
        $accounts = $service->simpleConnect('tiktok', 'direct', [
            'access_token' => 'should_be_ignored_in_mock_mode',
            'username' => 'mock_override_user',
        ]);
        $account = $accounts->first();

        // When config('social.mock_mode') is true, getIsMockAttribute returns true!
        $this->assertTrue($account->is_mock, 'is_mock must be true when social.mock_mode is enabled');

        $clip = new Clip([
            'title' => 'Mock Mode Override Clip',
            'file_url' => 'https://cdn.reelforge.internal/clips/test.mp4',
            'duration' => 15.0,
        ]);

        Http::fake();

        $adapter = app(TikTokPublisherAdapter::class);
        $result = $adapter->publish($clip, $account);

        $this->assertTrue($result->success);
        $this->assertStringStartsWith('mock_tiktok_', $result->platformPostId);

        // No live HTTP requests must be sent
        Http::assertNothingSent();
    }

    /**
     * EMPIRICAL TEST 7: Mode 'env' with Complete Environment Variables:
     * Simulates TIKTOK_ACCESS_TOKEN, INSTAGRAM_ACCESS_TOKEN, YOUTUBE_ACCESS_TOKEN.
     */
    public function test_mode_env_with_complete_tokens_creates_live_accounts_and_dispatches(): void
    {
        config(['social.mock_mode' => false]);
        config([
            'social.tiktok.access_token' => 'env_tt_token_111',
            'social.tiktok.username' => 'env_tiktok_user',
            'social.instagram.access_token' => 'env_ig_token_222',
            'social.instagram.username' => 'env_ig_user',
            'social.instagram.account_id' => 'ig_act_99999',
            'social.youtube.access_token' => 'env_yt_token_333',
            'social.youtube.username' => 'env_yt_user',
        ]);

        $service = app(SocialAccountService::class);
        $accounts = $service->simpleConnect('all', 'env');

        $this->assertCount(3, $accounts);
        foreach ($accounts as $acc) {
            $this->assertFalse($acc->is_mock, "Platform {$acc->platform} should not be mock");
            $this->assertNotEmpty($acc->access_token, "Platform {$acc->platform} must have access token");
        }

        $ttAccount = $accounts->firstWhere('platform', 'tiktok');
        $this->assertEquals('env_tt_token_111', $ttAccount->access_token);
        $this->assertEquals('env_tiktok_user', $ttAccount->platform_username);

        $igAccount = $accounts->firstWhere('platform', 'instagram');
        $this->assertEquals('env_ig_token_222', $igAccount->access_token);
        $this->assertEquals('ig_act_99999', $igAccount->platform_user_id);

        $ytAccount = $accounts->firstWhere('platform', 'youtube');
        $this->assertEquals('env_yt_token_333', $ytAccount->access_token);
    }

    /**
     * EMPIRICAL TEST 8: Mode 'env' with Partial Environment Variables:
     * TikTok has env token, Instagram and YouTube do not -> TikTok gets live account, others fallback to mock.
     */
    public function test_mode_env_with_partial_tokens_creates_mixed_live_and_mock_accounts(): void
    {
        config(['social.mock_mode' => false]);
        config([
            'social.tiktok.access_token' => 'env_only_tiktok_token_555',
            'social.instagram.access_token' => null,
            'social.youtube.access_token' => '',
        ]);

        $service = app(SocialAccountService::class);
        $accounts = $service->simpleConnect('all', 'env');

        $this->assertCount(3, $accounts);

        $tt = $accounts->firstWhere('platform', 'tiktok');
        $this->assertFalse($tt->is_mock, 'TikTok should be live because env token is present');
        $this->assertEquals('env_only_tiktok_token_555', $tt->access_token);

        $ig = $accounts->firstWhere('platform', 'instagram');
        $this->assertTrue($ig->is_mock, 'Instagram should fallback to mock because env token is null');

        $yt = $accounts->firstWhere('platform', 'youtube');
        $this->assertTrue($yt->is_mock, 'YouTube should fallback to mock because env token is empty');
    }

    /**
     * EMPIRICAL TEST 9: Error Handling in Live Dispatch:
     * When live API returns 401, 400, or 500, adapter cleanly returns PublishResult::failure.
     */
    public function test_live_dispatch_returns_failure_result_when_remote_api_errors(): void
    {
        config(['social.mock_mode' => false]);

        $service = app(SocialAccountService::class);
        $accounts = $service->simpleConnect('tiktok', 'direct', [
            'access_token' => 'invalid_expired_direct_token',
        ]);
        $account = $accounts->first();

        $clip = new Clip([
            'title' => 'Test Clip',
            'file_url' => 'https://cdn.reelforge.internal/clips/test.mp4',
            'duration' => 20.0,
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'error' => [
                    'code' => 'access_token_invalid',
                    'message' => 'The access token provided has expired or is invalid.',
                ],
            ], 401),
        ]);

        $adapter = app(TikTokPublisherAdapter::class);
        $result = $adapter->publish($clip, $account);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('TikTok API Error', $result->errorMessage);
        $this->assertEquals(401, $result->metadata['status']);
    }

    /**
     * EMPIRICAL TEST 10: Expired Direct Token with No Refresh Token:
     * Adapter cleanly returns PublishResult::failure rather than crashing.
     */
    public function test_expired_token_without_refresh_token_fails_gracefully(): void
    {
        config(['social.mock_mode' => false]);

        $account = new SocialAccount;
        $account->id = (string) Str::uuid();
        $account->user_id = '00000000-0000-0000-0000-000000000001';
        $account->platform = SocialAccount::PLATFORM_TIKTOK;
        $account->platform_user_id = 'direct_tiktok_expired_user';
        $account->platform_username = 'expired_user';
        $account->access_token = 'expired_direct_token';
        $account->refresh_token = null;
        $account->expires_at = Carbon::now()->subMinutes(10); // Expired

        $clip = new Clip([
            'title' => 'Test Clip',
            'file_url' => 'https://cdn.reelforge.internal/clips/test.mp4',
            'duration' => 20.0,
        ]);

        $adapter = app(TikTokPublisherAdapter::class);
        $result = $adapter->publish($clip, $account);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Failed to refresh expired TikTok OAuth token', $result->errorMessage);
    }

    /**
     * EMPIRICAL TEST 11: Rapid Sequential Re-connections on Same Platform:
     * Alternating between modes mock, direct, and env preserves single record idempotency and UUID.
     */
    public function test_rapid_sequential_reconnections_preserve_single_row_and_uuid(): void
    {
        $service = app(SocialAccountService::class);

        // 1. First: mock mode
        $acc1 = $service->simpleConnect('tiktok', 'mock', ['username' => 'user_mock'])->first();
        $uuid = $acc1->id;

        // 2. Second: direct mode
        $acc2 = $service->simpleConnect('tiktok', 'direct', [
            'username' => 'user_direct',
            'access_token' => 'token_direct_1',
        ])->first();
        $this->assertEquals($uuid, $acc2->id);
        $this->assertEquals('user_direct', $acc2->platform_username);

        // 3. Third: env mode
        config(['social.tiktok.access_token' => 'token_env_1', 'social.tiktok.username' => 'user_env']);
        $acc3 = $service->simpleConnect('tiktok', 'env')->first();
        $this->assertEquals($uuid, $acc3->id);
        $this->assertEquals('token_env_1', $acc3->access_token);

        // 4. Fourth: direct mode update
        $acc4 = $service->simpleConnect('tiktok', 'direct', [
            'username' => 'user_direct_updated',
            'access_token' => 'token_direct_updated',
        ])->first();
        $this->assertEquals($uuid, $acc4->id);
        $this->assertEquals('user_direct_updated', $acc4->platform_username);
        $this->assertEquals('token_direct_updated', $acc4->access_token);

        // Verify that across all 4 operations, exactly 1 row exists for this platform
        $allTikTokAccounts = array_filter(
            $this->mockConn->tables['social_accounts'],
            fn ($r) => $r['platform'] === 'tiktok'
        );
        $this->assertCount(1, $allTikTokAccounts, 'There must be exactly 1 TikTok record, not duplicates');
    }

    /**
     * EMPIRICAL TEST 12: Direct Token with Empty String Fallback:
     * When access_token is empty string, adapter safely falls back to mock rather than sending empty Bearer.
     */
    public function test_mode_direct_with_empty_token_safely_delegates_to_mock(): void
    {
        config(['social.mock_mode' => false]);

        $service = app(SocialAccountService::class);
        $accounts = $service->simpleConnect('tiktok', 'direct', [
            'access_token' => '', // Empty string passed
            'username' => 'empty_token_user',
        ]);
        $account = $accounts->first();

        $clip = new Clip([
            'title' => 'Test Clip',
            'file_path' => 'https://cdn.reelforge.internal/clips/test.mp4',
            'duration' => 20.0,
        ]);

        Http::fake();

        $adapter = app(TikTokPublisherAdapter::class);
        $result = $adapter->publish($clip, $account);

        $this->assertTrue($result->success);
        $this->assertStringStartsWith('mock_tiktok_', $result->platformPostId);
        Http::assertNothingSent();
    }

    /**
     * EMPIRICAL TEST 13: HTTP Endpoint POST /api/social-accounts/connect with Mode Direct:
     * Verifies HTTP API response contract and is_mock flag under live config.
     */
    public function test_http_connect_endpoint_mode_direct_returns_valid_live_contract(): void
    {
        config(['social.mock_mode' => false]);

        $response = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'all',
            'mode' => 'direct',
            'access_token' => 'universal_direct_token_test',
            'username' => 'cross_platform_creator',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'connected' => [
                    '*' => [
                        'id',
                        'platform',
                        'platform_username',
                        'is_mock',
                        'status',
                        'is_active',
                        'connected_at',
                    ],
                ],
            ]);

        $connected = $response->json('connected');
        $this->assertCount(3, $connected);

        foreach ($connected as $item) {
            $this->assertFalse($item['is_mock'], "Platform {$item['platform']} should have is_mock = false");
            $this->assertEquals('connected', $item['status']);
            $this->assertTrue($item['is_active']);
            $this->assertEquals('cross_platform_creator', $item['platform_username']);
        }
    }
}
