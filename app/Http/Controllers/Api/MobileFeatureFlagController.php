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
 *   → { "ai_voice": true, "ai_image_recognition": false }
 */
class MobileFeatureFlagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        $agency = $user?->agency;

        return response()->json([
            'ai_voice' => (bool) (
                $agency?->ai_voice_enabled
                && $user->hasPermission('use_ellie_voice')
            ),
            'ai_image_recognition' => (bool) (
                $agency?->ai_image_recognition_enabled
                && $user->hasPermission('use_property_image_ai')
            ),
            'agency_id' => $agency?->id,
            'user_id'   => $user?->id,
        ]);
    }
}
