<?php

namespace App\Modules\Videos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Videos\Services\VideoIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class InternalJobCallbackController extends Controller
{
    public function __construct(
        protected VideoIngestService $videoService
    ) {}

    /**
     * Handle internal webhook callback from Rust video processing engine.
     * POST /api/internal/jobs/{id}/callback
     */
    public function __invoke(string $id, Request $request): JsonResponse
    {
        return $this->handle($id, $request);
    }

    /**
     * Process internal webhook notification with authentication and status reconciliation.
     */
    public function handle(string $id, Request $request): JsonResponse
    {
        // 1. Fail-closed authentication check
        $expectedSecret = config('services.rust_engine.internal_secret');

        if (! empty($expectedSecret)) {
            $providedSecret = $request->header('X-Internal-Secret') ?? $request->header('X-Webhook-Secret');
            if (! $providedSecret || ! hash_equals((string) $expectedSecret, (string) $providedSecret)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized internal webhook callback',
                ], 401);
            }
        } elseif (! app()->environment('testing')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Internal webhook authentication not configured',
            ], 401);
        }

        // 2. Strict payload schema validation
        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:completed,failed,cancelled'],
            'clips' => ['nullable', 'array'],
            'clips.*.file_path' => ['required_if:status,completed', 'string'],
            'clips.*.thumbnail_path' => ['nullable', 'string'],
            'clips.*.start_time' => ['nullable', 'numeric'],
            'clips.*.end_time' => ['nullable', 'numeric'],
            'clips.*.duration' => ['nullable', 'numeric'],
            'error' => ['nullable', 'string'],
            'error_message' => ['nullable', 'string'],
            'job_id' => ['nullable', 'string'],
            'timestamp' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid webhook payload schema',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 3. Delegate to service for clip persistence and state transition
        try {
            $video = $this->videoService->handleWebhookCallback($id, $request->all());

            return response()->json([
                'status' => 'ok',
                'video_id' => $video->id,
                'video_status' => $video->status,
                'clips_count' => count($video->clips ?? []),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
