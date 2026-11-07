<?php

namespace Tests\Feature;

use App\Modules\Clips\DTOs\UpdateClipDTO;
use App\Modules\Clips\Models\Clip;
use App\Modules\Clips\Services\ClipStorageService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ClipsApiTest extends TestCase
{
    public function test_get_clips_by_video_id_returns_data_array(): void
    {
        $mockService = Mockery::mock(ClipStorageService::class);
        $this->app->instance(ClipStorageService::class, $mockService);

        $videoId = (string) Str::uuid();
        $clip1 = new Clip([
            'video_id' => $videoId,
            'file_path' => 'media/clips/c1.mp4',
            'duration' => 30.0,
            'start_time' => 0.0,
            'end_time' => 30.0,
            'status' => 'ready',
        ]);
        $clip1->id = (string) Str::uuid();

        $mockService->shouldReceive('listByVideo')
            ->with($videoId)
            ->once()
            ->andReturn(new Collection([$clip1]));

        $response = $this->getJson("/api/videos/{$videoId}/clips");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'video_id',
                        'file_url',
                        'duration',
                        'start_time',
                        'end_time',
                        'status',
                    ],
                ],
            ]);
    }

    public function test_get_clip_by_id_returns_200(): void
    {
        $mockService = Mockery::mock(ClipStorageService::class);
        $this->app->instance(ClipStorageService::class, $mockService);

        $clip = new Clip(['file_path' => 'media/clips/c.mp4', 'title' => 'Cool Clip']);
        $clip->id = (string) Str::uuid();

        $mockService->shouldReceive('findById')->with($clip->id)->once()->andReturn($clip);

        $response = $this->getJson("/api/clips/{$clip->id}");
        $response->assertStatus(200)
            ->assertJson(['id' => $clip->id, 'title' => 'Cool Clip']);
    }

    public function test_patch_clip_updates_metadata_and_returns_200(): void
    {
        $mockService = Mockery::mock(ClipStorageService::class);
        $this->app->instance(ClipStorageService::class, $mockService);

        $clip = new Clip(['title' => 'Updated Title', 'start_time' => 5.0, 'end_time' => 35.0]);
        $clip->id = (string) Str::uuid();

        $mockService->shouldReceive('update')
            ->with($clip->id, Mockery::type(UpdateClipDTO::class))
            ->once()
            ->andReturn($clip);

        $response = $this->patchJson("/api/clips/{$clip->id}", [
            'title' => 'Updated Title',
            'start_time' => 5.0,
            'end_time' => 35.0,
        ]);

        $response->assertStatus(200)
            ->assertJson(['title' => 'Updated Title']);
    }

    public function test_delete_clip_returns_204_no_content(): void
    {
        $mockService = Mockery::mock(ClipStorageService::class);
        $this->app->instance(ClipStorageService::class, $mockService);

        $id = (string) Str::uuid();
        $mockService->shouldReceive('delete')->with($id)->once()->andReturn(true);

        $response = $this->deleteJson("/api/clips/{$id}");
        $response->assertStatus(204);
    }
}
