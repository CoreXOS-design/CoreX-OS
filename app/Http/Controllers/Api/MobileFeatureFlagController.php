<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports which advanced AI features are enabled for the authenticated
 * user's agency AND that the user has permission to use. The mobile app
 * calls this on launch (and after agency switches) to know which UI
 * affordances to render.
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

        return response()->json([
            'aiVoice' => (bool) (
                $agency?->ai_voice_enabled
                && $user->hasPermission('use_ellie_voice')
            ),
            'aiImageRecognition' => (bool) (
                $agency?->ai_image_recognition_enabled
                && $user->hasPermission('use_property_image_ai')
            ),
            'agencyId' => $agency?->id,
            'userId'   => $user?->id,
        ]);
    }
}
