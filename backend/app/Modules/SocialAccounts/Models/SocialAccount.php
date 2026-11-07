<?php

namespace App\Modules\SocialAccounts\Models;

use App\Models\User;
use App\Modules\Publishing\Models\Publication;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    use HasFactory, HasUuids;

    public const PLATFORM_TIKTOK = 'tiktok';

    public const PLATFORM_INSTAGRAM = 'instagram';

    public const PLATFORM_YOUTUBE = 'youtube';

    public const SUPPORTED_PLATFORMS = [
        self::PLATFORM_TIKTOK,
        self::PLATFORM_INSTAGRAM,
        self::PLATFORM_YOUTUBE,
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'platform',
        'platform_user_id',
        'platform_username',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $appends = [
        'is_mock',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Check if token is nearing expiration (within 5 minutes).
     */
    public function isExpired(): bool
    {
        if (! $this->expires_at) {
            return false;
        }

        return Carbon::now()->addMinutes(5)->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * Determine if this is a synthetic mock account.
     */
    public function getIsMockAttribute(): bool
    {
        return str_starts_with((string) $this->platform_user_id, 'mock_') ||
            (bool) config('social.mock_mode', false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }
}
