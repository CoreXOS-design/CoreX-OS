<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Mobile "Delete my account" — Apple App Store guideline 5.1.1(v).
 *
 * Spec: .ai/specs/mobile-app-access.md
 *
 * Does NOT delete the underlying User row (non-negotiable #1, and an agent's
 * CoreX account carries deals/commissions/FICA history that is not solely
 * the individual's to delete). Turns app_access OFF instead — mobile login
 * is refused from this point on with an "account deleted" message, while
 * the agent's web CoreX account is completely unaffected (Johan, 2026-08-24).
 */
class AppAccessController extends Controller
{
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            return response()->json([
                'message' => 'Incorrect password.',
                'code'    => 'invalid_password',
            ], 422);
        }

        $user->revokeAppAccess();

        return response()->json([
            'message' => 'App access has been removed. You can restore it at any time from My Portal on the CoreX website.',
        ]);
    }
}
