<?php

namespace App\Modules\Publishing\Contracts;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\DTOs\PublishResult;
use App\Modules\SocialAccounts\Models\SocialAccount;

interface SocialPublisherAdapterInterface
{
    /**
     * Get platform identifier: 'tiktok', 'instagram', 'youtube', 'mock'.
     */
    public function getPlatform(): string;

    /**
     * Execute publication of a clip to the target social account.
     */
    public function publish(Clip $clip, SocialAccount $account, array $options = []): PublishResult;

    /**
     * Refresh OAuth access token if nearing expiration.
     */
    public function refreshToken(SocialAccount $account): bool;

    /**
     * Verify if account credentials remain valid.
     */
    public function validateAccount(SocialAccount $account): bool;
}
