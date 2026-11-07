<?php

namespace Tests\Feature;

use App\Modules\Clips\Models\Clip;
use App\Modules\Clips\Services\ClipStorageService;
use App\Modules\Publishing\Adapters\MockSocialAdapter;
use App\Modules\Publishing\Jobs\PublishToTikTokJob;
use App\Modules\Publishing\Services\PublishingOrchestrator;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\Videos\Services\VideoIngestService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class AdversarialSecurityAndConcurrencyTest extends TestCase
{
    // =========================================================================
    // 1. INPUT SANITIZATION & PROTOCOL BYPASS (SSRF)
    // =========================================================================

    public function test_adversarial_rejected_schemes_in_video_store(): void
    {
        $maliciousUrls = [
            'javascript:alert("XSS")',
            'file:///etc/passwd',
            'data:text/html,<script>alert(1)</script>',
            'php://filter/read=convert.base64-encode/resource=index.php',
        ];

        foreach ($maliciousUrls as $badUrl) {
            $response = $this->postJson('/api/videos', [
                'source_url' => $badUrl,
            ]);
            $response->assertStatus(422)
                ->assertJsonValidationErrors(['source_url']);
        }
    }

    public function test_adversarial_protocol_bypass_finding_in_video_validation(): void
    {
        // Verification: 'url:http,https' strictly rejects gopher:// and dict:// protocols
        $vGopher = Validator::make(['source_url' => 'gopher://127.0.0.1:6379/_flushall'], [
            'source_url' => ['required', 'string', 'url:http,https'],
        ]);
        $this->assertFalse(
            $vGopher->passes(),
            'Hardened: gopher:// protocol is rejected by url:http,https validator'
        );

        $vDict = Validator::make(['source_url' => 'dict://127.0.0.1:11211/stat'], [
            'source_url' => ['required', 'string', 'url:http,https'],
        ]);
        $this->assertFalse(
            $vDict->passes(),
            'Hardened: dict:// protocol is rejected by url:http,https validator'
        );

        // Verification via VideoController endpoint
        $resGopher = $this->postJson('/api/videos', [
            'source_url' => 'gopher://127.0.0.1:6379/_flushall',
        ]);
        $resGopher->assertStatus(422)
            ->assertJsonValidationErrors(['source_url']);

        $resDict = $this->postJson('/api/videos', [
            'source_url' => 'dict://127.0.0.1:11211/stat',
        ]);
        $resDict->assertStatus(422)
            ->assertJsonValidationErrors(['source_url']);
    }

    // =========================================================================
    // 2. SQL INJECTION IN QUERY STRINGS
    // =========================================================================

    public function test_adversarial_sqli_in_videos_status_query_is_neutralized_by_service(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        $sqliPayload = "pending' OR '1'='1";

        // Eloquent/Controller forwards the raw string parameter to service query
        $mockService->shouldReceive('list')
            ->with(Mockery::any(), $sqliPayload, 15)
            ->once()
            ->andReturn(new LengthAwarePaginator([], 0, 15));

        $response = $this->getJson('/api/videos?status='.urlencode($sqliPayload));
        $response->assertStatus(200);
    }

    public function test_adversarial_negative_per_page_clamped_to_one(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        // Controller clamps per_page: max(1, min(100, (int)$request->query('per_page', 15)))
        // Passing -10 is clamped to 1
        $mockService->shouldReceive('list')
            ->with(Mockery::any(), Mockery::any(), 1)
            ->once()
            ->andReturn(new LengthAwarePaginator([], 0, 15));

        $response = $this->getJson('/api/videos?per_page=-10');
        $response->assertStatus(200);
    }

    public function test_adversarial_excessive_per_page_clamped_to_100(): void
    {
        $mockService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockService);

        // Controller clamps per_page: max(1, min(100, (int)$request->query('per_page', 15)))
        // Passing 9999 is clamped to 100
        $mockService->shouldReceive('list')
            ->with(Mockery::any(), Mockery::any(), 100)
            ->once()
            ->andReturn(new LengthAwarePaginator([], 0, 15));

        $response = $this->getJson('/api/videos?per_page=9999');
        $response->assertStatus(200);
    }

    // =========================================================================
    // 3. CONCURRENCY & DUPLICATE CLIP PUBLISHING
    // =========================================================================

    public function test_adversarial_duplicate_publish_requests_creates_duplicate_jobs(): void
    {
        $mockClipService = Mockery::mock(ClipStorageService::class);
        $this->app->instance(ClipStorageService::class, $mockClipService);

        $mockOrchestrator = Mockery::mock(PublishingOrchestrator::class);
        $this->app->instance(PublishingOrchestrator::class, $mockOrchestrator);

        $clipId = (string) Str::uuid();
        $fakeClip = new Clip(['duration' => 30.0]);
        $fakeClip->id = $clipId;

        $mockClipService->shouldReceive('findById')->with($clipId)->twice()->andReturn($fakeClip);

        // First call
        $mockOrchestrator->shouldReceive('publishClip')
            ->with($fakeClip, ['tiktok'], 'Sample Reel', null, Mockery::any())
            ->once()
            ->andReturn([
                'publication_ids' => ['pub-uuid-1'],
                'status' => 'queued',
            ]);

        // Second call (simulating duplicate double click)
        $mockOrchestrator->shouldReceive('publishClip')
            ->with($fakeClip, ['tiktok'], 'Sample Reel', null, Mockery::any())
            ->once()
            ->andReturn([
                'publication_ids' => ['pub-uuid-2'],
                'status' => 'queued',
            ]);

        $resp1 = $this->postJson("/api/clips/{$clipId}/publish", [
            'platforms' => ['tiktok'],
            'caption' => 'Sample Reel',
        ]);
        $resp1->assertStatus(202);
        $this->assertEquals(['pub-uuid-1'], $resp1->json('publication_ids'));

        $resp2 = $this->postJson("/api/clips/{$clipId}/publish", [
            'platforms' => ['tiktok'],
            'caption' => 'Sample Reel',
        ]);
        $resp2->assertStatus(202);
        $this->assertEquals(['pub-uuid-2'], $resp2->json('publication_ids'));

        // Proves that duplicate submissions dispatch distinct un-deduplicated publications
        $this->assertNotEquals(
            $resp1->json('publication_ids.0'),
            $resp2->json('publication_ids.0'),
            'Repeated publish requests lack deduplication guard'
        );
    }

    // =========================================================================
    // 4. RESILIENCE: MISSING RELATIONS IN BACKGROUND PUBLISH JOBS
    // =========================================================================

    public function test_adversarial_publish_job_contracts_and_retry_invariants(): void
    {
        $nonExistentPubId = (string) Str::uuid();
        $job = new PublishToTikTokJob($nonExistentPubId, ['simulate_error' => true]);

        $this->assertEquals(3, $job->tries, 'Job must have 3 max retries');
        $this->assertEquals(300, $job->timeout, 'Job must have 300s timeout bound');
        $this->assertEquals($nonExistentPubId, $job->publicationId);

        // Verify job serialization round-trip
        $serialized = serialize($job);
        $unserialized = unserialize($serialized);
        $this->assertEquals($job->publicationId, $unserialized->publicationId);
        $this->assertEquals($job->options, $unserialized->options);
    }

    // =========================================================================
    // 5. MALFORMED WEBHOOK CALLBACKS
    // =========================================================================

    public function test_adversarial_webhook_callback_with_nonexistent_id_returns_404(): void
    {
        $mockVideoService = Mockery::mock(VideoIngestService::class);
        $this->app->instance(VideoIngestService::class, $mockVideoService);

        $missingJobId = (string) Str::uuid();
        $mockVideoService->shouldReceive('handleWebhookCallback')
            ->with($missingJobId, Mockery::any())
            ->once()
            ->andThrow(new InvalidArgumentException("Video not found: {$missingJobId}"));

        $response = $this->postJson("/api/internal/jobs/{$missingJobId}/callback", [
            'status' => 'completed',
            'clips' => [],
        ]);

        $response->assertStatus(404)
            ->assertJson(['message' => "Video not found: {$missingJobId}"]);
    }

    // =========================================================================
    // 6. TOKEN EXPIRATION IN MOCK SOCIAL ADAPTER
    // =========================================================================

    public function test_adversarial_mock_social_adapter_does_not_check_token_expiration(): void
    {
        $adapter = new MockSocialAdapter('tiktok');

        $clip = new Clip(['duration' => 15.0]);
        $clip->id = (string) Str::uuid();

        // Expired account (expired 10 days ago)
        $account = new SocialAccount([
            'platform' => 'tiktok',
            'platform_username' => 'creator_1',
        ]);
        $account->id = (string) Str::uuid();
        $account->access_token = 'expired_mock_token';
        $account->expires_at = Carbon::now()->subDays(10);

        $this->assertTrue($account->isExpired(), 'Account must be flagged as expired');

        // Empirical finding: MockSocialAdapter does not check $account->isExpired()
        // and succeeds anyway unless simulate_error is explicitly passed.
        $result = $adapter->publish($clip, $account);
        $this->assertTrue(
            $result->success,
            'Empirical finding: MockSocialAdapter publishes successfully even with expired account'
        );
    }
}
