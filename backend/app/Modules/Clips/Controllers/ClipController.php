<?php

namespace App\Modules\Clips\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clips\DTOs\UpdateClipDTO;
use App\Modules\Clips\Services\ClipStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class ClipController extends Controller
{
    public function __construct(
        protected ClipStorageService $clipService
    ) {}

    /**
     * List all clips across all videos.
     * GET /api/clips
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 50)));
        $clips = $this->clipService->listAll($perPage);

        return response()->json($clips);
    }

    /**
     * List clips generated for a video.
     * GET /api/videos/{videoId}/clips
     */
    public function indexByVideo(string $videoId): JsonResponse
    {
        $clips = $this->clipService->listByVideo($videoId);

        return response()->json([
            'data' => $clips,
        ]);
    }

    /**
     * Get clip details.
     * GET /api/clips/{id}
     */
    public function show(string $id): JsonResponse
    {
        $clip = $this->clipService->findById($id);
        if (! $clip) {
            return response()->json(['message' => 'Clip not found'], 404);
        }

        return response()->json($clip);
    }

    /**
     * Update clip metadata or trimming bounds.
     * PATCH /api/clips/{id}
     */
    public function update(string $id, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'start_time' => ['sometimes', 'numeric', 'min:0'],
            'end_time' => ['sometimes', 'numeric', 'min:0'],
            'metadata' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $dto = UpdateClipDTO::fromArray($validator->validated());
            $clip = $this->clipService->update($id, $dto);

            return response()->json($clip);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * Delete an individual clip.
     * DELETE /api/clips/{id}
     */
    public function destroy(string $id): Response|JsonResponse
    {
        $deleted = $this->clipService->delete($id);
        if (! $deleted) {
            return response()->json(['message' => 'Clip not found'], 404);
        }

        return response()->noContent();
    }
}
