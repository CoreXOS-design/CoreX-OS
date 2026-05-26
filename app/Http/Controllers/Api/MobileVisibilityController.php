<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesMobileDataScope;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/mobile/visibility
 *
 * Tells the mobile app what data-visibility options the logged-in user has
 * for Contacts and Properties, so it can decide whether to render the
 * "Mine / All / pick an agent" filter chips — exactly like the web sidebar
 * agent picker. When a module's `can_pick_agent` is false the app must show
 * ONLY the user's own records and hide the chips entirely.
 */
class MobileVisibilityController extends Controller
{
    use ResolvesMobileDataScope;

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'contacts'   => $this->moduleVisibility($user, 'contacts'),
            'properties' => $this->moduleVisibility($user, 'properties'),
        ]);
    }
}
