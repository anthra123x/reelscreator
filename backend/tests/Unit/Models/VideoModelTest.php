<?php

namespace Tests\Unit\Models;

use App\Modules\Clips\Models\Clip;
use App\Modules\Videos\Models\Video;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class VideoModelTest extends TestCase
{
    public function test_video_model_has_uuid_primary_key_and_casts_metadata(): void
    {
        $video = new Video([
            'source_url' => 'https://example.com/source.mp4',
            'title' => 'Sample Long Video',
            'status' => Video::STATUS_PENDING,
            'duration' => 120.5,
            'metadata' => [
                'strategy' => 'fixed_interval',
                'interval_seconds' => 30,
            ],
        ]);
        $video->id = (string) Str::uuid();

        $this->assertTrue(Str::isUuid($video->id));
        $this->assertEquals('Sample Long Video', $video->title);
        $this->assertEquals(Video::STATUS_PENDING, $video->status);
        $this->assertEquals(120.5, $video->duration);
        $this->assertIsArray($video->metadata);
        $this->assertEquals('fixed_interval', $video->metadata['strategy']);
    }

    public function test_video_status_state_machine_allows_legal_transitions(): void
    {
        $video = new Video(['status' => Video::STATUS_PENDING]);

        $this->assertTrue($video->canTransitionTo(Video::STATUS_DOWNLOADING));
        $this->assertTrue($video->canTransitionTo(Video::STATUS_PROCESSING));
        $this->assertTrue($video->canTransitionTo(Video::STATUS_FAILED));
        $this->assertFalse($video->canTransitionTo(Video::STATUS_READY));

        $video->transitionTo(Video::STATUS_DOWNLOADING);
        $this->assertEquals(Video::STATUS_DOWNLOADING, $video->status);

        $this->assertTrue($video->canTransitionTo(Video::STATUS_PROCESSING));
        $this->assertTrue($video->canTransitionTo(Video::STATUS_FAILED));

        $video->transitionTo(Video::STATUS_PROCESSING);
        $this->assertEquals(Video::STATUS_PROCESSING, $video->status);

        $this->assertTrue($video->canTransitionTo(Video::STATUS_READY));
        $video->transitionTo(Video::STATUS_READY);
        $this->assertEquals(Video::STATUS_READY, $video->status);
    }

    public function test_video_state_machine_rejects_illegal_transitions(): void
    {
        $video = new Video(['status' => Video::STATUS_READY]);

        $this->expectException(InvalidArgumentException::class);
        $video->transitionTo(Video::STATUS_DOWNLOADING);
    }

    public function test_video_failed_status_can_transition_back_to_pending(): void
    {
        $video = new Video(['status' => Video::STATUS_FAILED]);
        $this->assertTrue($video->canTransitionTo(Video::STATUS_PENDING));

        $video->transitionTo(Video::STATUS_PENDING);
        $this->assertEquals(Video::STATUS_PENDING, $video->status);
    }

    public function test_clips_relationship_definition(): void
    {
        $video = new Video;
        $relation = $video->clips();

        $this->assertInstanceOf(HasMany::class, $relation);
        $this->assertInstanceOf(Clip::class, $relation->getRelated());
    }
}
