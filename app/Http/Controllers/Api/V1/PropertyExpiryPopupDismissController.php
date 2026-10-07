<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Properties\MandateExpiryPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AT-448 — expiring-soon popup: per-user "I have seen these" record.
 *
 * Spec: .ai/specs/at448-property-expiry.md §7 flow A.
 *
 * Self-scoped like SystemUpdateDismissController: user_id comes from auth(),
 * never from input, and every posted property id is re-checked against the
 * user's OWN listings (not their role-wide view — an admin cannot mark someone
 * else's listing as seen either) before a row is written — a crafted id for any
 * other listing, or another agency's, is simply ignored, never an error.
 * Idempotent: posting the same ids twice records nothing new.
 */
class PropertyExpiryPopupDismissController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids'   => ['required', 'array', 'max:50'],
            'ids.*' => ['integer'],
        ]);

        $user     = $request->user();
        $agencyId = (int) ($user?->effectiveAgencyId() ?: 0);

        $recorded = MandateExpiryPolicy::markAnnounced(
            $user,
            $agencyId,
            $validated['ids']
        );

        return response()->json([
            'ok'       => true,
            'recorded' => $recorded,
        ]);
    }
}
