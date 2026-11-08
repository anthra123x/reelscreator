<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RustProcessorClient
{
    protected string $baseUrl;

    protected int $timeout;

    public function __construct(?string $baseUrl = null, ?int $timeout = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('services.rust_engine.url', 'http://localhost:3000'), '/');
        $this->timeout = $timeout ?? (int) config('services.rust_engine.timeout', 30);
    }

    /**
     * Check health of the Rust engine.
     *
     * @return array{status: string, version: string, concurrency: array, uptime_seconds: int}
     */
    public function health(): array
    {
        $response = $this->client()->get("{$this->baseUrl}/health");
        $this->assertSuccessful($response, 'Health check failed');

        return $response->json();
    }

    /**
     * Rapid check to determine if the Rust Axum engine is online and responding.
     */
    public function isAvailable(float $timeoutSeconds = 1.5): bool
    {
        try {
            $response = Http::timeout($timeoutSeconds)->get("{$this->baseUrl}/health");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Submit video processing job asynchronously to Axum engine.
     *
     * @param array{
     *     job_id: string,
     *     source_url?: ?string,
     *     local_video_path?: ?string,
     *     strategy?: string,
     *     interval_seconds?: int,
     *     max_duration_seconds?: int,
     *     aspect_ratio?: string,
     *     vertical_mode?: string,
     *     output_dir?: string,
     *     webhook_url?: ?string
     * } $payload
     * @return array{job_id: string, status: string, message: string}
     */
    public function submitJob(array $payload): array
    {
        $defaults = [
            'strategy' => 'fixed_interval',
            'interval_seconds' => 30,
            'max_duration_seconds' => 60,
            'aspect_ratio' => '9:16',
            'vertical_mode' => 'blur_padding',
            'local_video_path' => null,
            'webhook_url' => null,
        ];

        $body = array_merge($defaults, $payload);

        $response = $this->client()->post("{$this->baseUrl}/api/v1/jobs", $body);
        $this->assertAcceptedOrOk($response, 'Job submission failed');

        return $response->json();
    }

    /**
     * Query processing status and fetch results for a job.
     *
     * @return array{job_id: string, status: string, progress_percent: int, clips: array, error_message: ?string, execution_duration_ms: ?int}
     */
    public function getJobStatus(string $jobId): array
    {
        $response = $this->client()->get("{$this->baseUrl}/api/v1/jobs/{$jobId}");
        $this->assertSuccessful($response, "Failed to retrieve job status for {$jobId}");

        return $response->json();
    }

    /**
     * Cancel an ongoing processing job.
     *
     * @return array{job_id: string, status: string}
     */
    public function cancelJob(string $jobId): array
    {
        $response = $this->client()->post("{$this->baseUrl}/api/v1/jobs/{$jobId}/cancel");
        $this->assertSuccessful($response, "Failed to cancel job {$jobId}");

        return $response->json();
    }

    /**
     * Execute immediate synchronous direct cutting (for testing or short clips).
     *
     * @return array{clips: array}
     */
    public function directCut(array $payload): array
    {
        $response = $this->client()->post("{$this->baseUrl}/api/v1/direct-cut", $payload);
        $this->assertSuccessful($response, 'Direct cut execution failed');

        return $response->json();
    }

    /**
     * Build HTTP client instance.
     */
    protected function client()
    {
        return Http::timeout($this->timeout)->acceptJson();
    }

    /**
     * Assert response is 200 OK.
     */
    protected function assertSuccessful(Response $response, string $context): void
    {
        if ($response->failed()) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? $response->body();
            Log::error("[RustProcessorClient] {$context}: {$response->status()} - {$errorMessage}");
            throw new RuntimeException("{$context}: HTTP {$response->status()} - {$errorMessage}");
        }
    }

    /**
     * Assert response is 202 Accepted or 200 OK.
     */
    protected function assertAcceptedOrOk(Response $response, string $context): void
    {
        $status = $response->status();
        if ($status !== 200 && $status !== 202) {
            $errorMessage = $response->json('message') ?? $response->json('error') ?? $response->body();
            Log::error("[RustProcessorClient] {$context}: {$status} - {$errorMessage}");
            throw new RuntimeException("{$context}: HTTP {$status} - {$errorMessage}");
        }
    }
}
