<?php

namespace Tests\Feature;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Adapters\TikTokPublisherAdapter;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\SocialAccounts\Services\TikTokOAuthService;
use Carbon\Carbon;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class AgileSocialAccountsConnectionTest extends TestCase
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
                    'name' => 'Default Operator',
                    'email' => 'default@reelforge.internal',
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
                        // Locate target row by ID if present
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
     * Test 1: Connect single platform in mock mode returns HTTP 200/201 and valid structure.
     */
    public function test_connect_single_platform_in_mock_mode(): void
    {
        $response = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'tiktok',
            'mode' => 'mock',
            'username' => 'tiktok_star_tester',
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
                'account' => [
                    'id',
                    'platform',
                    'platform_username',
                    'is_mock',
                    'status',
                    'is_active',
                ],
            ]);

        $this->assertEquals('tiktok', $response->json('account.platform'));
        $this->assertEquals('tiktok_star_tester', $response->json('account.platform_username'));
        $this->assertTrue($response->json('account.is_mock'));
        $this->assertEquals('connected', $response->json('account.status'));
        $this->assertTrue($response->json('account.is_active'));

        $this->assertNotEmpty($this->mockConn->tables['social_accounts']);
        $row = $this->mockConn->tables['social_accounts'][0];
        $this->assertEquals('tiktok', $row['platform']);
        $this->assertEquals('tiktok_star_tester', $row['platform_username']);
    }

    /**
     * Test 2: Connect all 3 platforms via platform: 'all' returns 3 connected accounts.
     */
    public function test_connect_all_three_platforms_simultaneously(): void
    {
        $response = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'all',
            'mode' => 'mock',
            'username' => 'omni_creator',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Social accounts connected successfully',
            ]);

        $connected = $response->json('connected');
        $this->assertIsArray($connected);
        $this->assertCount(3, $connected);

        $platforms = array_column($connected, 'platform');
        sort($platforms);
        $this->assertEquals(['instagram', 'tiktok', 'youtube'], $platforms);

        foreach ($connected as $item) {
            $this->assertTrue($item['is_mock']);
            $this->assertEquals('connected', $item['status']);
            $this->assertTrue($item['is_active']);
            $this->assertEquals('omni_creator', $item['platform_username']);
        }

        $this->assertCount(3, $this->mockConn->tables['social_accounts']);
    }

    /**
     * Test 3: Idempotency — connecting twice updates existing row, preserving UUID.
     */
    public function test_idempotent_connection_preserves_uuid_and_updates_row(): void
    {
        // First connection
        $res1 = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'instagram',
            'mode' => 'mock',
            'username' => 'initial_handle',
        ]);
        $res1->assertStatus(200);
        $accountId1 = $res1->json('connected.0.id');
        $this->assertNotEmpty($accountId1);

        // Second connection with updated username
        $res2 = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'instagram',
            'mode' => 'mock',
            'username' => 'updated_handle',
        ]);
        $res2->assertStatus(200);
        $accountId2 = $res2->json('connected.0.id');

        // UUID must be strictly preserved
        $this->assertEquals($accountId1, $accountId2);

        // In-memory table row count must be exactly 1 for Instagram
        $igRows = array_filter($this->mockConn->tables['social_accounts'], fn ($r) => $r['platform'] === 'instagram');
        $this->assertCount(1, $igRows);

        // Username must be updated
        $firstRow = reset($igRows);
        $this->assertEquals('updated_handle', $firstRow['platform_username']);
    }

    /**
     * Test 4: Mode 'env' with configured tokens creates live account.
     */
    public function test_mode_env_with_configured_tokens_creates_live_account(): void
    {
        config([
            'social.mock_mode' => false,
            'social.tiktok.access_token' => 'live_env_tok_abc_123',
            'social.tiktok.refresh_token' => 'live_env_refresh_xyz_789',
            'social.tiktok.username' => 'env_configured_tiktok',
        ]);

        $response = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'tiktok',
            'mode' => 'env',
        ]);

        $response->assertStatus(200);
        $accountData = $response->json('connected.0');

        $this->assertEquals('tiktok', $accountData['platform']);
        $this->assertEquals('env_configured_tiktok', $accountData['platform_username']);
        $this->assertFalse($accountData['is_mock']);
        $this->assertEquals('connected', $accountData['status']);

        // Check decrypted token in DB
        $dbAccount = SocialAccount::find($accountData['id']);
        $this->assertEquals('live_env_tok_abc_123', $dbAccount->access_token);
        $this->assertEquals('live_env_refresh_xyz_789', $dbAccount->refresh_token);
    }

    /**
     * Test 5: Mode 'env' without tokens falls back to mock account.
     */
    public function test_mode_env_without_tokens_falls_back_to_mock(): void
    {
        config([
            'social.mock_mode' => false,
            'social.youtube.access_token' => null,
        ]);

        $response = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'youtube',
            'mode' => 'env',
            'username' => 'fallback_shorts',
        ]);

        $response->assertStatus(200);
        $accountData = $response->json('connected.0');

        $this->assertEquals('youtube', $accountData['platform']);
        $this->assertTrue($accountData['is_mock']);
        $this->assertEquals('connected', $accountData['status']);
    }

    /**
     * Test 6: Mode 'direct' persists provided credentials.
     */
    public function test_mode_direct_persists_provided_credentials(): void
    {
        config(['social.mock_mode' => false]);

        $response = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'instagram',
            'mode' => 'direct',
            'username' => 'direct_ig_creator',
            'access_token' => 'EAAB_direct_instagram_live_token',
        ]);

        $response->assertStatus(200);
        $accountData = $response->json('connected.0');

        $this->assertEquals('instagram', $accountData['platform']);
        $this->assertEquals('direct_ig_creator', $accountData['platform_username']);
        $this->assertFalse($accountData['is_mock']);

        $dbAccount = SocialAccount::find($accountData['id']);
        $this->assertEquals('EAAB_direct_instagram_live_token', $dbAccount->access_token);
    }

    /**
     * Test 7: Validation rejects invalid platform name with HTTP 422.
     */
    public function test_validation_rejects_invalid_platform_with_422(): void
    {
        $response = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'myspace',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['platform']);
    }

    /**
     * Test 8: Validation rejects invalid mode with HTTP 422.
     */
    public function test_validation_rejects_invalid_mode_with_422(): void
    {
        $response = $this->postJson('/api/social-accounts/connect', [
            'platform' => 'tiktok',
            'mode' => 'unsupported_mode',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['mode']);
    }

    /**
     * Test 9: GET /api/social-accounts returns items including is_active and status.
     */
    public function test_get_social_accounts_includes_is_active_and_status(): void
    {
        $this->mockConn->tables['social_accounts'] = [
            [
                'id' => (string) Str::uuid(),
                'user_id' => '00000000-0000-0000-0000-000000000001',
                'platform' => 'tiktok',
                'platform_user_id' => 'mock_tt_active',
                'platform_username' => 'active_tt',
                'access_token' => encrypt('tok1'),
                'refresh_token' => null,
                'expires_at' => Carbon::now()->addDays(10)->toIso8601String(),
                'created_at' => Carbon::now()->toIso8601String(),
                'updated_at' => Carbon::now()->toIso8601String(),
            ],
            [
                'id' => (string) Str::uuid(),
                'user_id' => '00000000-0000-0000-0000-000000000001',
                'platform' => 'youtube',
                'platform_user_id' => 'mock_yt_expired',
                'platform_username' => 'expired_yt',
                'access_token' => encrypt('tok2'),
                'refresh_token' => null,
                'expires_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
                'created_at' => Carbon::now()->toIso8601String(),
                'updated_at' => Carbon::now()->toIso8601String(),
            ],
        ];

        $response = $this->getJson('/api/social-accounts');
        $response->assertStatus(200);

        $data = collect($response->json('data'));

        $ttItem = $data->firstWhere('platform', 'tiktok');
        $this->assertNotNull($ttItem);
        $this->assertTrue($ttItem['is_active']);
        $this->assertEquals('connected', $ttItem['status']);

        $ytItem = $data->firstWhere('platform', 'youtube');
        $this->assertNotNull($ytItem);
        $this->assertFalse($ytItem['is_active']);
        $this->assertEquals('expired', $ytItem['status']);
    }

    /**
     * Test 10: Publisher adapter delegates to live API when mock_mode=false and token present.
     */
    public function test_publisher_adapter_uses_live_flow_with_direct_tokens(): void
    {
        config(['social.mock_mode' => false]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/video/init/*' => Http::response([
                'data' => [
                    'publish_id' => 'v_pub_live_tiktok_99999',
                ],
            ], 200),
        ]);

        $account = new SocialAccount([
            'id' => (string) Str::uuid(),
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'platform' => 'tiktok',
            'platform_user_id' => 'live_tiktok_user_888',
            'platform_username' => 'live_tiktok_handle',
            'access_token' => 'act.live_tiktok_token_xyz',
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        $clip = new Clip([
            'duration' => 15.0,
            'title' => 'Direct Live Reel',
        ]);
        $clip->file_url = 'https://storage.reelforge.internal/clips/test.mp4';

        $adapter = new TikTokPublisherAdapter(new TikTokOAuthService);
        $result = $adapter->publish($clip, $account);

        $this->assertTrue($result->success);
        $this->assertEquals('v_pub_live_tiktok_99999', $result->platformPostId);
    }
}
