<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Auth\DemoLoginController;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoAuthController extends Controller
{
    private const ALLOWED_ROLES = ['admin', 'branch_manager', 'agent', 'viewer'];

    public function status(): JsonResponse
    {
        return response()->json([
            'enabled' => DemoLoginController::isEnabled(),
            'roles'   => self::ALLOWED_ROLES,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        if (!DemoLoginController::isEnabled()) {
            return response()->json(['message' => 'Demo mode is not enabled.'], 403);
        }

        $data = $request->validate([
            'role' => 'required|string|in:' . implode(',', self::ALLOWED_ROLES),
        ]);

        $user = User::where('role', $data['role'])
            ->where('is_active', true)
            ->inRandomOrder()
            ->first();

        if (!$user) {
            return response()->json([
                'message' => "No active demo user found with role '{$data['role']}'.",
            ], 404);
        }

        $token = $user->createToken('corex-mobile-demo')->plainTextToken;

        $agency = $user->effectiveAgencyId()
            ? Agency::withoutGlobalScope(AgencyScope::class)->find($user->effectiveAgencyId())
            : null;

        return response()->json([
            'token' => $token,
            'user'  => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'role'       => $user->role,
                'branch'     => $user->branch?->name ?? null,
                'ffc_status' => $user->ffc_status ?? null,
                'agency'     => $agency ? [
                    'id'   => $agency->id,
                    'slug' => $agency->slug,
                    'name' => $agency->name,
                ] : null,
            ],
        ]);
    }
}
