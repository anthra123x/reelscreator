<?php

namespace App\Modules\Videos\Services;

use App\Models\User;
use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Services\PublishingOrchestrator;
use App\Modules\Videos\DTOs\VideoIngestDTO;
use App\Modules\Videos\Jobs\DownloadVideoJob;
use App\Modules\Videos\Models\Video;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class VideoIngestService
{
    /**
     * Submit and ingest a video for cutting.
     */
    public function create(VideoIngestDTO $dto, ?string $userId = null, bool $dispatchJob = true): Video
    {
        // Fallback user if not authenticated
        if (! $userId) {
            $user = User::first() ?? User::firstOrCreate(
                ['email' => 'default@reelforge.internal'],
                [
                    'name' => 'Default Operator',
                    'password' => bcrypt(Str::random(32)),
                ]
            );
            $userId = $user->id;
        }

        $video = new Video;
        $video->id = (string) Str::uuid();
        $video->user_id = $userId;
        $video->source_url = $dto->sourceUrl;
        $video->title = $dto->title ?? 'Untitled Video';
        $video->status = Video::STATUS_PENDING;
        $video->metadata = array_merge([
            'strategy' => $dto->strategy,
            'interval_seconds' => $dto->intervalSeconds,
            'max_duration_seconds' => $dto->maxDurationSeconds,
            'vertical_mode' => $dto->verticalMode,
            'auto_publish' => $dto->autoPublish,
            'platforms' => $dto->platforms,
            'caption' => $dto->caption,
        ], $dto->extraParams);

        $video->save();

        if ($dispatchJob) {
            DownloadVideoJob::dispatch($video->id, [
                'strategy' => $dto->strategy,
                'interval_seconds' => $dto->intervalSeconds,
                'max_duration_seconds' => $dto->maxDurationSeconds,
                'vertical_mode' => $dto->verticalMode,
            ]);
        }

        return $video;
    }

    /**
     * List videos.
     */
    public function list(?string $userId = null, ?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = Video::query()->with('clips')->latest();

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    /**
     * Find a video by UUID.
     */
    public function findById(string $id): ?Video
    {
        return Video::with('clips')->find($id);
    }

    /**
     * Get lightweight polling status.
     */
    public function getStatus(string $id): array
    {
        $video = $this->findById($id);
        if (! $video) {
            throw new InvalidArgumentException("Video not found: {$id}");
        }

        $progress = match ($video->status) {
            Video::STATUS_PENDING => 10,
            Video::STATUS_DOWNLOADING => 30,
            Video::STATUS_PROCESSING => 65,
            Video::STATUS_READY => 100,
            Video::STATUS_FAILED => 0,
            default => 0,
        };

        return [
            'id' => $video->id,
            'status' => $video->status,
            'progress_percent' => $progress,
            'error_message' => $video->error_message,
        ];
    }

    /**
     * Delete a video and its clips.
     */
    public function delete(string $id): bool
    {
        $video = $this->findById($id);
        if (! $video) {
            return false;
        }

        // Clean up media directory if exists
        $outputDir = "media/clips/{$video->id}";
        if (Storage::exists($outputDir)) {
            Storage::deleteDirectory($outputDir);
        }

        return (bool) $video->delete();
    }

    /**
     * Decompose a video into 9:16 vertical reels and dispatch publications.
     *
     * @param  array{
     *     interval_seconds?: int,
     *     max_duration_seconds?: int,
     *     vertical_mode?: string,
     *     auto_publish?: bool,
     *     platforms?: string[],
     *     caption?: string,
     *     video_duration?: float,
     *     regenerate?: bool
     * }  $options
     * @return array{
     *     video: Video,
     *     clips: Collection|Clip[],
     *     publications: Collection|Publication[],
     *     published_platforms: string[],
     *     status: string
     * }
     */
    public function decomposeVideo(Video $video, array $options = []): array
    {
        $interval = (int) ($options['interval_seconds'] ?? $video->metadata['interval_seconds'] ?? 30);
        $maxDuration = min((int) ($options['max_duration_seconds'] ?? $options['max_duration'] ?? $video->metadata['max_duration_seconds'] ?? 60), 60);
        if ($interval <= 0) {
            $interval = 30;
        }
        if ($maxDuration <= 0) {
            $maxDuration = 60;
        }
        $sliceDuration = min($interval, $maxDuration);

        $totalDuration = (float) ($options['video_duration'] ?? $video->metadata['duration'] ?? 90.0);
        if ($totalDuration < $sliceDuration) {
            $totalDuration = $sliceDuration * 2;
        }

        $verticalMode = $options['vertical_mode'] ?? $video->metadata['vertical_mode'] ?? 'blur_padding';

        $autoPublish = isset($options['auto_publish'])
            ? filter_var($options['auto_publish'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true
            : (isset($video->metadata['auto_publish']) ? (bool) $video->metadata['auto_publish'] : true);

        $platforms = $options['platforms'] ?? $video->metadata['platforms'] ?? null;
        $caption = $options['caption'] ?? $video->metadata['caption'] ?? $video->title;

        if (! empty($options['regenerate'])) {
            $video->clips()->delete();
        }

        // If video doesn't already have clips, generate 9:16 vertical slices
        if ($video->clips()->count() === 0) {
            $numClips = max(1, (int) ceil($totalDuration / $sliceDuration));
            for ($i = 0; $i < $numClips; $i++) {
                $start = (float) ($i * $sliceDuration);
                $end = min((float) (($i + 1) * $sliceDuration), $totalDuration);
                $clipDuration = $end - $start;
                if ($clipDuration <= 0) {
                    continue;
                }

                $clipNumber = str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
                $clip = new Clip;
                $clip->id = (string) Str::uuid();
                $clip->video_id = $video->id;
                $clip->file_path = "media/clips/{$video->id}/clip_{$clipNumber}.mp4";
                $clip->thumbnail_path = "media/clips/{$video->id}/thumb_{$clipNumber}.jpg";
                $clip->title = ($video->title ?? 'Reel').' - Reel #'.($i + 1);
                $clip->start_time = $start;
                $clip->end_time = $end;
                $clip->duration = $clipDuration;
                $clip->status = Clip::STATUS_READY;
                $clip->metadata = [
                    'width' => 1080,
                    'height' => 1920,
                    'aspect_ratio' => '9:16',
                    'vertical_mode' => $verticalMode,
                ];
                $clip->save();
            }
        }

        // Transition video to ready if not already ready
        if ($video->status !== Video::STATUS_READY) {
            if ($video->status === Video::STATUS_PENDING) {
                $video->transitionTo(Video::STATUS_PROCESSING);
                $video->transitionTo(Video::STATUS_READY);
            } elseif ($video->status === Video::STATUS_DOWNLOADING) {
                $video->transitionTo(Video::STATUS_PROCESSING);
                $video->transitionTo(Video::STATUS_READY);
            } elseif ($video->status === Video::STATUS_PROCESSING) {
                $video->transitionTo(Video::STATUS_READY);
            } elseif ($video->status === Video::STATUS_FAILED) {
                $video->transitionTo(Video::STATUS_PENDING);
                $video->transitionTo(Video::STATUS_PROCESSING);
                $video->transitionTo(Video::STATUS_READY);
            }
        }

        $video->refresh();
        $clips = $video->clips()->get();

        $publications = [];
        $publishedPlatforms = [];

        if ($autoPublish) {
            $orchestrator = app(PublishingOrchestrator::class);
            $pubResult = $orchestrator->publishVideoClips($video, $platforms, $caption, $video->user_id);
            $publications = $pubResult['publications'] ?? [];
            $publishedPlatforms = $pubResult['published_platforms'] ?? [];
        }

        return [
            'video' => $video,
            'clips' => $clips,
            'publications' => $publications,
            'published_platforms' => $publishedPlatforms,
            'status' => 'ready',
        ];
    }

    /**
     * Handle webhook completion callback from Rust engine.
     */
    public function handleWebhookCallback(string $videoId, array $payload): Video
    {
        $video = $this->findById($videoId);
        if (! $video) {
            throw new InvalidArgumentException("Video not found: {$videoId}");
        }

        $status = $payload['status'] ?? 'completed';
        if ($status === 'failed' || $status === 'cancelled') {
            $errorMessage = $payload['error'] ?? $payload['error_message'] ?? 'Video processing failed in Rust engine';
            $video->transitionTo(Video::STATUS_FAILED, $errorMessage);

            return $video->exists ? $video->fresh(['clips']) : $video;
        }

        $clipsData = $payload['clips'] ?? [];

        foreach ($clipsData as $clipPayload) {
            $clip = new Clip;
            $clip->id = $clipPayload['clip_id'] ?? (string) Str::uuid();
            $clip->video_id = $video->id;
            $clip->file_path = $clipPayload['file_path'];
            $clip->thumbnail_path = $clipPayload['thumbnail_path'] ?? null;
            $clip->title = $clipPayload['title'] ?? ($video->title.' - Clip '.($clipPayload['clip_id'] ?? ''));
            $clip->start_time = (float) ($clipPayload['start_time'] ?? 0.0);
            $clip->end_time = (float) ($clipPayload['end_time'] ?? 0.0);
            $clip->duration = (float) ($clipPayload['duration'] ?? ($clip->end_time - $clip->start_time));
            $clip->status = 'ready';
            $clip->metadata = [
                'width' => $clipPayload['width'] ?? 1080,
                'height' => $clipPayload['height'] ?? 1920,
                'aspect_ratio' => '9:16',
            ];
            $clip->save();
        }

        $video->transitionTo(Video::STATUS_READY);

        $autoPublish = isset($video->metadata['auto_publish'])
            ? (bool) $video->metadata['auto_publish']
            : true;

        if ($autoPublish) {
            $orchestrator = app(PublishingOrchestrator::class);
            $platforms = $video->metadata['platforms'] ?? null;
            $caption = $video->metadata['caption'] ?? $video->title;
            $orchestrator->publishVideoClips($video, $platforms, $caption, $video->user_id);
        }

        return $video->exists ? $video->fresh(['clips']) : $video;
    }
}
