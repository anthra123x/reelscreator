<?php

namespace Tests\Feature;

use App\Modules\SocialAccounts\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class M6AdversarialEmpiricalChallengeTest extends TestCase
{
    private object $mockConn;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.mock_mode' => true]);
        $this->mockConn = $this->setupMockPostgresConnection([
            'users' => [
                [
                    'id' => '00000000-0000-0000-0000-000000000001',
                    'name' => 'Adversarial Operator',
                    'email' => 'operator@reelforge.internal',
                    'password' => bcrypt('password123'),
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ],
            ],
            'social_accounts' => [],
        ]);
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

            public function transaction(\Closure $callback, $attempts = 1)
            {
                return $callback($this);
            }

            public function beginTransaction() {}

            public function commit() {}

            public function rollBack($toLevel = null) {}

            public function select($query, $bindings = [], $useReadPdo = true): array
            {
                if (preg_match('/from\s+"?([a-z_]+)"?/i', $query, $m)) {
                    $table = $m[1];
                    if (isset($this->tables[$table])) {
                        $rows = $this->tables[$table];

                        // Match where "user_id" = ? and "platform" = ?
                        if (preg_match('/"user_id"\s*=\s*\?\s+and\s+"platform"\s*=\s*\?/i', $query) && count($bindings) >= 2) {
                            $userId = $bindings[0];
                            $platform = $bindings[1];
                            $filtered = array_filter($rows, fn ($r) => ($r['user_id'] ?? null) === $userId && ($r['platform'] ?? null) === $platform);

                            return ! empty($filtered) ? [reset($filtered)] : [];
                        }

                        // Match where "id" = ?
                        if (preg_match('/"id"\s*=\s*\?/i', $query) && count($bindings) >= 1) {
                            $id = $bindings[0];
                            $filtered = array_filter($rows, fn ($r) => ($r['id'] ?? null) === $id);

                            return ! empty($filtered) ? [reset($filtered)] : [];
                        }

                        // Match where "user_id" = ?
                        if (preg_match('/"user_id"\s*=\s*\?/i', $query) && count($bindings) >= 1) {
                            $userId = $bindings[0];
                            $filtered = array_filter($rows, fn ($r) => ($r['user_id'] ?? null) === $userId);

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

                        // Match by id if present in WHERE
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
     * CHALLENGE 1.1: Stress Repeated Sequential Connections (Idempotency Harness).
     * Verify that 10 sequential calls with identical or updated payloads strictly preserve
     * the existing row UUID and maintain exactly 1 database row for the platform.
     */
    public function test_adversarial_repeated_calls_preserve_uuid_and_zero_duplicate_rows(): void
    {
        $platform = 'tiktok';
        $originalUuid = null;

        for ($i = 1; $i <= 10; $i++) {
            $response = $this->postJson('/api/social-accounts/connect', [
                'platform' => $platform,
                'mode' => 'mock',
                'username' => "iteration_handle_{$i}",
            ]);

            $response->assertStatus(200);
            $currentUuid = $response->json('connected.0.id');
            $this->assertNotEmpty($currentUuid, "Iteration {$i} must return a non-empty UUID");

            if ($originalUuid === null) {
                $originalUuid = $currentUuid;
            } else {
                $this->assertEquals(
                    $originalUuid,
                    $currentUuid,
                    "Iteration {$i} failed: UUID was not preserved across repeated calls"
                );
            }

            // Empirical verification against raw database table
            $rows = array_values(array_filter(
                $this->mockConn->tables['social_accounts'],
                fn ($r) => $r['platform'] === $platform
            ));

            $this->assertCount(
                1,
                $rows,
                "Iteration {$i} failed: Expected exactly 1 row for {$platform}, found ".count($rows)
            );
            $this->assertEquals("iteration_handle_{$i}", $rows[0]['platform_username']);
            $this->assertEquals($originalUuid, $rows[0]['id']);
        }
    }

    /**
     * CHALLENGE 1.2: Atomic Connection of All Platforms (platform: 'all').
     * Verify that platform: 'all' connects all 3 platforms (tiktok, instagram, youtube),
     * produces a 3-element connected array, does not populate top-level 'account',
     * and persists exactly 3 rows in the database.
     */
    public function test_adversarial_atomic_platform_all_connects_exactly_three_platforms(): void
    {
        $response = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'all',
            'mode' => 'mock',
            'username' => 'creator_multichannel',
        ]);

        $response->assertStatus(200);

        // Verify connected array structure
        $connected = $response->json('connected');
        $this->assertIsArray($connected);
        $this->assertCount(3, $connected, 'Must connect exactly 3 platforms');

        // Top-level 'account' should be omitted when multiple accounts are connected
        $this->assertNull($response->json('account'), "Top-level 'account' key must only exist for single-account responses");

        $expectedPlatforms = ['instagram', 'tiktok', 'youtube'];
        $receivedPlatforms = array_column($connected, 'platform');
        sort($receivedPlatforms);
        $this->assertEquals($expectedPlatforms, $receivedPlatforms);

        // Database row verification
        $this->assertCount(3, $this->mockConn->tables['social_accounts']);
        $dbPlatforms = array_column($this->mockConn->tables['social_accounts'], 'platform');
        sort($dbPlatforms);
        $this->assertEquals($expectedPlatforms, $dbPlatforms);

        // Repeated atomic connection must preserve all 3 UUIDs
        $initialUuids = array_column($connected, 'id', 'platform');

        $repeatResponse = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'all',
            'mode' => 'mock',
            'username' => 'creator_multichannel_updated',
        ]);

        $repeatResponse->assertStatus(200);
        $repeatConnected = $repeatResponse->json('connected');
        $repeatUuids = array_column($repeatConnected, 'id', 'platform');

        $this->assertEquals($initialUuids, $repeatUuids, 'All 3 UUIDs must be strictly preserved on repeated all connection');
        $this->assertCount(3, $this->mockConn->tables['social_accounts'], 'Database table must maintain exactly 3 rows');
    }

    /**
     * CHALLENGE 1.3: Mode 'env' Fallback when Environment Tokens are Missing.
     * When mode is 'env':
     * - Configured platform (tiktok) uses real credentials (is_mock = false).
     * - Unconfigured platform (youtube) cleanly falls back to mock (is_mock = true).
     * - Mock tokens generated are valid non-empty strings.
     */
    public function test_adversarial_env_mode_fallback_when_tokens_missing(): void
    {
        config([
            'social.mock_mode' => false,
            'social.tiktok.access_token' => 'live_tiktok_env_secret_token_12345',
            'social.tiktok.refresh_token' => 'live_tiktok_refresh_54321',
            'social.tiktok.username' => 'tiktok_live_operator',
            'social.youtube.access_token' => null, // Missing!
            'social.youtube.refresh_token' => null,
            'social.youtube.username' => null,
        ]);

        // Connect YouTube with missing env token
        $responseMissing = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'youtube',
            'mode' => 'env',
            'username' => 'fallback_user_shorts',
        ]);

        $responseMissing->assertStatus(200);
        $ytAccount = $responseMissing->json('connected.0');
        $this->assertEquals('youtube', $ytAccount['platform']);
        $this->assertTrue($ytAccount['is_mock'], 'Account must cleanly fall back to mock when env token is absent');
        $this->assertEquals('connected', $ytAccount['status']);
        $this->assertTrue($ytAccount['is_active']);

        // Verify valid mock token was stored in DB
        $ytDb = SocialAccount::find($ytAccount['id']);
        $this->assertNotNull($ytDb);
        $this->assertNotEmpty($ytDb->access_token, 'Mock access token must be non-empty');
        $this->assertStringStartsWith('mock_access_token_', $ytDb->access_token);

        // Now connect TikTok with configured env token
        $responseConfigured = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'tiktok',
            'mode' => 'env',
        ]);

        $responseConfigured->assertStatus(200);
        $ttAccount = $responseConfigured->json('connected.0');
        $this->assertEquals('tiktok', $ttAccount['platform']);
        $this->assertFalse($ttAccount['is_mock'], 'Account with configured env token must NOT be mock');
        $this->assertEquals('tiktok_live_operator', $ttAccount['platform_username']);

        $ttDb = SocialAccount::find($ttAccount['id']);
        $this->assertEquals('live_tiktok_env_secret_token_12345', $ttDb->access_token);
        $this->assertEquals('live_tiktok_refresh_54321', $ttDb->refresh_token);
    }

    /**
     * CHALLENGE 1.4: Input Validation and Hostile Payloads.
     * Verify that invalid platforms and invalid modes return HTTP 422 with structured validation errors.
     */
    public function test_adversarial_invalid_inputs_return_http_422(): void
    {
        // 1. Invalid platform: 'facebook'
        $resFb = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'facebook',
            'mode' => 'mock',
        ]);
        $resFb->assertStatus(422)
            ->assertJsonValidationErrors(['platform']);
        $this->assertEquals(
            'The selected platform is invalid.',
            $resFb->json('errors.platform.0')
        );

        // 2. Invalid mode: 'hacked'
        $resHacked = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'tiktok',
            'mode' => 'hacked',
        ]);
        $resHacked->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);
        $this->assertEquals(
            'The selected mode is invalid.',
            $resHacked->json('errors.mode.0')
        );

        // 3. Both invalid
        $resBoth = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'linkedin',
            'mode' => 'bypass',
        ]);
        $resBoth->assertStatus(422)
            ->assertJsonValidationErrors(['platform', 'mode']);

        // 4. Hostile SQL injection string in platform
        $resSql = $this->postJson('/api/social-accounts/connect', [
            'platform' => "tiktok'; DROP TABLE social_accounts;--",
        ]);
        $resSql->assertStatus(422)
            ->assertJsonValidationErrors(['platform']);

        // 5. Missing required platform
        $resEmpty = $this->postJson('/api/social-accounts/connect', []);
        $resEmpty->assertStatus(422)
            ->assertJsonValidationErrors(['platform']);

        // 6. Username exceeding 255 chars boundary
        $resLongUser = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'instagram',
            'username' => str_repeat('X', 256),
        ]);
        $resLongUser->assertStatus(422)
            ->assertJsonValidationErrors(['username']);

        // 7. Exactly 255 chars username boundary (allowed)
        $resBoundaryUser = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'instagram',
            'username' => str_repeat('Y', 255),
        ]);
        $resBoundaryUser->assertStatus(200);
    }

    /**
     * CHALLENGE 2.1: Token Encryption at Rest & Zero Response Leakage.
     * Verify that:
     * 1. Access and refresh tokens are encrypted at rest in the database (raw stored string is ciphertext).
     * 2. Ciphertext can be decrypted with Crypt::decryptString to the original plaintext.
     * 3. No token (access_token, refresh_token) appears in the JSON response of connect or index endpoints.
     */
    public function test_adversarial_token_encryption_and_zero_response_leakage(): void
    {
        config(['social.mock_mode' => false]);
        $secretPlainToken = 'SECRET_LIVE_PLAINTEXT_BEARER_TOKEN_999888';
        $secretRefreshToken = 'SECRET_LIVE_REFRESH_TOKEN_111222';

        $response = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'instagram',
            'mode' => 'direct',
            'username' => 'encrypted_creator',
            'access_token' => $secretPlainToken,
            'refresh_token' => $secretRefreshToken,
        ]);

        $response->assertStatus(200);

        // LEAK CHECK: Recursive scan of response payload
        $responseBody = $response->getContent();
        $this->assertStringNotContainsString(
            $secretPlainToken,
            $responseBody,
            'CRITICAL SECURITY VULNERABILITY: Plaintext access token leaked in JSON response!'
        );
        $this->assertStringNotContainsString(
            $secretRefreshToken,
            $responseBody,
            'CRITICAL SECURITY VULNERABILITY: Plaintext refresh token leaked in JSON response!'
        );
        $this->assertArrayNotHasKey(
            'access_token',
            $response->json('connected.0'),
            'access_token key must not exist in connect response items'
        );
        $this->assertArrayNotHasKey(
            'refresh_token',
            $response->json('connected.0'),
            'refresh_token key must not exist in connect response items'
        );

        // RAW DATABASE ENCRYPTION VERIFICATION
        $igRow = reset($this->mockConn->tables['social_accounts']);
        $this->assertNotEmpty($igRow, 'Row must exist in mock database');

        $rawStoredAccessToken = $igRow['access_token'];
        $rawStoredRefreshToken = $igRow['refresh_token'];

        $this->assertNotEquals(
            $secretPlainToken,
            $rawStoredAccessToken,
            'Access token MUST NOT be stored in plaintext in the database'
        );
        $this->assertNotEquals(
            $secretRefreshToken,
            $rawStoredRefreshToken,
            'Refresh token MUST NOT be stored in plaintext in the database'
        );

        // Must be decryptable using Laravel's Crypt facade
        $decryptedAccess = Crypt::decryptString($rawStoredAccessToken);
        $decryptedRefresh = Crypt::decryptString($rawStoredRefreshToken);

        $this->assertEquals($secretPlainToken, $decryptedAccess);
        $this->assertEquals($secretRefreshToken, $decryptedRefresh);

        // LEAK CHECK ON GET /api/social-accounts (index endpoint)
        $indexResponse = $this->getJson('/api/social-accounts');
        $indexResponse->assertStatus(200);
        $indexBody = $indexResponse->getContent();

        $this->assertStringNotContainsString(
            $secretPlainToken,
            $indexBody,
            'CRITICAL SECURITY VULNERABILITY: Plaintext access token leaked in index JSON response!'
        );
        $this->assertStringNotContainsString(
            $secretRefreshToken,
            $indexBody,
            'CRITICAL SECURITY VULNERABILITY: Plaintext refresh token leaked in index JSON response!'
        );
        $this->assertArrayNotHasKey(
            'access_token',
            $indexResponse->json('data.0'),
            'access_token key must not exist in index response items'
        );
    }

    /**
     * CHALLENGE 2.2: Expired Token Simulation & 5-Minute Window Status Behavior.
     * Verify that:
     * - Past expires_at -> is_active: false, status: 'expired'
     * - Near-expiration (within 5 minutes) -> is_active: false, status: 'expired'
     * - Safe future (> 5 minutes) -> is_active: true, status: 'connected'
     * - Null expires_at -> is_active: true, status: 'connected'
     */
    public function test_adversarial_expired_token_simulation_and_thresholds(): void
    {
        $now = Carbon::now();

        $this->mockConn->tables['social_accounts'] = [
            // 1. Clearly expired 1 hour ago
            [
                'id' => (string) Str::uuid(),
                'user_id' => '00000000-0000-0000-0000-000000000001',
                'platform' => 'tiktok',
                'platform_user_id' => 'mock_tt_expired_1',
                'platform_username' => 'past_expired_tt',
                'access_token' => encrypt('tok_past'),
                'refresh_token' => null,
                'expires_at' => $now->copy()->subHour()->toIso8601String(),
                'created_at' => $now->toIso8601String(),
                'updated_at' => $now->toIso8601String(),
            ],
            // 2. Imminent expiration in 3 minutes (within 5 minute buffer)
            [
                'id' => (string) Str::uuid(),
                'user_id' => '00000000-0000-0000-0000-000000000001',
                'platform' => 'instagram',
                'platform_user_id' => 'mock_ig_imminent',
                'platform_username' => 'imminent_ig',
                'access_token' => encrypt('tok_imminent'),
                'refresh_token' => null,
                'expires_at' => $now->copy()->addMinutes(3)->toIso8601String(),
                'created_at' => $now->toIso8601String(),
                'updated_at' => $now->toIso8601String(),
            ],
            // 3. Healthy token expiring in 30 days
            [
                'id' => (string) Str::uuid(),
                'user_id' => '00000000-0000-0000-0000-000000000001',
                'platform' => 'youtube',
                'platform_user_id' => 'mock_yt_healthy',
                'platform_username' => 'healthy_yt',
                'access_token' => encrypt('tok_healthy'),
                'refresh_token' => null,
                'expires_at' => $now->copy()->addDays(30)->toIso8601String(),
                'created_at' => $now->toIso8601String(),
                'updated_at' => $now->toIso8601String(),
            ],
        ];

        $response = $this->getJson('/api/social-accounts');
        $response->assertStatus(200);

        $accounts = collect($response->json('data'));

        // Case 1: Past expired
        $tt = $accounts->firstWhere('platform', 'tiktok');
        $this->assertNotNull($tt);
        $this->assertFalse($tt['is_active'], 'Expired token must have is_active = false');
        $this->assertEquals('expired', $tt['status'], "Expired token must have status = 'expired'");

        // Case 2: Near expiration within 5 minutes buffer
        $ig = $accounts->firstWhere('platform', 'instagram');
        $this->assertNotNull($ig);
        $this->assertFalse($ig['is_active'], 'Near-expiration token (3 min left) must have is_active = false');
        $this->assertEquals('expired', $ig['status'], "Near-expiration token must have status = 'expired'");

        // Case 3: Healthy token
        $yt = $accounts->firstWhere('platform', 'youtube');
        $this->assertNotNull($yt);
        $this->assertTrue($yt['is_active'], 'Healthy token must have is_active = true');
        $this->assertEquals('connected', $yt['status'], "Healthy token must have status = 'connected'");
    }

    /**
     * CHALLENGE 2.3: Mode Transition While Preserving Identity.
     * Test switching a platform from 'mock' to 'direct' and back to 'mock':
     * - UUID remains constant throughout transitions.
     * - is_mock transitions from true -> false -> true.
     * - Credentials update accordingly.
     */
    public function test_adversarial_mode_transitions_preserve_uuid_and_toggle_mock(): void
    {
        config(['social.mock_mode' => false]);

        // Step 1: Initial mock connection
        $res1 = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'youtube',
            'mode' => 'mock',
            'username' => 'transition_step_1',
        ]);
        $res1->assertStatus(200);
        $uuid1 = $res1->json('connected.0.id');
        $this->assertTrue($res1->json('connected.0.is_mock'));

        // Step 2: Transition to direct live credentials
        $res2 = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'youtube',
            'mode' => 'direct',
            'username' => 'transition_step_2_live',
            'access_token' => 'live_direct_token_step_2',
        ]);
        $res2->assertStatus(200);
        $uuid2 = $res2->json('connected.0.id');
        $this->assertEquals($uuid1, $uuid2, 'UUID must remain unchanged when transitioning mock -> direct');
        $this->assertFalse($res2->json('connected.0.is_mock'), 'is_mock must become false in direct mode');
        $this->assertEquals('transition_step_2_live', $res2->json('connected.0.platform_username'));

        // Step 3: Transition back to mock
        $res3 = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'youtube',
            'mode' => 'mock',
            'username' => 'transition_step_3_mock',
        ]);
        $res3->assertStatus(200);
        $uuid3 = $res3->json('connected.0.id');
        $this->assertEquals($uuid1, $uuid3, 'UUID must remain unchanged when transitioning direct -> mock');
        $this->assertTrue($res3->json('connected.0.is_mock'), 'is_mock must return to true in mock mode');
        $this->assertEquals('transition_step_3_mock', $res3->json('connected.0.platform_username'));

        // Total rows must still be exactly 1
        $this->assertCount(1, $this->mockConn->tables['social_accounts']);
    }
}
