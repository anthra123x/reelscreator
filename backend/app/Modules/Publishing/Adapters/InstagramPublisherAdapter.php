<?php

namespace App\Modules\Publishing\Adapters;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\DTOs\PublishResult;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\SocialAccounts\Services\InstagramOAuthService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class InstagramPublisherAdapter extends AbstractPublisherAdapter
{
    public function __construct(
        protected InstagramOAuthService $instagramOAuth
    ) {}

    public function getPlatform(): string
    {
        return SocialAccount::PLATFORM_INSTAGRAM;
    }

    public function publish(Clip $clip, SocialAccount $account, array $options = []): PublishResult
    {
        if ($clipError = $this->validateClip($clip)) {
            return $clipError;
        }

        if (config('social.mock_mode') || $account->is_mock || empty($account->access_token)) {
            $mock = new MockSocialAdapter(SocialAccount::PLATFORM_INSTAGRAM);

            return $mock->publish($clip, $account, $options);
        }

        if (! $this->ensureValidToken($account)) {
            return PublishResult::failure('Failed to refresh expired Instagram OAuth token');
        }

        $graphBase = config('social.instagram.graph_base', 'https://graph.facebook.com/v21.0');
        $igUserId = $account->platform_user_id;
        $caption = $options['caption'] ?? $clip->title ?? 'ReelForge Short';

        // Step 1: Create media container for REELS
        $containerResponse = Http::post("{$graphBase}/{$igUserId}/media", [
            'access_token' => $account->access_token,
            'media_type' => 'REELS',
            'video_url' => $clip->file_url,
            'caption' => $caption,
            'share_to_feed' => true,
        ]);

        if ($containerResponse->failed()) {
            return PublishResult::failure(
                'Instagram Media Container Error: '.$containerResponse->body(),
                ['status' => $containerResponse->status()]
            );
        }

        $creationId = $containerResponse->json('id');
        if (! $creationId) {
            return PublishResult::failure('Missing Instagram creation_id from response');
        }

        // Step 2: Publish media container
        $publishResponse = Http::post("{$graphBase}/{$igUserId}/media_publish", [
            'access_token' => $account->access_token,
            'creation_id' => $creationId,
        ]);

        if ($publishResponse->failed()) {
            return PublishResult::failure(
                'Instagram Media Publish Error: '.$publishResponse->body(),
                ['status' => $publishResponse->status()]
            );
        }

        $postId = $publishResponse->json('id') ?? 'ig_'.Str::uuid();

        return PublishResult::success($postId, [
            'platform' => SocialAccount::PLATFORM_INSTAGRAM,
            'media_id' => $postId,
        ]);
    }

    public function refreshToken(SocialAccount $account): bool
    {
        if (config('social.mock_mode') || $account->is_mock) {
            $mock = new MockSocialAdapter(SocialAccount::PLATFORM_INSTAGRAM);

            return $mock->refreshToken($account);
        }

        try {
            $tokens = $this->instagramOAuth->refreshToken($account->access_token);
            $account->access_token = $tokens['access_token'];
            if (isset($tokens['expires_in'])) {
                $account->expires_at = Carbon::now()->addSeconds($tokens['expires_in']);
            }
            $account->save();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function validateAccount(SocialAccount $account): bool
    {
        return ! empty($account->access_token) && $account->platform === SocialAccount::PLATFORM_INSTAGRAM;
    }
}
