<?php

namespace Tests\Unit\Database;

use App\Models\User;
use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Services\PublishingOrchestrator;
use App\Modules\SocialAccounts\Services\SocialAccountService;
use App\Modules\Videos\DTOs\VideoIngestDTO;
use App\Modules\Videos\Services\VideoIngestService;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;

class PostgresCompilationAndFkFallbackAdversarialTest extends TestCase
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Helper to create a PostgresConnection with PostgresGrammar for dry-run compilation.
     */
    private function createPostgresTestConnection(): PostgresConnection
    {
        $pdoMock = new class extends PDO
        {
            public function __construct() {}
        };

        $conn = new PostgresConnection($pdoMock, 'reelforge', '', ['server_version' => '16.0']);
        $conn->useDefaultSchemaGrammar();
        $conn->useDefaultPostProcessor();

        return $conn;
    }

    /**
     * Test 1: Verify all 8 migrations compile cleanly under PostgresGrammar for up() and down().
     */
    public function test_all_8_migrations_compile_cleanly_under_postgres_grammar_up_and_down(): void
    {
        $conn = $this->createPostgresTestConnection();
        Schema::swap($conn->getSchemaBuilder());

        $files = glob(database_path('migrations/*.php'));
        sort($files);

        $this->assertCount(8, $files, 'Expected exactly 8 migration files');

        $compiledMigrations = [];

        foreach ($files as $file) {
            $baseName = basename($file);
            $migration = require $file;

            $upQueries = $conn->pretend(fn () => $migration->up());
            $downQueries = $conn->pretend(fn () => $migration->down());

            $this->assertNotEmpty($upQueries, "Migration {$baseName} up() produced no SQL queries");
            $this->assertNotEmpty($downQueries, "Migration {$baseName} down() produced no SQL queries");

            $compiledMigrations[$baseName] = [
                'up' => array_map(fn ($q) => $q['query'], $upQueries),
                'down' => array_map(fn ($q) => $q['query'], $downQueries),
            ];
        }

        // Verify key PostgreSQL type assertions
        $upSqlLists = array_values(array_map(fn ($m) => $m['up'], $compiledMigrations));
        $allUpSql = implode("\n", array_merge(...$upSqlLists));

        // 1. Native UUID primary keys
        $this->assertStringContainsString('"id" uuid not null', $allUpSql);

        // 2. JSONB metadata
        $this->assertStringContainsString('"metadata" jsonb not null default \'{}\'', $allUpSql);
        $this->assertStringContainsString('"payload" jsonb not null default \'{}\'', $allUpSql);

        // 3. Double precision for duration and timestamps
        $this->assertStringContainsString('"duration" double precision', $allUpSql);

        // 4. Timezone-aware timestamps for scheduling and token expiration
        $this->assertStringContainsString('timestamp(0) with time zone', $allUpSql);

        // 5. Cascade delete constraints
        $this->assertStringContainsString('on delete cascade', $allUpSql);

        // 6. Rollback statements
        $downSqlLists = array_values(array_map(fn ($m) => $m['down'], $compiledMigrations));
        $allDownSql = implode("\n", array_merge(...$downSqlLists));
        $this->assertStringContainsString('drop table if exists "publishing_logs"', $allDownSql);
        $this->assertStringContainsString('drop table if exists "publications"', $allDownSql);
        $this->assertStringContainsString('drop table if exists "social_accounts"', $allDownSql);
        $this->assertStringContainsString('drop table if exists "clips"', $allDownSql);
        $this->assertStringContainsString('drop table if exists "videos"', $allDownSql);
        $this->assertStringContainsString('drop table if exists "users"', $allDownSql);
    }

    /**
     * Test 2: Adversarially audit schema for orphaned foreign keys and unindexed constraints.
     */
    public function test_referential_integrity_and_indexing_completeness(): void
    {
        $conn = $this->createPostgresTestConnection();
        Schema::swap($conn->getSchemaBuilder());

        $files = glob(database_path('migrations/*.php'));
        sort($files);

        $schemaTables = [];
        $foreignKeys = [];
        $indexes = [];

        foreach ($files as $file) {
            $migration = require $file;
            $queries = $conn->pretend(fn () => $migration->up());

            foreach ($queries as $q) {
                $sql = $q['query'];

                if (preg_match('/create table "([^"]+)" \((.+)\)/i', $sql, $m)) {
                    $tableName = $m[1];
                    $schemaTables[$tableName] = [];
                    $cols = preg_split('/,(?![^(]*\))/', $m[2]);
                    foreach ($cols as $col) {
                        if (preg_match('/"([^"]+)"\s+([a-z0-9_ ]+)/i', trim($col), $cm)) {
                            $schemaTables[$tableName][$cm[1]] = trim($cm[2]);
                        }
                    }
                }

                if (preg_match('/alter table "([^"]+)" add constraint "([^"]+)" foreign key \("([^"]+)"\) references "([^"]+)" \("([^"]+)"\)( on delete ([a-z]+))?/i', $sql, $m)) {
                    $foreignKeys[] = [
                        'table' => $m[1],
                        'constraint' => $m[2],
                        'column' => $m[3],
                        'foreign_table' => $m[4],
                        'foreign_column' => $m[5],
                        'on_delete' => $m[7] ?? 'no_action',
                    ];
                }

                if (preg_match('/create (unique )?index "([^"]+)" on "([^"]+)" \(([^)]+)\)/i', $sql, $m)) {
                    $indexes[$m[3]][] = [
                        'name' => $m[2],
                        'columns' => array_map(fn ($c) => trim(str_replace('"', '', $c)), explode(',', $m[4])),
                    ];
                }

                if (preg_match('/alter table "([^"]+)" add constraint "([^"]+)" unique \(([^)]+)\)/i', $sql, $m)) {
                    $indexes[$m[1]][] = [
                        'name' => $m[2],
                        'columns' => array_map(fn ($c) => trim(str_replace('"', '', $c)), explode(',', $m[3])),
                    ];
                }
            }
        }

        $this->assertCount(8, $foreignKeys, 'Expected exactly 8 foreign keys across domain models');

        foreach ($foreignKeys as $fk) {
            $table = $fk['table'];
            $col = $fk['column'];
            $foreignTable = $fk['foreign_table'];
            $foreignCol = $fk['foreign_column'];
            $constraintName = $fk['constraint'];

            // 1. Target table must exist
            $this->assertArrayHasKey($foreignTable, $schemaTables, "Foreign table [{$foreignTable}] does not exist for FK [{$constraintName}]");

            // 2. Target column must exist
            $this->assertArrayHasKey($foreignCol, $schemaTables[$foreignTable], "Foreign column [{$foreignTable}.{$foreignCol}] does not exist for FK [{$constraintName}]");

            // 3. Data type match (both must be uuid)
            $localType = strtok($schemaTables[$table][$col] ?? '', ' ');
            $foreignType = strtok($schemaTables[$foreignTable][$foreignCol] ?? '', ' ');
            $this->assertSame('uuid', $localType, "Local FK column [{$table}.{$col}] must be uuid");
            $this->assertSame('uuid', $foreignType, "Referenced column [{$foreignTable}.{$foreignCol}] must be uuid");

            // 4. Constraint must cascade on delete
            $this->assertSame('cascade', $fk['on_delete'], "FK [{$constraintName}] must have cascadeOnDelete");

            // 5. Foreign key column must be indexed (as primary index column) to prevent PostgreSQL table scans
            $tableIndexes = $indexes[$table] ?? [];
            $hasLeadingIndex = false;
            foreach ($tableIndexes as $idx) {
                if ($idx['columns'][0] === $col) {
                    $hasLeadingIndex = true;
                    break;
                }
            }
            $this->assertTrue($hasLeadingIndex, "FK column [{$table}.{$col}] is UNINDEXED in table [{$table}]");
        }
    }

    /**
     * Helper to configure an in-memory mock connection for service testing.
     */
    private function setupMockDatabaseConnection(array $initialTables = []): object
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
                                    if (! in_array($b, array_values($row), true)) {
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
                if (preg_match('/insert into\s+"?([a-z_]+)"?\s*\(([^)]+)\)\s*values\s*\(([^)]+)\)/i', $query, $m)) {
                    $table = $m[1];
                    $cols = array_map(fn ($c) => trim(str_replace('"', '', $c)), explode(',', $m[2]));
                    $row = [];
                    foreach ($cols as $idx => $col) {
                        $row[$col] = $bindings[$idx] ?? null;
                    }
                    $this->tables[$table][] = $row;
                }

                return true;
            }

            public function update($query, $bindings = []): int
            {
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
     * Test 3: VideoIngestService triggers User::firstOrCreate and returns valid UUID when no user exists in DB.
     */
    public function test_video_ingest_service_triggers_user_first_or_create_when_db_empty(): void
    {
        Queue::fake();
        $conn = $this->setupMockDatabaseConnection(['users' => []]);

        $service = $this->app->make(VideoIngestService::class);
        $dto = new VideoIngestDTO(
            sourceUrl: 'https://example.com/adversarial_ingest.mp4',
            title: 'Adversarial Verification Video',
            strategy: 'fixed_interval',
            intervalSeconds: 15,
            verticalMode: 'blur_padding'
        );

        $video = $service->create($dto, null);

        // Assert user was persisted in DB
        $this->assertNotEmpty($conn->tables['users'], 'User must be created in DB via firstOrCreate');
        $createdUser = $conn->tables['users'][0];
        $this->assertSame('default@reelforge.internal', $createdUser['email']);
        $this->assertSame('Default Operator', $createdUser['name']);

        // Assert valid UUID
        $this->assertMatchesRegularExpression(self::UUID_REGEX, $createdUser['id']);
        $this->assertSame($createdUser['id'], $video->user_id, 'Video must reference created user UUID');
        $this->assertMatchesRegularExpression(self::UUID_REGEX, $video->id);
    }

    /**
     * Test 4: SocialAccountService triggers User::firstOrCreate and returns valid UUID when no user exists in DB.
     */
    public function test_social_account_service_triggers_user_first_or_create_when_db_empty(): void
    {
        $conn = $this->setupMockDatabaseConnection(['users' => []]);

        $service = $this->app->make(SocialAccountService::class);

        // 4a. createMockAccount
        $account = $service->createMockAccount('tiktok', 'adversarial_creator', null);

        $this->assertNotEmpty($conn->tables['users'], 'User must be created in DB via firstOrCreate');
        $createdUser = $conn->tables['users'][0];
        $this->assertSame('default@reelforge.internal', $createdUser['email']);
        $this->assertMatchesRegularExpression(self::UUID_REGEX, $createdUser['id']);
        $this->assertSame($createdUser['id'], $account->user_id);
        $this->assertMatchesRegularExpression(self::UUID_REGEX, $account->id);

        // 4b. saveOAuthAccount with fallback
        $conn->tables['users'] = [];
        $oauthAccount = $service->saveOAuthAccount('youtube', [
            'user_id' => 'yt_channel_999',
            'username' => 'yt_creator',
            'access_token' => 'mock_token',
        ], null);

        $this->assertNotEmpty($conn->tables['users']);
        $this->assertMatchesRegularExpression(self::UUID_REGEX, $oauthAccount->user_id);
    }

    /**
     * Test 5: PublishingOrchestrator triggers User::firstOrCreate when clip has no video user.
     */
    public function test_publishing_orchestrator_triggers_user_first_or_create_when_db_empty(): void
    {
        Queue::fake();
        $conn = $this->setupMockDatabaseConnection(['users' => []]);

        $orchestrator = $this->app->make(PublishingOrchestrator::class);

        $clip = new Clip;
        $clip->id = (string) Str::uuid();
        $clip->video_id = (string) Str::uuid();
        $clip->file_path = 'clips/adversarial.mp4';
        $clip->duration = 20.0;
        $clip->status = Clip::STATUS_READY;

        $result = $orchestrator->publishClip($clip, ['tiktok', 'instagram'], 'Adversarial Post', null, null);

        $this->assertNotEmpty($conn->tables['users'], 'User must be created in DB via firstOrCreate');
        $createdUser = $conn->tables['users'][0];
        $this->assertMatchesRegularExpression(self::UUID_REGEX, $createdUser['id']);

        $this->assertArrayHasKey('publication_ids', $result);
        $this->assertCount(2, $result['publication_ids']);
        foreach ($result['publication_ids'] as $pubId) {
            $this->assertMatchesRegularExpression(self::UUID_REGEX, $pubId);
        }
    }

    /**
     * Test 6: Services reuse existing user without creating redundant users.
     */
    public function test_services_reuse_existing_user_when_present(): void
    {
        Queue::fake();
        $existingUuid = '12345678-1234-4234-8234-123456789abc';
        $conn = $this->setupMockDatabaseConnection([
            'users' => [
                [
                    'id' => $existingUuid,
                    'name' => 'Existing Operator',
                    'email' => 'operator@reelforge.internal',
                    'password' => 'secret',
                ],
            ],
        ]);

        $videoService = $this->app->make(VideoIngestService::class);
        $dto = new VideoIngestDTO(sourceUrl: 'https://example.com/v.mp4', title: 'Existing User Test');
        $video = $videoService->create($dto, null);

        $this->assertSame($existingUuid, $video->user_id, 'Must reuse existing user ID');
        $this->assertCount(1, $conn->tables['users'], 'No redundant user should be created');

        $socialService = $this->app->make(SocialAccountService::class);
        $account = $socialService->createMockAccount('instagram', 'existing_acc', null);
        $this->assertSame($existingUuid, $account->user_id, 'Must reuse existing user ID');
        $this->assertCount(1, $conn->tables['users'], 'No redundant user should be created');
    }

    /**
     * Test 7: Explicit user ID is strictly preserved without DB fallback.
     */
    public function test_explicit_user_id_is_strictly_preserved(): void
    {
        Queue::fake();
        $customUuid = '87654321-4321-4321-8321-cba987654321';
        $conn = $this->setupMockDatabaseConnection(['users' => []]);

        $videoService = $this->app->make(VideoIngestService::class);
        $dto = new VideoIngestDTO(sourceUrl: 'https://example.com/custom.mp4', title: 'Custom User Test');
        $video = $videoService->create($dto, $customUuid);

        $this->assertSame($customUuid, $video->user_id, 'Explicit user ID must be preserved');
        $this->assertEmpty($conn->tables['users'], 'No user should be created when explicit user ID is given');
    }
}
