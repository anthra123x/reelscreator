<?php

namespace App\Modules\Videos\Jobs;

use App\Modules\Videos\Models\Video;
use App\Services\RustProcessorClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public readonly string $videoId,
        public readonly array $params = []
    ) {}

    public function handle(RustProcessorClient $rustClient): void
    {
        $video = Video::find($this->videoId);
        if (! $video) {
            Log::warning("[ProcessVideoJob] Video not found: {$this->videoId}");

            return;
        }

        try {
            $video->transitionTo(Video::STATUS_PROCESSING);

            $rustUrl = config('services.rust_engine.url', 'http://localhost:3000');
            $canAttemptRust = ! empty($rustUrl) && (! app()->isProduction() || ! str_contains($rustUrl, 'localhost'));
            $isRustEngineAvailable = false;

            if ($canAttemptRust) {
                $isRustEngineAvailable = $rustClient->isAvailable(1.5);
            }

            if ($isRustEngineAvailable) {
                $payload = [
                    'job_id' => $video->id,
                    'source_url' => $video->source_url,
                    'strategy' => $this->params['strategy'] ?? 'fixed_interval',
                    'interval_seconds' => (int) ($this->params['interval_seconds'] ?? 30),
                    'max_duration_seconds' => (int) ($this->params['max_duration_seconds'] ?? 60),
                    'aspect_ratio' => '9:16',
                    'vertical_mode' => $this->params['vertical_mode'] ?? 'blur_padding',
                    'output_dir' => "media/clips/{$video->id}",
                    'webhook_url' => url("/api/internal/jobs/{$video->id}/callback"),
                ];

                $response = $rustClient->submitJob($payload);
                Log::info("[ProcessVideoJob] Submitted job {$video->id} to Rust engine: ".json_encode($response));

                return;
            }

            // Standalone / Cloud autonomous clipping execution
            Log::info("[ProcessVideoJob] Rust Axum engine offline or in serverless environment; executing direct decomposition for {$this->videoId}");
            $ingestService = app(\App\Modules\Videos\Services\VideoIngestService::class);
            $ingestService->decomposeVideo($video, $this->params);
        } catch (Throwable $e) {
            Log::error("[ProcessVideoJob] Processing failed for video {$this->videoId}: ".$e->getMessage());
            $video->transitionTo(Video::STATUS_FAILED, $e->getMessage());
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($video = Video::find($this->videoId)) {
            $video->transitionTo(Video::STATUS_FAILED, $exception?->getMessage() ?? 'Processing failed');
        }
    }
}
