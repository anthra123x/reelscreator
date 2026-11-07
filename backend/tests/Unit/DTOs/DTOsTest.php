<?php

namespace Tests\Unit\DTOs;

use App\Modules\Clips\DTOs\UpdateClipDTO;
use App\Modules\Publishing\DTOs\PublishResult;
use App\Modules\Videos\DTOs\VideoIngestDTO;
use InvalidArgumentException;
use Tests\TestCase;

class DTOsTest extends TestCase
{
    public function test_video_ingest_dto_validates_strategy_and_modes(): void
    {
        $dto = VideoIngestDTO::fromArray([
            'source_url' => 'https://youtube.com/watch?v=123',
            'strategy' => 'scene_detection',
            'interval_seconds' => 45,
            'max_duration' => 60,
            'vertical_mode' => 'center_crop',
            'title' => 'My Video',
        ]);

        $this->assertEquals('https://youtube.com/watch?v=123', $dto->sourceUrl);
        $this->assertEquals('scene_detection', $dto->strategy);
        $this->assertEquals(45, $dto->intervalSeconds);
        $this->assertEquals(60, $dto->maxDurationSeconds);
        $this->assertEquals('center_crop', $dto->verticalMode);
        $this->assertEquals('My Video', $dto->title);

        $array = $dto->toArray();
        $this->assertEquals('https://youtube.com/watch?v=123', $array['source_url']);
    }

    public function test_video_ingest_dto_rejects_unsupported_strategy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported strategy');

        new VideoIngestDTO(
            sourceUrl: 'https://example.com/video.mp4',
            strategy: 'magic_ai_cut'
        );
    }

    public function test_video_ingest_dto_rejects_negative_interval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('interval_seconds must be positive');

        new VideoIngestDTO(
            sourceUrl: 'https://example.com/video.mp4',
            intervalSeconds: -5
        );
    }

    public function test_update_clip_dto_from_array(): void
    {
        $dto = UpdateClipDTO::fromArray([
            'title' => 'Edited Clip',
            'start_time' => 12.5,
            'end_time' => 42.5,
            'metadata' => ['key' => 'val'],
        ]);

        $this->assertEquals('Edited Clip', $dto->title);
        $this->assertEquals(12.5, $dto->startTime);
        $this->assertEquals(42.5, $dto->endTime);
        $this->assertEquals(['key' => 'val'], $dto->metadata);
    }

    public function test_publish_result_factories(): void
    {
        $success = PublishResult::success('post_id_999', ['platform' => 'tiktok']);
        $this->assertTrue($success->success);
        $this->assertEquals('post_id_999', $success->platformPostId);
        $this->assertNull($success->errorMessage);

        $failure = PublishResult::failure('Rate limit exceeded', ['code' => 429]);
        $this->assertFalse($failure->success);
        $this->assertNull($failure->platformPostId);
        $this->assertEquals('Rate limit exceeded', $failure->errorMessage);
    }
}
