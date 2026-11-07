<?php

namespace Tests\Unit\Services;

use App\Services\RustProcessorClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RustProcessorClientTest extends TestCase
{
    protected RustProcessorClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new RustProcessorClient('http://localhost:3000', 5);
    }

    public function test_health_check_returns_parsed_response(): void
    {
        Http::fake([
            'http://localhost:3000/health' => Http::response([
                'status' => 'healthy',
                'version' => '0.1.0',
                'concurrency' => [
                    'active_jobs' => 0,
                    'max_concurrent_jobs' => 1,
                    'available_permits' => 1,
                ],
                'uptime_seconds' => 120,
            ], 200),
        ]);

        $health = $this->client->health();

        $this->assertEquals('healthy', $health['status']);
        $this->assertEquals('0.1.0', $health['version']);
        $this->assertEquals(1, $health['concurrency']['available_permits']);
    }

    public function test_submit_job_returns_accepted_response(): void
    {
        $jobId = (string) Str::uuid();

        Http::fake([
            'http://localhost:3000/api/v1/jobs' => Http::response([
                'job_id' => $jobId,
                'status' => 'queued',
                'message' => 'Job accepted and queued for processing',
            ], 202),
        ]);

        $response = $this->client->submitJob([
            'job_id' => $jobId,
            'source_url' => 'https://example.com/video.mp4',
            'strategy' => 'fixed_interval',
            'interval_seconds' => 30,
        ]);

        $this->assertEquals($jobId, $response['job_id']);
        $this->assertEquals('queued', $response['status']);
    }

    public function test_get_job_status_returns_clips_when_completed(): void
    {
        $jobId = (string) Str::uuid();

        Http::fake([
            "http://localhost:3000/api/v1/jobs/{$jobId}" => Http::response([
                'job_id' => $jobId,
                'status' => 'completed',
                'progress_percent' => 100,
                'clips' => [
                    [
                        'clip_id' => (string) Str::uuid(),
                        'file_path' => "media/clips/{$jobId}/clip_001.mp4",
                        'thumbnail_path' => "media/thumbnails/{$jobId}/thumb_001.jpg",
                        'start_time' => 0.0,
                        'end_time' => 30.0,
                        'duration' => 30.0,
                        'width' => 1080,
                        'height' => 1920,
                    ],
                ],
                'error_message' => null,
                'execution_duration_ms' => 4500,
            ], 200),
        ]);

        $status = $this->client->getJobStatus($jobId);

        $this->assertEquals('completed', $status['status']);
        $this->assertEquals(100, $status['progress_percent']);
        $this->assertCount(1, $status['clips']);
        $this->assertEquals(1080, $status['clips'][0]['width']);
    }

    public function test_cancel_job(): void
    {
        $jobId = (string) Str::uuid();

        Http::fake([
            "http://localhost:3000/api/v1/jobs/{$jobId}/cancel" => Http::response([
                'job_id' => $jobId,
                'status' => 'cancelled',
            ], 200),
        ]);

        $res = $this->client->cancelJob($jobId);
        $this->assertEquals('cancelled', $res['status']);
    }

    public function test_direct_cut_returns_clips(): void
    {
        Http::fake([
            'http://localhost:3000/api/v1/direct-cut' => Http::response([
                'clips' => [
                    ['clip_id' => 'direct-01', 'file_path' => 'media/clips/direct.mp4'],
                ],
            ], 200),
        ]);

        $result = $this->client->directCut(['source' => 'test.mp4']);
        $this->assertCount(1, $result['clips']);
    }

    public function test_error_handling_throws_runtime_exception(): void
    {
        Http::fake([
            'http://localhost:3000/health' => Http::response([
                'error' => 'Axum server is overloaded',
            ], 503),
        ]);

        $this->expectException(RuntimeException::class);
        $this->client->health();
    }
}
