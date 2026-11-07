<?php

namespace App\Modules\Publishing\Adapters;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Contracts\SocialPublisherAdapterInterface;
use App\Modules\Publishing\DTOs\PublishResult;
use App\Modules\SocialAccounts\Models\SocialAccount;

abstract class AbstractPublisherAdapter implements SocialPublisherAdapterInterface
{
    /**
     * Get platform identifier: 'tiktok', 'instagram', 'youtube', 'mock'.
     */
    abstract public function getPlatform(): string;

    /**
     * Execute publication of a clip to the target social account.
     */
    abstract public function publish(Clip $clip, SocialAccount $account, array $options = []): PublishResult;

    /**
     * Refresh OAuth access token if nearing expiration.
     */
    abstract public function refreshToken(SocialAccount $account): bool;

    /**
     * Verify if account credentials remain valid.
     */
    abstract public function validateAccount(SocialAccount $account): bool;

    /**
     * Validate common clip constraints.
     */
    protected function validateClip(Clip $clip): ?PublishResult
    {
        if ($clip->duration <= 0) {
            return PublishResult::failure("Invalid clip duration: {$clip->duration}s");
        }

        return null;
    }

    /**
     * Ensure OAuth token is valid, attempting refresh if expired.
     */
    protected function ensureValidToken(SocialAccount $account): bool
    {
        if ($account->isExpired()) {
            return $this->refreshToken($account);
        }

        return true;
    }
}
