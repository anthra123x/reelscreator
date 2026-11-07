<?php

namespace Tests\Unit\Models;

use App\Modules\Clips\Models\Clip;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Videos\Models\Video;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClipModelTest extends TestCase
{
    public function test_clip_attributes_and_casts(): void
    {
        $clip = new Clip([
            'video_id' => (string) Str::uuid(),
            'file_path' => 'media/clips/test_001.mp4',
            'thumbnail_path' => 'media/thumbnails/test_001.jpg',
            'title' => 'Viral Moment 1',
            'start_time' => 10.0,
            'end_time' => 40.0,
            'duration' => 30.0,
            'status' => 'ready',
            'metadata' => [
                'width' => 1080,
                'height' => 1920,
            ],
        ]);
        $clip->id = (string) Str::uuid();

        $this->assertTrue(Str::isUuid($clip->id));
        $this->assertEquals(10.0, $clip->start_time);
        $this->assertEquals(40.0, $clip->end_time);
        $this->assertEquals(30.0, $clip->duration);
        $this->assertEquals(1080, $clip->metadata['width']);
        $this->assertEquals(1920, $clip->metadata['height']);
    }

    public function test_clip_file_and_thumbnail_url_accessors(): void
    {
        $clip = new Clip([
            'file_path' => 'media/clips/test_clip.mp4',
            'thumbnail_path' => 'media/thumbnails/test_thumb.jpg',
        ]);

        $this->assertStringContainsString('media/clips/test_clip.mp4', $clip->file_url);
        $this->assertStringContainsString('media/thumbnails/test_thumb.jpg', $clip->thumbnail_url);
    }

    public function test_clip_url_accessors_preserve_external_http_urls(): void
    {
        $clip = new Clip([
            'file_path' => 'https://cdn.example.com/clips/remote.mp4',
            'thumbnail_path' => 'https://cdn.example.com/thumbs/remote.jpg',
        ]);

        $this->assertEquals('https://cdn.example.com/clips/remote.mp4', $clip->file_url);
        $this->assertEquals('https://cdn.example.com/thumbs/remote.jpg', $clip->thumbnail_url);
    }

    public function test_clip_relationships_definition(): void
    {
        $clip = new Clip;
        $this->assertInstanceOf(BelongsTo::class, $clip->video());
        $this->assertInstanceOf(Video::class, $clip->video()->getRelated());

        $this->assertInstanceOf(HasMany::class, $clip->publications());
        $this->assertInstanceOf(Publication::class, $clip->publications()->getRelated());
    }
}
