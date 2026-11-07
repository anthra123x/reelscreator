<?php

namespace App\Modules\Clips\Services;

use App\Modules\Clips\DTOs\UpdateClipDTO;
use App\Modules\Clips\Models\Clip;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ClipStorageService
{
    /**
     * List all clips belonging to a video.
     */
    public function listByVideo(string $videoId): Collection
    {
        return Clip::where('video_id', $videoId)->orderBy('start_time')->get();
    }

    /**
     * List all clips across all videos.
     */
    public function listAll(int $perPage = 50)
    {
        return Clip::with('video')->latest()->paginate($perPage);
    }

    /**
     * Retrieve clip by UUID.
     */
    public function findById(string $id): ?Clip
    {
        return Clip::with('video')->find($id);
    }

    /**
     * Update clip metadata, title or trimming bounds.
     */
    public function update(string $id, UpdateClipDTO $dto): Clip
    {
        $clip = $this->findById($id);
        if (! $clip) {
            throw new InvalidArgumentException("Clip not found: {$id}");
        }

        if ($dto->title !== null) {
            $clip->title = $dto->title;
        }

        if ($dto->startTime !== null) {
            $clip->start_time = $dto->startTime;
        }

        if ($dto->endTime !== null) {
            $clip->end_time = $dto->endTime;
        }

        if ($dto->startTime !== null || $dto->endTime !== null) {
            $clip->duration = max(0.0, $clip->end_time - $clip->start_time);
        }

        if ($dto->metadata !== null) {
            $clip->metadata = array_merge($clip->metadata ?? [], $dto->metadata);
        }

        $clip->save();

        return $clip;
    }

    /**
     * Delete clip from storage and database.
     */
    public function delete(string $id): bool
    {
        $clip = $this->findById($id);
        if (! $clip) {
            return false;
        }

        if (Storage::exists($clip->file_path)) {
            Storage::delete($clip->file_path);
        }

        if ($clip->thumbnail_path && Storage::exists($clip->thumbnail_path)) {
            Storage::delete($clip->thumbnail_path);
        }

        return (bool) $clip->delete();
    }
}
