<?php

namespace App\Modules\Publishing\Jobs;

use App\Modules\Publishing\Adapters\TikTokPublisherAdapter;
use App\Modules\Publishing\Jobs\Concerns\HandlesPublishingJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PublishToTikTokJob implements ShouldQueue
{
    use Dispatchable, HandlesPublishingJob, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $publicationId,
        public readonly array $options = []
    ) {}

    public function handle(TikTokPublisherAdapter $adapter): void
    {
        $this->executePublication($adapter);
    }

    public function failed(?Throwable $exception): void
    {
        $this->handleFailed($exception);
    }
}
