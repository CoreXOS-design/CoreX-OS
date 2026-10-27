<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TrainingVideos\ChunkedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives an uploaded training video in pieces.
 *
 * Spec: .ai/specs/training-videos.md §8.5 (U3). Routes: /api/v1/admin/training-video-uploads/*,
 * session-authenticated, named api.v1.training-video-uploads.* (non-negotiable #7).
 *
 * ══ OWNER-ONLY. NO PERMISSION KEY. ══ Same reasoning as the admin screens: this publishes
 * to CoreX's own public page, so it is never delegable. Every action re-checks the owner role,
 * and the service additionally binds each upload to the user who started it — a different user
 * holding a valid upload id is still refused.
 */
class TrainingVideoUploadController extends Controller
{
    public function __construct(private readonly ChunkedUploadService $uploads)
    {
    }

    /** POST /api/v1/admin/training-video-uploads — begin an upload. */
    public function start(Request $request): JsonResponse
    {
        $this->assertOwner($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->uploads->start($request->user(), $data['name'], (int) $data['size']));
    }

    /** POST /api/v1/admin/training-video-uploads/{upload}/chunks — one piece (re-sendable). */
    public function chunk(Request $request, string $upload): JsonResponse
    {
        $this->assertOwner($request);

        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ]);

        $this->uploads->storeChunk($request->user(), $upload, (int) $data['index'], $request->file('chunk'));

        return response()->json(['ok' => true, 'index' => (int) $data['index']]);
    }

    /** POST /api/v1/admin/training-video-uploads/{upload}/complete — stitch and check it is a video. */
    public function complete(Request $request, string $upload): JsonResponse
    {
        $this->assertOwner($request);

        return response()->json($this->uploads->complete($request->user(), $upload));
    }

    /**
     * GET /api/v1/admin/training-video-uploads/progress — how far each video still being shrunk has got.
     * Polled by the Training Videos list so the owner can watch it (spec §8.11).
     */
    public function progress(Request $request): JsonResponse
    {
        $this->assertOwner($request);

        $rows = \App\Models\TrainingVideo::withTrashed()
            ->where('source', \App\Models\TrainingVideo::SOURCE_UPLOAD)
            ->whereNotNull('pending_upload_id')
            ->where('status', '!=', \App\Models\TrainingVideo::STATUS_FAILED)
            ->get()
            ->map(fn ($v) => [
                'id'      => $v->id,
                'percent' => $v->progress_percent,
                'eta'     => $v->etaSeconds(),
            ]);

        return response()->json(['videos' => $rows->values(), 'active' => $rows->count()]);
    }

    private function assertOwner(Request $request): void
    {
        abort_unless($request->user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }
}
