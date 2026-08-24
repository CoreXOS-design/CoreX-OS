<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports which advanced AI features the authenticated user may use. AI is
 * universal at the agency level — every agency has full AI access — so these
 * booleans reflect only the per-user permission. The mobile app calls this on
 * launch (and after agency switches) to know which UI affordances to render.
 *
 * GET /api/v1/mobile/features
 *   → { "aiVoice": true, "aiImageRecognition": false }
 *
 * NB: the mobile client reads these flags by their EXACT camelCase keys
 * (`aiVoice`, `aiImageRecognition`) and hides the corresponding UI when the
 * key is absent/falsy. Keep them camelCase — a snake_case key reads as
 * `undefined` on the client and silently disables the whole feature.
 */
class MobileFeatureFlagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        $agency = $user?->agency;

        // Advanced AI features are universal at the agency level — every agency
        // has full AI access, so there is no per-agency enable flag. These
        // booleans now reflect only the per-user permission: whether THIS user
        // is allowed to use the feature.
        return response()->json([
            'aiVoice'            => (bool) $user?->hasPermission('use_ellie_voice'),
            'aiImageRecognition' => (bool) $user?->hasPermission('use_property_image_ai'),
            'agencyId'           => $agency?->id,
            'userId'             => $user?->id,
        ]);
    }
}
