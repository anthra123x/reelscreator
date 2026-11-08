<?php

namespace App\Modules\Videos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Videos\DTOs\VideoIngestDTO;
use App\Modules\Videos\Services\VideoIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class VideoController extends Controller
{
    public function __construct(
        protected VideoIngestService $videoService
    ) {}

    /**
     * Submit video for processing.
     * POST /api/videos
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source_url' => ['required', 'string', 'url:http,https'],
            'strategy' => ['sometimes', 'string', 'in:fixed_interval,scene_detection,silence_detection,manual_timestamps'],
            'interval_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'max_duration' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'max_duration_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'vertical_mode' => ['sometimes', 'string', 'in:blur_padding,center_crop,letterbox'],
            'title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'auto_publish' => ['sometimes', 'boolean'],
            'platforms' => ['sometimes', 'array'],
            'platforms.*' => ['string', 'in:tiktok,instagram,youtube'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $dto = VideoIngestDTO::fromArray($validator->validated());
        $video = $this->videoService->create($dto, $request->user()?->id);

        return response()->json([
            'id' => $video->id,
            'source_url' => $video->source_url,
            'title' => $video->title,
            'status' => $video->status,
            'created_at' => $video->created_at?->toISOString() ?? now()->toISOString(),
        ], 201);
    }

    /**
     * Decompose existing video into 9:16 vertical reels and dispatch publications.
     * POST /api/videos/{id}/decompose
     */
    public function decompose(string $id, Request $request): JsonResponse
    {
        $video = $this->videoService->findById($id);
        if (! $video) {
            return response()->json(['message' => 'Video not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'interval_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'max_duration' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'max_duration_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'vertical_mode' => ['sometimes', 'string', 'in:blur_padding,center_crop,letterbox'],
            'auto_publish' => ['sometimes', 'boolean'],
            'platforms' => ['sometimes', 'array'],
            'platforms.*' => ['string', 'in:tiktok,instagram,youtube'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'video_duration' => ['sometimes', 'numeric', 'min:1'],
            'regenerate' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->videoService->decomposeVideo($video, $validator->validated());

        return response()->json([
            'message' => 'Video decomposed and reels dispatched for publication',
            'video' => $result['video'],
            'clips' => $result['clips'],
            'publications' => $result['publications'],
            'published_platforms' => $result['published_platforms'],
            'status' => 'ready',
        ], 200);
    }

    /**
     * Ingest and decompose a video into 9:16 vertical reels in one autonomous call.
     * POST /api/videos/decompose
     */
    public function ingestAndDecompose(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'source_url' => ['required', 'string', 'url:http,https'],
            'strategy' => ['sometimes', 'string', 'in:fixed_interval,scene_detection,silence_detection,manual_timestamps'],
            'interval_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'max_duration' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'max_duration_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'vertical_mode' => ['sometimes', 'string', 'in:blur_padding,center_crop,letterbox'],
            'title' => ['sometimes', 'nullable', 'string', 'max:500'],
            'auto_publish' => ['sometimes', 'boolean'],
            'platforms' => ['sometimes', 'array'],
            'platforms.*' => ['string', 'in:tiktok,instagram,youtube'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'video_duration' => ['sometimes', 'numeric', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $dto = VideoIngestDTO::fromArray($validator->validated());
        $video = $this->videoService->create($dto, $request->user()?->id, false);
        $result = $this->videoService->decomposeVideo($video, $validator->validated());

        return response()->json([
            'message' => 'Video decomposed and reels dispatched for publication',
            'video' => $result['video'],
            'clips' => $result['clips'],
            'publications' => $result['publications'],
            'published_platforms' => $result['published_platforms'],
            'status' => 'ready',
        ], 201);
    }

    /**
     * List submitted videos.
     * GET /api/videos
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $userId = $request->user()?->id;

        $videos = $this->videoService->list($userId, $status, $perPage);

        return response()->json($videos);
    }

    /**
     * Get video details.
     * GET /api/videos/{id}
     */
    public function show(string $id): JsonResponse
    {
        $video = $this->videoService->findById($id);
        if (! $video) {
            return response()->json(['message' => 'Video not found'], 404);
        }

        return response()->json($video);
    }

    /**
     * Fast polling status endpoint.
     * GET /api/videos/{id}/status
     */
    public function status(string $id): JsonResponse
    {
        try {
            $status = $this->videoService->getStatus($id);

            return response()->json($status);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => 'Video not found'], 404);
        }
    }

    /**
     * Delete video and associated clips.
     * DELETE /api/videos/{id}
     */
    public function destroy(string $id): Response|JsonResponse
    {
        $deleted = $this->videoService->delete($id);
        if (! $deleted) {
            return response()->json(['message' => 'Video not found'], 404);
        }

        return response()->noContent();
    }

    /**
     * Webhook callback from Rust engine upon job completion.
     * POST /api/internal/jobs/{id}/callback
     */
    public function webhookCallback(string $id, Request $request): JsonResponse
    {
        return app(InternalJobCallbackController::class)->handle($id, $request);
    }
}
