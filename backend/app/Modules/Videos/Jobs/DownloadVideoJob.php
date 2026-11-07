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

class DownloadVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public readonly string $videoId,
        public readonly array $params = []
    ) {}

    public function handle(RustProcessorClient $rustClient): void
    {
        $video = Video::find($this->videoId);
        if (! $video) {
            Log::warning("[DownloadVideoJob] Video not found: {$this->videoId}");

            return;
        }

        try {
            $video->transitionTo(Video::STATUS_DOWNLOADING);

            // Dispatch processing job with cutting configuration
            ProcessVideoJob::dispatch($this->videoId, $this->params);
        } catch (Throwable $e) {
            Log::error("[DownloadVideoJob] Error downloading video {$this->videoId}: ".$e->getMessage());
            $video->transitionTo(Video::STATUS_FAILED, $e->getMessage());
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($video = Video::find($this->videoId)) {
            $video->transitionTo(Video::STATUS_FAILED, $exception?->getMessage() ?? 'Download failed');
        }
    }
}
