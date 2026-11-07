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

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }
}
