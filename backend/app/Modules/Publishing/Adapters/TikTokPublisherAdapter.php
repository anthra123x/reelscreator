<?php

namespace App\Modules\Publishing\Adapters;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\DTOs\PublishResult;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\SocialAccounts\Services\TikTokOAuthService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TikTokPublisherAdapter extends AbstractPublisherAdapter
{
    public function __construct(
        protected TikTokOAuthService $tikTokOAuth
    ) {}

    public function getPlatform(): string
    {
        return SocialAccount::PLATFORM_TIKTOK;
    }

    public function publish(Clip $clip, SocialAccount $account, array $options = []): PublishResult
    {
        if ($clipError = $this->validateClip($clip)) {
            return $clipError;
        }

        // Check for mock mode or missing live credentials
        if (config('social.mock_mode') || $account->is_mock || empty($account->access_token)) {
            $mock = new MockSocialAdapter(SocialAccount::PLATFORM_TIKTOK);

            return $mock->publish($clip, $account, $options);
        }

        // Auto-refresh token if needed
        if (! $this->ensureValidToken($account)) {
            return PublishResult::failure('Failed to refresh expired TikTok OAuth token');
        }

        $apiBase = config('social.tiktok.api_base', 'https://open.tiktokapis.com/v2');
        $caption = $options['caption'] ?? $clip->title ?? 'ReelForge Short';

        // TikTok Video Publish Init
        $response = Http::withToken($account->access_token)
            ->post("{$apiBase}/post/publish/video/init/", [
                'post_info' => [
                    'title' => Str::limit($caption, 150),
                    'privacy_level' => 'PUBLIC_TO_EVERYONE',
                    'disable_duet' => false,
                    'disable_stitch' => false,
                    'disable_comment' => false,
                    'video_cover_timestamp_ms' => 1000,
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'video_url' => $clip->file_url,
                ],
            ]);

        if ($response->failed()) {
            return PublishResult::failure(
                'TikTok API Error: '.$response->body(),
                ['status' => $response->status()]
            );
        }

        $publishId = $response->json('data.publish_id') ?? 'tt_'.Str::uuid();

        return PublishResult::success($publishId, [
            'platform' => SocialAccount::PLATFORM_TIKTOK,
            'response' => $response->json(),
        ]);
    }

    public function refreshToken(SocialAccount $account): bool
    {
        if (config('social.mock_mode') || $account->is_mock) {
            $mock = new MockSocialAdapter(SocialAccount::PLATFORM_TIKTOK);

            return $mock->refreshToken($account);
        }

        if (empty($account->refresh_token)) {
            return false;
        }

        try {
            $tokens = $this->tikTokOAuth->refreshToken($account->refresh_token);
            $account->access_token = $tokens['access_token'];
            if (! empty($tokens['refresh_token'])) {
                $account->refresh_token = $tokens['refresh_token'];
            }
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
        return ! empty($account->access_token) && $account->platform === SocialAccount::PLATFORM_TIKTOK;
    }
}
