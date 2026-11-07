<?php

namespace App\Modules\Videos\Models;

use App\Models\User;
use App\Modules\Clips\Models\Clip;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class Video extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DOWNLOADING = 'downloading';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DOWNLOADING,
        self::STATUS_PROCESSING,
        self::STATUS_READY,
        self::STATUS_FAILED,
    ];

    /**
     * Permitted state transitions.
     */
    protected const TRANSITION_MAP = [
        self::STATUS_PENDING => [self::STATUS_DOWNLOADING, self::STATUS_PROCESSING, self::STATUS_FAILED],
        self::STATUS_DOWNLOADING => [self::STATUS_PROCESSING, self::STATUS_FAILED],
        self::STATUS_PROCESSING => [self::STATUS_READY, self::STATUS_FAILED],
        self::STATUS_READY => [],
        self::STATUS_FAILED => [self::STATUS_PENDING], // Allow retry
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'source_url',
        'title',
        'status',
        'file_path',
        'duration',
        'metadata',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'duration' => 'float',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Validate if state transition is allowed.
     */
    public function canTransitionTo(string $targetStatus): bool
    {
        if (! in_array($targetStatus, self::ALLOWED_STATUSES, true)) {
            return false;
        }

        $current = $this->status ?? self::STATUS_PENDING;
        if ($current === $targetStatus) {
            return true;
        }

        $allowed = self::TRANSITION_MAP[$current] ?? [];

        return in_array($targetStatus, $allowed, true);
    }

    /**
     * Transition video status with state machine guard.
     */
    public function transitionTo(string $targetStatus, ?string $errorMessage = null): self
    {
        if (! $this->canTransitionTo($targetStatus)) {
            throw new InvalidArgumentException(
                "Invalid status transition from '{$this->status}' to '{$targetStatus}' for Video [{$this->id}]"
            );
        }

        $this->status = $targetStatus;
        if ($errorMessage !== null) {
            $this->error_message = $errorMessage;
        }

        if ($this->exists) {
            $this->save();
        }

        return $this;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clips(): HasMany
    {
        return $this->hasMany(Clip::class);
    }
}
