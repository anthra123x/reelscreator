<?php

namespace App\Modules\Publishing\Services;

use App\Models\User;
use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Adapters\InstagramPublisherAdapter;
use App\Modules\Publishing\Adapters\MockSocialAdapter;
use App\Modules\Publishing\Adapters\TikTokPublisherAdapter;
use App\Modules\Publishing\Adapters\YouTubePublisherAdapter;
use App\Modules\Publishing\Contracts\SocialPublisherAdapterInterface;
use App\Modules\Publishing\Jobs\PublishToInstagramJob;
use App\Modules\Publishing\Jobs\PublishToTikTokJob;
use App\Modules\Publishing\Jobs\PublishToYouTubeJob;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublishingLog;
use App\Modules\SocialAccounts\Models\SocialAccount;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PublishingOrchestrator
{
    public function __construct(
        protected TikTokPublisherAdapter $tikTokAdapter,
        protected InstagramPublisherAdapter $instagramAdapter,
        protected YouTubePublisherAdapter $youTubeAdapter
    ) {}

    /**
     * Resolve the appropriate social publisher adapter for an account.
     */
    public function getAdapter(SocialAccount $account): SocialPublisherAdapterInterface
    {
        if (config('social.mock_mode') || $account->is_mock) {
            return new MockSocialAdapter($account->platform);
        }

        return match ($account->platform) {
            SocialAccount::PLATFORM_TIKTOK => $this->tikTokAdapter,
            SocialAccount::PLATFORM_INSTAGRAM => $this->instagramAdapter,
            SocialAccount::PLATFORM_YOUTUBE => $this->youTubeAdapter,
            default => new MockSocialAdapter($account->platform),
        };
    }

    /**
     * Trigger or schedule publishing of a clip across multiple social platforms.
     *
     * @param  string[]  $platforms
     * @return array{publication_ids: string[], status: string}
     */
    public function publishClip(
        Clip $clip,
        array $platforms,
        ?string $caption = null,
        ?string $scheduledAt = null,
        ?string $userId = null
    ): array {
        if (empty($platforms)) {
            throw new InvalidArgumentException('At least one social platform must be selected');
        }

        $userId = $userId ?? $clip->video?->user_id ?? (User::first() ?? User::firstOrCreate(
            ['email' => 'default@reelforge.internal'],
            [
                'name' => 'Default Operator',
                'password' => bcrypt(Str::random(32)),
            ]
        ))->id;
        $publicationIds = [];

        foreach ($platforms as $platform) {
            // Find existing connected account or synthesize mock account
            $account = SocialAccount::where('user_id', $userId)
                ->where('platform', $platform)
                ->first();

            if (! $account) {
                // Provision mock account fallback to prevent pipeline blockage
                $account = new SocialAccount;
                $account->id = (string) Str::uuid();
                $account->user_id = $userId;
                $account->platform = $platform;
                $account->platform_user_id = "mock_{$platform}_".Str::random(8);
                $account->platform_username = "mock_{$platform}_creator";
                $account->access_token = 'mock_token_'.Str::random(32);
                $account->expires_at = Carbon::now()->addDays(30);
                $account->save();
            }

            // Idempotency guard: check if publication for this (clip_id, social_account_id) is already queued or in progress
            $existing = Publication::where('clip_id', $clip->id)
                ->where('social_account_id', $account->id)
                ->whereIn('status', [
                    Publication::STATUS_PENDING,
                    Publication::STATUS_SCHEDULED,
                    Publication::STATUS_PUBLISHING,
                ])
                ->first();

            if ($existing) {
                $publicationIds[] = $existing->id;

                continue;
            }

            $isScheduled = ! empty($scheduledAt) && Carbon::parse($scheduledAt)->isFuture();

            $publication = new Publication;
            $publication->id = (string) Str::uuid();
            $publication->clip_id = $clip->id;
            $publication->social_account_id = $account->id;
            $publication->status = $isScheduled ? Publication::STATUS_SCHEDULED : Publication::STATUS_PENDING;
            $publication->scheduled_at = $scheduledAt ? Carbon::parse($scheduledAt) : null;
            $publication->save();

            $publicationIds[] = $publication->id;

            $options = ['caption' => $caption ?? $clip->title ?? ''];

            // Log event: dispatched
            PublishingLog::create([
                'publication_id' => $publication->id,
                'clip_id' => $publication->clip_id,
                'social_account_id' => $publication->social_account_id,
                'platform' => $platform,
                'event' => PublishingLog::EVENT_DISPATCHED,
                'status' => $publication->status,
                'payload' => [
                    'caption' => $options['caption'],
                    'scheduled_at' => $scheduledAt,
                    'is_scheduled' => $isScheduled,
                ],
                'error_message' => null,
            ]);

            // Dispatch publishing queue job if immediate or sync
            if (! $isScheduled) {
                $this->dispatchPublicationJob($publication, $options);
            }
        }

        return [
            'publication_ids' => $publicationIds,
            'status' => 'queued',
        ];
    }

    /**
     * Dispatch queue job for specific platform.
     */
    protected function dispatchPublicationJob(Publication $publication, array $options): void
    {
        $platform = $publication->socialAccount?->platform;

        match ($platform) {
            SocialAccount::PLATFORM_TIKTOK => PublishToTikTokJob::dispatch($publication->id, $options),
            SocialAccount::PLATFORM_INSTAGRAM => PublishToInstagramJob::dispatch($publication->id, $options),
            SocialAccount::PLATFORM_YOUTUBE => PublishToYouTubeJob::dispatch($publication->id, $options),
            default => PublishToTikTokJob::dispatch($publication->id, $options),
        };
    }

    /**
     * Retry a failed publication.
     */
    public function retryPublication(string $publicationId): Publication
    {
        $publication = Publication::with(['clip', 'socialAccount'])->find($publicationId);
        if (! $publication) {
            throw new InvalidArgumentException("Publication not found: {$publicationId}");
        }

        $publication->status = Publication::STATUS_PENDING;
        $publication->error_message = null;
        $publication->save();

        // Retrieve prior caption from dispatched log if available to prevent metadata loss
        $originalLog = PublishingLog::where('publication_id', $publication->id)
            ->where('event', PublishingLog::EVENT_DISPATCHED)
            ->latest()
            ->first();

        $caption = $originalLog?->payload['caption'] ?? $publication->clip?->title ?? '';
        $options = ['caption' => $caption];

        PublishingLog::create([
            'publication_id' => $publication->id,
            'clip_id' => $publication->clip_id,
            'social_account_id' => $publication->social_account_id,
            'platform' => $publication->socialAccount?->platform ?? 'unknown',
            'event' => PublishingLog::EVENT_DISPATCHED,
            'status' => Publication::STATUS_PENDING,
            'payload' => [
                'retry' => true,
                'caption' => $caption,
            ],
            'error_message' => null,
        ]);

        $this->dispatchPublicationJob($publication, $options);

        return $publication;
    }

    /**
     * List audit log of publications.
     */
    public function listPublications(?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = Publication::with(['clip', 'socialAccount', 'publishingLogs'])->latest();

        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }
}
