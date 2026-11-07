<?php

namespace Tests\Unit\Jobs;

use App\Modules\Publishing\Jobs\PublishToInstagramJob;
use App\Modules\Publishing\Jobs\PublishToTikTokJob;
use App\Modules\Publishing\Jobs\PublishToYouTubeJob;
use App\Modules\Videos\Jobs\DownloadVideoJob;
use App\Modules\Videos\Jobs\ProcessVideoJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobsTest extends TestCase
{
    public function test_publish_to_tiktok_job_properties_and_queue_contract(): void
    {
        $pubId = (string) Str::uuid();
        $job = new PublishToTikTokJob($pubId, ['caption' => 'Test TikTok']);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertEquals($pubId, $job->publicationId);
        $this->assertEquals('Test TikTok', $job->options['caption']);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(300, $job->timeout);
        $this->assertEquals([20, 40, 80], $job->backoff());
        $this->assertCount(1, $job->middleware());
        $this->assertInstanceOf(RateLimited::class, $job->middleware()[0]);
    }

    public function test_publish_to_instagram_job_properties_and_queue_contract(): void
    {
        $pubId = (string) Str::uuid();
        $job = new PublishToInstagramJob($pubId, ['caption' => 'Test Instagram']);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertEquals($pubId, $job->publicationId);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(300, $job->timeout);
        $this->assertEquals([20, 40, 80], $job->backoff());
        $this->assertCount(1, $job->middleware());
        $this->assertInstanceOf(RateLimited::class, $job->middleware()[0]);
    }

    public function test_publish_to_youtube_job_properties_and_queue_contract(): void
    {
        $pubId = (string) Str::uuid();
        $job = new PublishToYouTubeJob($pubId, ['caption' => 'Test YouTube']);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertEquals($pubId, $job->publicationId);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(300, $job->timeout);
        $this->assertEquals([20, 40, 80], $job->backoff());
        $this->assertCount(1, $job->middleware());
        $this->assertInstanceOf(RateLimited::class, $job->middleware()[0]);
    }

    public function test_download_video_job_properties(): void
    {
        $videoId = (string) Str::uuid();
        $job = new DownloadVideoJob($videoId, ['strategy' => 'fixed_interval']);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertEquals($videoId, $job->videoId);
        $this->assertEquals('fixed_interval', $job->params['strategy']);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(600, $job->timeout);
    }

    public function test_process_video_job_properties(): void
    {
        $videoId = (string) Str::uuid();
        $job = new ProcessVideoJob($videoId, ['strategy' => 'scene_detection', 'interval_seconds' => 45]);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertEquals($videoId, $job->videoId);
        $this->assertEquals('scene_detection', $job->params['strategy']);
        $this->assertEquals(45, $job->params['interval_seconds']);
        $this->assertEquals(2, $job->tries);
        $this->assertEquals(900, $job->timeout);
    }
}
