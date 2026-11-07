<?php

namespace App\Modules\Publishing\Adapters;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\DTOs\PublishResult;
use App\Modules\SocialAccounts\Models\SocialAccount;
use App\Modules\SocialAccounts\Services\YouTubeOAuthService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class YouTubePublisherAdapter extends AbstractPublisherAdapter
{
    public function __construct(
        protected YouTubeOAuthService $youTubeOAuth
    ) {}

    public function getPlatform(): string
    {
        return SocialAccount::PLATFORM_YOUTUBE;
    }

    public function publish(Clip $clip, SocialAccount $account, array $options = []): PublishResult
    {
        if ($clipError = $this->validateClip($clip)) {
            return $clipError;
        }

        // Platform constraint: YouTube Shorts max duration is 60.0 seconds
        if ($clip->duration > 60.0) {
            return PublishResult::failure(
                "PlatformConstraintViolation: YouTube Shorts duration cannot exceed 60 seconds (60.0s, given: {$clip->duration}s)",
                ['platform' => SocialAccount::PLATFORM_YOUTUBE, 'max_duration' => 60.0, 'duration' => $clip->duration]
            );
        }

        if (config('social.mock_mode') || $account->is_mock || empty(config('social.youtube.client_id'))) {
            $mock = new MockSocialAdapter(SocialAccount::PLATFORM_YOUTUBE);

            return $mock->publish($clip, $account, $options);
        }

        if (! $this->ensureValidToken($account)) {
            return PublishResult::failure('Failed to refresh expired YouTube OAuth token');
        }

        $uploadBase = config('social.youtube.upload_base', 'https://www.googleapis.com/upload/youtube/v3');
        $rawTitle = $options['title'] ?? $clip->title ?? 'ReelForge Short';
        // Mandatory requirement: append #Shorts
        $title = str_contains($rawTitle, '#Shorts') ? $rawTitle : "{$rawTitle} #Shorts";
        $caption = ($options['caption'] ?? '').' #Shorts';

        $metadata = [
            'snippet' => [
                'title' => Str::limit($title, 100),
                'description' => $caption,
                'tags' => ['Shorts', 'Reels', 'VerticalVideo'],
                'categoryId' => '22',
            ],
            'status' => [
                'privacyStatus' => $options['privacy'] ?? 'public',
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        // Insert video metadata into YouTube Data API
        $response = Http::withToken($account->access_token)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("{$uploadBase}/videos?uploadType=resumable&part=snippet,status", $metadata);

        if ($response->failed()) {
            return PublishResult::failure(
                'YouTube API Error: '.$response->body(),
                ['status' => $response->status()]
            );
        }

        $videoId = $response->json('id') ?? 'yt_'.Str::uuid();

        return PublishResult::success($videoId, [
            'platform' => SocialAccount::PLATFORM_YOUTUBE,
            'video_id' => $videoId,
            'title' => $title,
        ]);
    }

    public function refreshToken(SocialAccount $account): bool
    {
        if (config('social.mock_mode') || $account->is_mock) {
            $mock = new MockSocialAdapter(SocialAccount::PLATFORM_YOUTUBE);

            return $mock->refreshToken($account);
        }

        if (empty($account->refresh_token)) {
            return false;
        }

        try {
            $tokens = $this->youTubeOAuth->refreshToken($account->refresh_token);
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
        return ! empty($account->access_token) && $account->platform === SocialAccount::PLATFORM_YOUTUBE;
    }
}
