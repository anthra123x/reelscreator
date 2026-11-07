<?php

namespace App\Modules\Publishing\Jobs\Concerns;

use App\Modules\Publishing\Contracts\SocialPublisherAdapterInterface;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublishingLog;
use Carbon\Carbon;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

trait HandlesPublishingJob
{
    public int $tries = 3;

    public int $timeout = 300;

    /**
     * Exponential backoff delays for worker-level retries.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [20, 40, 80];
    }

    /**
     * Rate limiting middleware configured via social-publishing rate limiter.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new RateLimited('social-publishing'))->dontRelease(),
        ];
    }

    /**
     * Common publication execution logic with structured logging, chunk simulation,
     * exponential release, and failure handling.
     */
    protected function executePublication(SocialPublisherAdapterInterface $adapter): void
    {
        $publication = Publication::with(['clip', 'socialAccount'])->find($this->publicationId);
        if (! $publication || ! $publication->clip || ! $publication->socialAccount) {
            Log::error("[{$this->getJobName()}] Publication or relations not found: {$this->publicationId}");

            return;
        }

        $platform = $publication->socialAccount->platform;

        try {
            $publication->status = Publication::STATUS_PUBLISHING;
            $publication->save();

            // 1. Log event: upload_started
            PublishingLog::create([
                'publication_id' => $publication->id,
                'clip_id' => $publication->clip_id,
                'social_account_id' => $publication->social_account_id,
                'platform' => $platform,
                'event' => PublishingLog::EVENT_UPLOAD_STARTED,
                'status' => Publication::STATUS_PUBLISHING,
                'payload' => [
                    'attempt' => $this->attempts(),
                    'max_tries' => $this->tries,
                    'options' => $this->options,
                ],
                'error_message' => null,
            ]);

            // 2. Log event: chunk_uploaded (if simulate_chunking is requested)
            if (! empty($this->options['simulate_chunking'])) {
                $totalChunks = (int) ($this->options['total_chunks'] ?? 3);
                $chunkSize = (int) ($this->options['chunk_size'] ?? 1048576);

                for ($i = 1; $i <= $totalChunks; $i++) {
                    PublishingLog::create([
                        'publication_id' => $publication->id,
                        'clip_id' => $publication->clip_id,
                        'social_account_id' => $publication->social_account_id,
                        'platform' => $platform,
                        'event' => PublishingLog::EVENT_CHUNK_UPLOADED,
                        'status' => PublishingLog::STATUS_INFO,
                        'payload' => [
                            'chunk_index' => $i,
                            'total_chunks' => $totalChunks,
                            'bytes_uploaded' => $i * $chunkSize,
                        ],
                        'error_message' => null,
                    ]);
                }
            }

            // 3. Adapter publish invocation
            $result = $adapter->publish($publication->clip, $publication->socialAccount, $this->options);

            // 4. Handle result
            if ($result->success) {
                $publication->status = Publication::STATUS_PUBLISHED;
                $publication->platform_post_id = $result->platformPostId;
                $publication->published_at = Carbon::now();
                $publication->error_message = null;
                $publication->save();

                // Log event: published
                PublishingLog::create([
                    'publication_id' => $publication->id,
                    'clip_id' => $publication->clip_id,
                    'social_account_id' => $publication->social_account_id,
                    'platform' => $platform,
                    'event' => PublishingLog::EVENT_PUBLISHED,
                    'status' => Publication::STATUS_PUBLISHED,
                    'payload' => [
                        'platform_post_id' => $result->platformPostId,
                        'metadata' => $result->metadata,
                        'attempt' => $this->attempts(),
                    ],
                    'error_message' => null,
                ]);

                Log::info("[{$this->getJobName()}] Published to {$platform}: {$result->platformPostId}");
            } else {
                // If retries remain
                if ($this->attempts() < $this->tries) {
                    $delay = (int) (pow(2, $this->attempts()) * 10);

                    // Log event: retry_attempted
                    PublishingLog::create([
                        'publication_id' => $publication->id,
                        'clip_id' => $publication->clip_id,
                        'social_account_id' => $publication->social_account_id,
                        'platform' => $platform,
                        'event' => PublishingLog::EVENT_RETRY_ATTEMPTED,
                        'status' => PublishingLog::STATUS_RETRY,
                        'payload' => [
                            'attempt' => $this->attempts(),
                            'max_tries' => $this->tries,
                            'next_retry_delay_seconds' => $delay,
                            'metadata' => $result->metadata,
                        ],
                        'error_message' => $result->errorMessage,
                    ]);

                    Log::warning("[{$this->getJobName()}] Publish attempt {$this->attempts()} failed: {$result->errorMessage}. Retrying in {$delay}s");

                    $this->release($delay);

                    return;
                }

                // Retries exhausted
                $publication->status = Publication::STATUS_FAILED;
                $publication->error_message = $result->errorMessage;
                $publication->save();

                // Log event: failed
                PublishingLog::create([
                    'publication_id' => $publication->id,
                    'clip_id' => $publication->clip_id,
                    'social_account_id' => $publication->social_account_id,
                    'platform' => $platform,
                    'event' => PublishingLog::EVENT_FAILED,
                    'status' => Publication::STATUS_FAILED,
                    'payload' => [
                        'attempt' => $this->attempts(),
                        'max_tries' => $this->tries,
                        'options' => $this->options,
                    ],
                    'error_message' => $result->errorMessage,
                ]);

                Log::warning("[{$this->getJobName()}] Publish permanently failed after {$this->attempts()} attempts: {$result->errorMessage}");
            }
        } catch (Throwable $e) {
            if ($this->attempts() < $this->tries) {
                $delay = (int) (pow(2, $this->attempts()) * 10);

                PublishingLog::create([
                    'publication_id' => $publication->id,
                    'clip_id' => $publication->clip_id,
                    'social_account_id' => $publication->social_account_id,
                    'platform' => $platform,
                    'event' => PublishingLog::EVENT_RETRY_ATTEMPTED,
                    'status' => PublishingLog::STATUS_RETRY,
                    'payload' => [
                        'attempt' => $this->attempts(),
                        'max_tries' => $this->tries,
                        'exception' => get_class($e),
                        'next_retry_delay_seconds' => $delay,
                    ],
                    'error_message' => $e->getMessage(),
                ]);

                Log::error("[{$this->getJobName()}] Exception during publish attempt {$this->attempts()}: {$e->getMessage()}. Releasing in {$delay}s");

                $this->release($delay);

                return;
            }

            $publication->status = Publication::STATUS_FAILED;
            $publication->error_message = $e->getMessage();
            $publication->save();

            PublishingLog::create([
                'publication_id' => $publication->id,
                'clip_id' => $publication->clip_id,
                'social_account_id' => $publication->social_account_id,
                'platform' => $platform,
                'event' => PublishingLog::EVENT_FAILED,
                'status' => Publication::STATUS_FAILED,
                'payload' => [
                    'attempt' => $this->attempts(),
                    'max_tries' => $this->tries,
                    'exception' => get_class($e),
                ],
                'error_message' => $e->getMessage(),
            ]);

            Log::error("[{$this->getJobName()}] Exception during publish permanently failed: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Fallback when job fails completely or times out in queue worker.
     */
    public function handleFailed(?Throwable $exception): void
    {
        if ($publication = Publication::with('socialAccount')->find($this->publicationId)) {
            $publication->status = Publication::STATUS_FAILED;
            $publication->error_message = $exception?->getMessage() ?? 'Publishing failed';
            $publication->save();

            $existing = PublishingLog::where('publication_id', $publication->id)
                ->where('event', PublishingLog::EVENT_FAILED)
                ->where('error_message', $publication->error_message)
                ->first();

            if (! $existing) {
                PublishingLog::create([
                    'publication_id' => $publication->id,
                    'clip_id' => $publication->clip_id,
                    'social_account_id' => $publication->social_account_id,
                    'platform' => $publication->socialAccount?->platform ?? 'unknown',
                    'event' => PublishingLog::EVENT_FAILED,
                    'status' => Publication::STATUS_FAILED,
                    'payload' => [
                        'attempt' => $this->attempts(),
                        'max_tries' => $this->tries,
                        'options' => $this->options,
                        'exception' => $exception ? get_class($exception) : null,
                    ],
                    'error_message' => $publication->error_message,
                ]);
            }
        }
    }

    protected function getJobName(): string
    {
        return class_basename($this);
    }
}
