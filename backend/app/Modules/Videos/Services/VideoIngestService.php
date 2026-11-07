<?php

namespace App\Modules\Videos\Services;

use App\Models\User;
use App\Modules\Clips\Models\Clip;
use App\Modules\Videos\DTOs\VideoIngestDTO;
use App\Modules\Videos\Jobs\DownloadVideoJob;
use App\Modules\Videos\Models\Video;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class VideoIngestService
{
    /**
     * Submit and ingest a video for cutting.
     */
    public function create(VideoIngestDTO $dto, ?string $userId = null): Video
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
        ], $dto->extraParams);

        $video->save();

        DownloadVideoJob::dispatch($video->id, [
            'strategy' => $dto->strategy,
            'interval_seconds' => $dto->intervalSeconds,
            'max_duration_seconds' => $dto->maxDurationSeconds,
            'vertical_mode' => $dto->verticalMode,
        ]);

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
            ];
            $clip->save();
        }

        $video->transitionTo(Video::STATUS_READY);

        return $video->exists ? $video->fresh(['clips']) : $video;
    }
}
