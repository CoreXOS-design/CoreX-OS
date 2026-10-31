<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\VideoAd;
use App\Services\AdStudio\AdScriptValidator;
use App\Services\AdStudio\VideoAdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * AT-527 - the two JSON calls of the Ad Studio pages (non-negotiable #7: under /api/v1, named, in the Admin -> API catalogue): the editor's live check and the
 * list's progress poll. System Owner only (owner_only middleware + the check here). Spec .ai/specs/training-videos.md 9.21.8.
 */
class VideoAdApiController extends Controller
{
    private function assertOwner(): void
    {
        abort_unless(Auth::user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    /** POST /api/v1/admin/video-ads/validate - the editor's draft in, plain problems + the estimated seconds out. */
    public function validateDraft(Request $request, VideoAdService $service, AdScriptValidator $validator): JsonResponse
    {
        $this->assertOwner();
        $draft = $service->normalise((array) $request->input('draft', []));

        return response()->json($validator->check($draft));
    }

    /** GET /api/v1/admin/video-ads/progress - every ad being made, with its percent; also tells the page when one has just finished. */
    public function progress(VideoAdService $service): JsonResponse
    {
        $this->assertOwner();
        $rows = VideoAd::query()->whereIn('status', [VideoAd::STATUS_QUEUED, VideoAd::STATUS_RENDERING])->get()
            ->map(fn (VideoAd $ad) => $service->sync($ad))
            ->filter(fn (VideoAd $ad) => $ad->isWorking())   // one that has just finished (or failed) drops out, which tells the page to reload
            ->map(fn (VideoAd $ad) => ['id' => $ad->id, 'name' => $ad->name, 'status' => $ad->status, 'percent' => (int) ($ad->progress_percent ?? 0), 'stage' => $ad->stage,
                'failure' => $ad->failure_reason]);

        return response()->json(['working' => $rows->values()]);
    }
}
