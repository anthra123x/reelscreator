<?php

namespace App\Modules\SocialAccounts\Services;

use App\Models\User;
use App\Modules\SocialAccounts\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
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
