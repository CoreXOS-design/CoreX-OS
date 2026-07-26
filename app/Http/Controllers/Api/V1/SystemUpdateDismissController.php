<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SystemUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * System Updates — per-user dismissal.
 *
 * Spec: .ai/specs/system-updates.md §11.2.
 *
 * Writes are strictly self-scoped: user_id is taken from auth(), never from input,
 * so these endpoints need authentication but no further permission gate — there is
 * no cross-user or cross-tenant surface to protect. Same shape and reasoning as
 * TourProgressController.
 *
 * Deliberately forgiving: unknown, archived or out-of-audience ids are IGNORED
 * rather than rejected. A modal that was open while the owner archived an update
 * must never hand the user an error for closing it.
 */
class SystemUpdateDismissController extends Controller
{
    public function __invoke(Request $request, SystemUpdateService $service): JsonResponse
    {
        $validated = $request->validate([
            'ids'   => ['required', 'array', 'max:50'],
            'ids.*' => ['integer'],
        ]);

        $recorded = $service->dismiss($request->user(), $validated['ids']);

        return response()->json([
            'ok'       => true,
            'recorded' => $recorded,
        ]);
    }
}
