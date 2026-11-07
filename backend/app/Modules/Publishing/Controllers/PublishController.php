<?php

namespace App\Modules\Publishing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Clips\Services\ClipStorageService;
use App\Modules\Publishing\Services\PublishingOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class PublishController extends Controller
{
    public function __construct(
        protected ClipStorageService $clipService,
        protected PublishingOrchestrator $orchestrator
    ) {}

    /**
     * Trigger or schedule multi-platform publication for a clip.
     * POST /api/clips/{id}/publish
     */
    public function publish(string $id, Request $request): JsonResponse
    {
        $clip = $this->clipService->findById($id);
        if (! $clip) {
            return response()->json(['message' => 'Clip not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['required', 'string', 'in:tiktok,instagram,youtube'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:2200'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->orchestrator->publishClip(
                clip: $clip,
                platforms: $request->input('platforms'),
                caption: $request->input('caption'),
                scheduledAt: $request->input('scheduled_at'),
                userId: $request->user()?->id
            );

            return response()->json($result, 202);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * List audit log of publications.
     * GET /api/publications
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));

        $publications = $this->orchestrator->listPublications($status, $perPage);

        return response()->json($publications);
    }

    /**
     * Retry a previously failed publication.
     * POST /api/publications/{id}/retry
     */
    public function retry(string $id): JsonResponse
    {
        try {
            $publication = $this->orchestrator->retryPublication($id);

            return response()->json([
                'message' => 'Publication retry scheduled',
                'publication' => $publication,
                'status' => 'queued',
            ], 202);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
