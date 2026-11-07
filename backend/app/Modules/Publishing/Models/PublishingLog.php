<?php

namespace App\Modules\Publishing\Models;

use App\Modules\Clips\Models\Clip;
use App\Modules\SocialAccounts\Models\SocialAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishingLog extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_INFO = 'info';

    public const STATUS_RETRY = 'retry';

    public const EVENT_DISPATCH = 'dispatch';

    public const EVENT_DISPATCHED = 'dispatched';

    public const EVENT_UPLOAD_STARTED = 'upload_started';

    public const EVENT_CHUNK_UPLOADED = 'chunk_uploaded';

    public const EVENT_UPLOADED = 'uploaded';

    public const EVENT_PUBLISHED = 'published';

    public const EVENT_FAILED = 'failed';

    public const EVENT_RETRY = 'retry';

    public const EVENT_RETRY_ATTEMPTED = 'retry_attempted';

    protected $table = 'publishing_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'publication_id',
        'clip_id',
        'social_account_id',
        'platform',
        'event',
        'status',
        'payload',
        'error_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function clip(): BelongsTo
    {
        return $this->belongsTo(Clip::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
