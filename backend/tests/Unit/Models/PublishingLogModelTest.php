<?php

namespace Tests\Unit\Models;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublishingLog;
use App\Modules\SocialAccounts\Models\SocialAccount;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use stdClass;
use Tests\TestCase;

class PublishingLogModelTest extends TestCase
{
    public function test_publishing_log_instantiation_and_mass_assignment(): void
    {
        $pubId = (string) Str::uuid();
        $clipId = (string) Str::uuid();
        $accId = (string) Str::uuid();

        $log = new PublishingLog([
            'id' => 'malicious-uuid-override',
            'publication_id' => $pubId,
            'clip_id' => $clipId,
            'social_account_id' => $accId,
            'platform' => 'tiktok',
            'event' => PublishingLog::EVENT_UPLOADED,
            'status' => PublishingLog::STATUS_SUCCESS,
            'payload' => ['result' => 'ok'],
            'error_message' => null,
            'is_admin' => true,
        ]);

        $this->assertEquals('publishing_logs', $log->getTable());
        $this->assertEquals('string', $log->getKeyType());
        $this->assertFalse($log->getIncrementing());

        // Fillable mass-assignment assertions
        $this->assertEquals($pubId, $log->publication_id);
        $this->assertEquals($clipId, $log->clip_id);
        $this->assertEquals($accId, $log->social_account_id);
        $this->assertEquals('tiktok', $log->platform);
        $this->assertEquals(PublishingLog::EVENT_UPLOADED, $log->event);
        $this->assertEquals(PublishingLog::STATUS_SUCCESS, $log->status);
        $this->assertEquals(['result' => 'ok'], $log->payload);
        $this->assertNull($log->error_message);

        // Guard against unfillable mass assignment
        $this->assertNull($log->id);
        $this->assertArrayNotHasKey('is_admin', $log->getAttributes());
    }

    public function test_publishing_log_constants(): void
    {
        $this->assertSame('success', PublishingLog::STATUS_SUCCESS);
        $this->assertSame('failed', PublishingLog::STATUS_FAILED);
        $this->assertSame('info', PublishingLog::STATUS_INFO);
        $this->assertSame('retry', PublishingLog::STATUS_RETRY);

        $this->assertSame('dispatch', PublishingLog::EVENT_DISPATCH);
        $this->assertSame('upload_started', PublishingLog::EVENT_UPLOAD_STARTED);
        $this->assertSame('uploaded', PublishingLog::EVENT_UPLOADED);
        $this->assertSame('published', PublishingLog::EVENT_PUBLISHED);
        $this->assertSame('failed', PublishingLog::EVENT_FAILED);
        $this->assertSame('retry', PublishingLog::EVENT_RETRY);
    }

    public function test_publishing_log_jsonb_nested_and_unicode_payload_casting(): void
    {
        $complexPayload = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'japanese' => '日本語テスト',
                        'emojis' => '🚀🔥🎬✨🤖💯',
                        'accents' => 'áéíóúñÑüÜçÇ',
                        'rtl' => 'مرحبا بالعالم / שלום עולם',
                        'escapes' => "Line1\nLine2\tTabbed \"Quotes\" and \\Backslashes\\",
                        'injection' => '<script>alert("xss")</script> \' OR 1=1 --',
                        'items' => [1, 'two', 3.14, false, null, ['nested' => true]],
                    ],
                ],
            ],
        ];

        $log = new PublishingLog(['payload' => $complexPayload]);

        $this->assertIsArray($log->payload);
        $this->assertSame('日本語テスト', $log->payload['level1']['level2']['level3']['japanese']);
        $this->assertSame('🚀🔥🎬✨🤖💯', $log->payload['level1']['level2']['level3']['emojis']);
        $this->assertSame('áéíóúñÑüÜçÇ', $log->payload['level1']['level2']['level3']['accents']);
        $this->assertSame('مرحبا بالعالم / שלום עולם', $log->payload['level1']['level2']['level3']['rtl']);
        $this->assertTrue($log->payload['level1']['level2']['level3']['items'][5]['nested']);

        // Test serialization to array and JSON
        $arrayForm = $log->toArray();
        $this->assertIsArray($arrayForm['payload']);
        $this->assertSame('🚀🔥🎬✨🤖💯', $arrayForm['payload']['level1']['level2']['level3']['emojis']);

        $jsonForm = $log->toJson();
        $this->assertIsString($jsonForm);
        $decoded = json_decode($jsonForm, true);
        $this->assertSame('日本語テスト', $decoded['payload']['level1']['level2']['level3']['japanese']);
    }

    public function test_publishing_log_jsonb_numeric_null_and_boundary_types(): void
    {
        $payload = [
            'int_zero' => 0,
            'int_negative' => -999999,
            'int_max' => PHP_INT_MAX,
            'float_pi' => 3.141592653589793,
            'float_tiny' => 1e-12,
            'bool_true' => true,
            'bool_false' => false,
            'null_val' => null,
            'empty_array' => [],
            'empty_obj' => new stdClass,
        ];

        $log = new PublishingLog(['payload' => $payload]);

        $this->assertSame(0, $log->payload['int_zero']);
        $this->assertSame(-999999, $log->payload['int_negative']);
        $this->assertSame(PHP_INT_MAX, $log->payload['int_max']);
        $this->assertSame(3.141592653589793, $log->payload['float_pi']);
        $this->assertTrue($log->payload['bool_true']);
        $this->assertFalse($log->payload['bool_false']);
        $this->assertNull($log->payload['null_val']);
        $this->assertSame([], $log->payload['empty_array']);
    }

    public function test_publishing_log_jsonb_raw_attribute_hydration_and_mutation(): void
    {
        // Hydration from raw JSON string (as returned by DB driver)
        $rawJson = json_encode([
            'http_status' => 200,
            'platform_response' => ['id' => '12345678', 'state' => 'PROCESSING'],
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
        ]);

        $log = new PublishingLog;
        $log->setRawAttributes([
            'id' => (string) Str::uuid(),
            'payload' => $rawJson,
        ]);

        $this->assertIsArray($log->payload);
        $this->assertSame(200, $log->payload['http_status']);
        $this->assertSame('12345678', $log->payload['platform_response']['id']);

        // Mutation test
        $currentPayload = $log->payload;
        $currentPayload['platform_response']['state'] = 'PUBLISHED';
        $currentPayload['published_at'] = '2026-11-07T17:00:00Z';
        $log->payload = $currentPayload;

        $this->assertSame('PUBLISHED', $log->payload['platform_response']['state']);
        $this->assertSame('2026-11-07T17:00:00Z', $log->payload['published_at']);

        // Null and empty raw attributes
        $logNull = new PublishingLog;
        $logNull->setRawAttributes(['payload' => null]);
        $this->assertNull($logNull->payload);

        $logEmpty = new PublishingLog;
        $logEmpty->setRawAttributes(['payload' => '{}']);
        $this->assertIsArray($logEmpty->payload);
        $this->assertEmpty($logEmpty->payload);
    }

    public function test_publishing_log_relationships_definition_and_keys(): void
    {
        $pubId = (string) Str::uuid();
        $clipId = (string) Str::uuid();
        $accId = (string) Str::uuid();

        $log = new PublishingLog([
            'publication_id' => $pubId,
            'clip_id' => $clipId,
            'social_account_id' => $accId,
        ]);

        // 1. Publication relationship
        $pubRel = $log->publication();
        $this->assertInstanceOf(BelongsTo::class, $pubRel);
        $this->assertInstanceOf(Publication::class, $pubRel->getRelated());
        $this->assertSame('publication_id', $pubRel->getForeignKeyName());
        $this->assertSame('id', $pubRel->getOwnerKeyName());
        $this->assertStringContainsString('where "publications"."id" = ?', $pubRel->toSql());

        // 2. Clip relationship
        $clipRel = $log->clip();
        $this->assertInstanceOf(BelongsTo::class, $clipRel);
        $this->assertInstanceOf(Clip::class, $clipRel->getRelated());
        $this->assertSame('clip_id', $clipRel->getForeignKeyName());
        $this->assertSame('id', $clipRel->getOwnerKeyName());
        $this->assertStringContainsString('where "clips"."id" = ?', $clipRel->toSql());

        // 3. SocialAccount relationship
        $accRel = $log->socialAccount();
        $this->assertInstanceOf(BelongsTo::class, $accRel);
        $this->assertInstanceOf(SocialAccount::class, $accRel->getRelated());
        $this->assertSame('social_account_id', $accRel->getForeignKeyName());
        $this->assertSame('id', $accRel->getOwnerKeyName());
        $this->assertStringContainsString('where "social_accounts"."id" = ?', $accRel->toSql());

        // 4. Test when foreign keys are null
        $emptyLog = new PublishingLog;
        $this->assertStringContainsString('where "publications"."id" is null', $emptyLog->publication()->toSql());
        $this->assertStringContainsString('where "clips"."id" is null', $emptyLog->clip()->toSql());
        $this->assertStringContainsString('where "social_accounts"."id" is null', $emptyLog->socialAccount()->toSql());
    }

    public function test_publishing_logs_ddl_cascade_on_delete_rules(): void
    {
        $pdoMock = new class extends PDO
        {
            public function __construct() {}
        };
        $conn = new PostgresConnection($pdoMock, 'reelforge', '', ['server_version' => '16.0']);
        $conn->useDefaultSchemaGrammar();
        Schema::swap($conn->getSchemaBuilder());

        $migration = require database_path('migrations/2026_03_01_000005_create_publishing_logs_table.php');
        $queries = $conn->pretend(fn () => $migration->up());

        $sqlStatements = array_map(fn ($q) => $q['query'], $queries);
        $joinedSql = implode("\n", $sqlStatements);

        // Verify CREATE TABLE
        $this->assertStringContainsString('create table "publishing_logs"', $joinedSql);
        $this->assertStringContainsString('"id" uuid not null', $joinedSql);
        $this->assertStringContainsString('"publication_id" uuid not null', $joinedSql);
        $this->assertStringContainsString('"clip_id" uuid not null', $joinedSql);
        $this->assertStringContainsString('"social_account_id" uuid not null', $joinedSql);
        $this->assertStringContainsString('"payload" jsonb not null default \'{}\'', $joinedSql);

        // Verify Cascade on Delete on all 3 foreign keys
        $this->assertStringContainsString(
            'alter table "publishing_logs" add constraint "publishing_logs_publication_id_foreign" foreign key ("publication_id") references "publications" ("id") on delete cascade',
            $joinedSql
        );
        $this->assertStringContainsString(
            'alter table "publishing_logs" add constraint "publishing_logs_clip_id_foreign" foreign key ("clip_id") references "clips" ("id") on delete cascade',
            $joinedSql
        );
        $this->assertStringContainsString(
            'alter table "publishing_logs" add constraint "publishing_logs_social_account_id_foreign" foreign key ("social_account_id") references "social_accounts" ("id") on delete cascade',
            $joinedSql
        );

        // Verify explicit indexing on foreign keys for high-performance cascade deletion
        $this->assertStringContainsString('create index "idx_publishing_logs_pub" on "publishing_logs" ("publication_id")', $joinedSql);
        $this->assertStringContainsString('create index "idx_publishing_logs_clip" on "publishing_logs" ("clip_id")', $joinedSql);
        $this->assertStringContainsString('create index "idx_publishing_logs_acc" on "publishing_logs" ("social_account_id")', $joinedSql);
        $this->assertStringContainsString('create index "idx_publishing_logs_platform_event" on "publishing_logs" ("platform", "event")', $joinedSql);
        $this->assertStringContainsString('create index "idx_publishing_logs_status_created" on "publishing_logs" ("status", "created_at")', $joinedSql);

        // Verify down migration
        $downQueries = $conn->pretend(fn () => $migration->down());
        $downSql = implode("\n", array_map(fn ($q) => $q['query'], $downQueries));
        $this->assertStringContainsString('drop table if exists "publishing_logs"', $downSql);
    }
}
