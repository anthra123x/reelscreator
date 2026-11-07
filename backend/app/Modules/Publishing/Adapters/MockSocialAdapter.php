<?php

namespace App\Modules\Publishing\Adapters;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\DTOs\PublishResult;
use App\Modules\SocialAccounts\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Support\Str;

class MockSocialAdapter extends AbstractPublisherAdapter
{
    public function __construct(
        protected string $platform = 'mock'
    ) {}

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function publish(Clip $clip, SocialAccount $account, array $options = []): PublishResult
    {
        $platform = $account->platform ?: $this->platform;

        // 1. Simulation flag: simulate_network_failure
        if (! empty($options['simulate_network_failure'])) {
            return PublishResult::failure(
                "Simulated network failure: connection timed out while uploading to {$platform}",
                ['simulated' => true, 'retryable' => true, 'platform' => $platform]
            );
        }

        // 2. Simulation flag: simulate_token_expired
        if (! empty($options['simulate_token_expired'])) {
            return PublishResult::failure(
                'TokenExpiredException: OAuth access token has expired',
                ['simulated' => true, 'error_type' => 'token_expired', 'platform' => $platform]
            );
        }

        // 3. Generic error simulation / error_test username check
        if (! empty($options['simulate_error']) || str_contains($account->platform_username ?? '', 'error_test')) {
            $message = $options['error_message'] ?? "Simulated failure for {$platform}";

            return PublishResult::failure($message, ['simulated' => true, 'platform' => $platform]);
        }

        // 4. Token expiration check on $account and refresh handling
        if ($account->isExpired()) {
            if (! empty($options['skip_token_refresh'])) {
                return PublishResult::failure(
                    'TokenExpiredException: OAuth access token has expired',
                    ['simulated' => true, 'platform' => $platform]
                );
            }

            $refreshed = $this->refreshToken($account);
            if (! $refreshed) {
                return PublishResult::failure(
                    'TokenExpiredException: OAuth access token has expired and refresh failed',
                    ['simulated' => true, 'platform' => $platform]
                );
            }
        }

        // 5. Basic duration check
        if ($clip->duration <= 0) {
            return PublishResult::failure("Invalid clip duration: {$clip->duration}s");
        }

        // 6. Platform-specific duration constraints
        // YouTube Shorts limit: 60.0 seconds
        if (($platform === SocialAccount::PLATFORM_YOUTUBE || $this->platform === SocialAccount::PLATFORM_YOUTUBE) && $clip->duration > 60.0) {
            return PublishResult::failure(
                "PlatformConstraintViolation: YouTube Shorts duration cannot exceed 60 seconds (60.0s, given: {$clip->duration}s)",
                ['platform' => $platform, 'max_duration' => 60.0, 'duration' => $clip->duration]
            );
        }

        // Instagram Reels limit: 90.0 seconds
        if (($platform === SocialAccount::PLATFORM_INSTAGRAM || $this->platform === SocialAccount::PLATFORM_INSTAGRAM) && $clip->duration > 90.0) {
            return PublishResult::failure(
                "PlatformConstraintViolation: Instagram Reels duration cannot exceed 90 seconds (90.0s, given: {$clip->duration}s)",
                ['platform' => $platform, 'max_duration' => 90.0, 'duration' => $clip->duration]
            );
        }

        // 7. Simulation flag: simulate_chunking
        $chunkMetadata = [];
        if (! empty($options['simulate_chunking'])) {
            $chunkSize = (int) ($options['chunk_size'] ?? 1048576);
            $totalChunks = (int) ($options['total_chunks'] ?? 3);
            $chunks = [];
            for ($i = 1; $i <= $totalChunks; $i++) {
                $chunks[] = [
                    'chunk_index' => $i,
                    'total_chunks' => $totalChunks,
                    'bytes_uploaded' => $i * $chunkSize,
                    'status' => 'uploaded',
                ];
            }
            $chunkMetadata = [
                'chunked_upload' => true,
                'total_chunks' => $totalChunks,
                'chunk_size' => $chunkSize,
                'chunks' => $chunks,
            ];
        }

        $mockPostId = "mock_{$platform}_".Str::uuid();

        return PublishResult::success($mockPostId, array_merge([
            'platform' => $platform,
            'caption' => $options['caption'] ?? '',
            'simulated' => true,
            'published_at' => Carbon::now()->toIso8601String(),
        ], $chunkMetadata));
    }

    public function refreshToken(SocialAccount $account): bool
    {
        if (! empty($account->platform_username) && str_contains($account->platform_username, 'fail_refresh')) {
            return false;
        }

        $account->access_token = 'mock_refreshed_token_'.Str::random(32);
        $account->expires_at = Carbon::now()->addDays(30);

        if ($account->exists) {
            $account->save();
        }

        return true;
    }

    public function validateAccount(SocialAccount $account): bool
    {
        return ! empty($account->access_token) &&
            ! str_contains($account->platform_username ?? '', 'invalid_test');
    }
}
