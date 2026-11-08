<?php

namespace App\Modules\Clips\Models;

use App\Modules\Publishing\Models\Publication;
use App\Modules\Videos\Models\Video;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Clip extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_READY = 'ready';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'video_id',
        'file_path',
        'thumbnail_path',
        'title',
        'start_time',
        'end_time',
        'duration',
        'status',
        'metadata',
    ];

    protected $appends = [
        'file_url',
        'thumbnail_url',
        'embed_url',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'start_time' => 'float',
            'end_time' => 'float',
            'duration' => 'float',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Compute publicly accessible URL for playback.
     */
    public function getFileUrlAttribute(): string
    {
        if (str_starts_with($this->file_path, 'http://') || str_starts_with($this->file_path, 'https://')) {
            return $this->file_path;
        }

        // If parent video source_url is a direct video media stream, append media fragments
        $sourceUrl = $this->relationLoaded('video') ? $this->video?->source_url : null;
        if ($sourceUrl && preg_match('/\.(mp4|webm|ogg|mov)(\?.*)?$/i', $sourceUrl)) {
            $start = $this->start_time ?? 0;
            $end = $this->end_time ?? ($start + 30);

            return "{$sourceUrl}#t={$start},{$end}";
        }

        return Storage::url($this->file_path);
    }

    /**
     * Compute publicly accessible URL for preview thumbnail.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        if (str_starts_with($this->thumbnail_path, 'http://') || str_starts_with($this->thumbnail_path, 'https://')) {
            return $this->thumbnail_path;
        }

        return Storage::url($this->thumbnail_path);
    }

    /**
     * Compute safe embed URL for external video providers (YouTube, Vimeo) with start and end times.
     */
    public function getEmbedUrlAttribute(): ?string
    {
        $sourceUrl = $this->relationLoaded('video') ? $this->video?->source_url : null;
        if (! $sourceUrl) {
            return null;
        }

        // YouTube
        if (preg_match('/(?:youtube\.com\/(?:watch\?.*v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i', $sourceUrl, $matches)) {
            $videoId = $matches[1];
            $start = (int) floor($this->start_time ?? 0);
            $end = (int) ceil($this->end_time ?? ($start + 30));

            return "https://www.youtube-nocookie.com/embed/{$videoId}?start={$start}&end={$end}&autoplay=1&playsinline=1&controls=0&rel=0&modestbranding=1&iv_load_policy=3&showinfo=0&disablekb=1&fs=0&loop=1&playlist={$videoId}";
        }

        // Vimeo
        if (preg_match('/vimeo\.com\/(?:video\/)?([0-9]+)/i', $sourceUrl, $matches)) {
            $vimeoId = $matches[1];
            $start = (int) floor($this->start_time ?? 0);

            return "https://player.vimeo.com/video/{$vimeoId}?autoplay=1#t={$start}s";
        }

        return null;
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }
}
