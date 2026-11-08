<?php

namespace App\Modules\SocialAccounts\Services;

use App\Models\User;
use App\Modules\SocialAccounts\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocialAccountService
{
    /**
     * List social accounts for user.
     */
    public function listAccounts(?string $userId = null): Collection
    {
        $query = SocialAccount::query();

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->latest()->get();
    }

    /**
     * Find social account by UUID.
     */
    public function findById(string $id): ?SocialAccount
    {
        return SocialAccount::find($id);
    }

    /**
     * Provision a synthetic mock account for testing without credentials.
     */
    public function createMockAccount(string $platform, string $username, ?string $userId = null): SocialAccount
    {
        $userId = $this->resolveUserId($userId);
        $mockId = "mock_{$platform}_".Str::random(12);

        $account = new SocialAccount;
        $account->id = (string) Str::uuid();
        $account->user_id = $userId;
        $account->platform = $platform;
        $account->platform_user_id = $mockId;
        $account->platform_username = $username;
        $account->access_token = 'mock_access_token_'.Str::random(32);
        $account->refresh_token = 'mock_refresh_token_'.Str::random(32);
        $account->expires_at = Carbon::now()->addDays(30);
        $account->save();

        return $account;
    }

    /**
     * Connect social account(s) in an agile and idempotent manner.
     *
     * @param  string  $platform  'tiktok'|'instagram'|'youtube'|'all'
     * @param  string  $mode  'auto'|'env'|'mock'|'direct'
     * @param  array<string, mixed>  $options
     * @return Collection<int, SocialAccount>
     */
    public function simpleConnect(
        string $platform,
        string $mode = 'auto',
        array $options = [],
        ?string $userId = null
    ): Collection {
        $userId = $this->resolveUserId($userId);
        $mode = in_array($mode, ['auto', 'env', 'mock', 'direct'], true) ? $mode : 'auto';

        $targetPlatforms = ($platform === 'all')
            ? SocialAccount::SUPPORTED_PLATFORMS
            : [$platform];

        return DB::transaction(function () use ($targetPlatforms, $mode, $options, $userId) {
            $connectedAccounts = new Collection;

            foreach ($targetPlatforms as $plat) {
                $existing = SocialAccount::where('user_id', $userId)
                    ->where('platform', $plat)
                    ->first();

                $effectiveMode = $mode;
                if ($mode === 'auto') {
                    $hasEnvToken = ! empty(config("social.{$plat}.access_token"));
                    $mockModeDisabled = ! config('social.mock_mode', false);
                    if ($hasEnvToken && $mockModeDisabled) {
                        $effectiveMode = 'env';
                    } else {
                        $effectiveMode = 'mock';
                    }
                }

                if ($effectiveMode === 'env') {
                    $envToken = config("social.{$plat}.access_token");
                    if (! empty($envToken)) {
                        $accessToken = $envToken;
                        $refreshToken = config("social.{$plat}.refresh_token");
                        $rawUser = $options['username'] ?? config("social.{$plat}.username") ?? "{$plat}_creator";
                        $username = ltrim(trim($rawUser), '@');
                        $platformUserId = config("social.{$plat}.account_id")
                            ?? ($existing && ! str_starts_with($existing->platform_user_id, 'mock_')
                                ? $existing->platform_user_id
                                : "env_{$plat}_".substr(md5($accessToken), 0, 10));
                        $expiresAt = Carbon::now()->addDays(90);
                    } else {
                        $effectiveMode = 'mock';
                    }
                }

                if ($effectiveMode === 'direct') {
                    $accessToken = $options['access_token'] ?? ('token_'.Str::random(32));
                    $refreshToken = $options['refresh_token'] ?? null;
                    $rawUser = $options['username'] ?? config("social.{$plat}.username", "{$plat}_creator");
                    $username = ltrim(trim($rawUser), '@');
                    $platformUserId = $options['platform_user_id']
                        ?? ($existing && ! str_starts_with($existing->platform_user_id, 'mock_')
                            ? $existing->platform_user_id
                            : "act_{$plat}_".Str::random(10));
                    $expiresAt = Carbon::now()->addDays(90);
                }

                if ($effectiveMode === 'mock') {
                    $accessToken = 'mock_access_token_'.Str::random(32);
                    $refreshToken = 'mock_refresh_token_'.Str::random(32);
                    $username = $options['username'] ?? config("social.{$plat}.username", "mock_{$plat}_creator");
                    $platformUserId = ($existing && str_starts_with($existing->platform_user_id, 'mock_'))
                        ? $existing->platform_user_id
                        : "mock_{$plat}_".Str::random(12);
                    $expiresAt = Carbon::now()->addDays(30);
                }

                $account = SocialAccount::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'platform' => $plat,
                    ],
                    [
                        'platform_user_id' => $platformUserId,
                        'platform_username' => $username,
                        'access_token' => $accessToken,
                        'refresh_token' => $refreshToken,
                        'expires_at' => $expiresAt,
                    ]
                );

                $connectedAccounts->push($account);
            }

            return $connectedAccounts;
        });
    }

    /**
     * Save or update OAuth credentials.
     */
    public function saveOAuthAccount(string $platform, array $tokenData, ?string $userId = null): SocialAccount
    {
        $userId = $this->resolveUserId($userId);
        $platformUserId = $tokenData['open_id'] ?? $tokenData['user_id'] ?? Str::uuid()->toString();

        return SocialAccount::updateOrCreate(
            [
                'user_id' => $userId,
                'platform' => $platform,
                'platform_user_id' => $platformUserId,
            ],
            [
                'platform_username' => $tokenData['username'] ?? "{$platform}_user",
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'expires_at' => isset($tokenData['expires_in']) ? Carbon::now()->addSeconds($tokenData['expires_in']) : null,
            ]
        );
    }

    /**
     * Safely resolve or create default user to preserve referential integrity.
     */
    private function resolveUserId(?string $userId = null): string
    {
        if ($userId) {
            return $userId;
        }

        $user = User::first() ?? User::firstOrCreate(
            ['email' => 'default@reelforge.internal'],
            [
                'name' => 'Default Operator',
                'password' => bcrypt(Str::random(32)),
            ]
        );

        return $user->id;
    }

    /**
     * Get active connected platforms for user, defaulting to supported platforms.
     *
     * @return string[]
     */
    public function getActivePlatforms(?string $userId = null): array
    {
        $userId = $this->resolveUserId($userId);
        $connected = SocialAccount::where('user_id', $userId)
            ->pluck('platform')
            ->unique()
            ->values()
            ->all();

        return ! empty($connected) ? $connected : SocialAccount::SUPPORTED_PLATFORMS;
    }

    /**
     * Disconnect social account.
     */
    public function deleteAccount(string $id): bool
    {
        $account = $this->findById($id);
        if (! $account) {
            return false;
        }

        return (bool) $account->delete();
    }
}
