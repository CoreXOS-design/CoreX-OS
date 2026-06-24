<?php

namespace App\Http\Controllers;

use App\Models\UserTourProgress;
use App\Support\Tours\TourRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Interactive help-tour progress.
 *
 * Writes are strictly self-scoped: a user can only ever mark their OWN tour
 * progress (user_id is taken from auth(), never from input), so these endpoints
 * need authentication but no further permission gate — there is no cross-tenant
 * surface to protect.
 *
 * @see \App\Support\Tours\TourRegistry
 */
class TourProgressController extends Controller
{
    /** Mark a tour as completed (reached the final step). */
    public function seen(Request $request, string $tourKey): JsonResponse
    {
        return $this->mark($request, $tourKey, 'completed_at');
    }

    /** Mark a tour as dismissed (skipped / "don't show again"). */
    public function dismiss(Request $request, string $tourKey): JsonResponse
    {
        return $this->mark($request, $tourKey, 'dismissed_at');
    }

    private function mark(Request $request, string $tourKey, string $column): JsonResponse
    {
        // Only known tours are writable — keeps the table free of stale keys.
        if (! TourRegistry::find($tourKey)) {
            return response()->json(['ok' => false, 'error' => 'Unknown tour.'], 404);
        }

        $progress = UserTourProgress::firstOrNew([
            'user_id'  => $request->user()->id,
            'tour_key' => $tourKey,
        ]);

        $progress->{$column} = now();
        $progress->save();

        return response()->json([
            'ok'           => true,
            'tour_key'     => $tourKey,
            'completed_at' => optional($progress->completed_at)->toIso8601String(),
            'dismissed_at' => optional($progress->dismissed_at)->toIso8601String(),
        ]);
    }
}
