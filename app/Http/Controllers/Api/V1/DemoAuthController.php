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

        // Restrict the random pool to users of agencies explicitly flagged
        // is_demo=1. AgencyScope does not apply here (Auth::user() is null
        // before this login), so without this the query is unscoped across
        // EVERY tenant — any random active user of the given role, from any
        // real agency, would get a valid Sanctum token. is_demo is the one
        // signal that survives even if demo_mode_enabled is ever accidentally
        // flipped on staging/live (see the 2026-06-02 incident write-up in
        // DatabaseSeeder): a real agency is never flagged is_demo=1 (enforced
        // structurally by demo:refresh's notADemoDatabaseRefusal), so this
        // clause can never resolve to a real tenant's user.
        $user = User::where('role', $data['role'])
            ->where('is_active', true)
            ->whereHas('agency', fn ($q) => $q->where('is_demo', true))
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
