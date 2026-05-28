<?php

declare(strict_types=1);

namespace App\Http\Controllers\Map;

use App\Http\Controllers\Controller;
use App\Models\Map\MapSavedSearch;
use App\Models\Scopes\AgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase A.3.2 — CRUD for the Map's per-user saved searches.
 *
 *   GET    /corex/map/saved-searches            — list (incl. is_default)
 *   POST   /corex/map/saved-searches            — create
 *   PATCH  /corex/map/saved-searches/{id}       — rename or set-default
 *   DELETE /corex/map/saved-searches/{id}       — soft-delete
 *
 * Scoping: every query is narrowed by (agency_id, user_id) at the WHERE
 * level. Owner row guarantees a user cannot read/touch another user's
 * searches even within the same agency.
 */
final class MapSavedSearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$agencyId, $userId] = $this->ownerOrFail($request);

        $rows = MapSavedSearch::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'filter_payload', 'is_default', 'updated_at']);

        return response()->json(['saved_searches' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        [$agencyId, $userId] = $this->ownerOrFail($request);

        $validated = $request->validate([
            'name'           => 'required|string|max:120',
            'filter_payload' => 'required|array',
            'is_default'     => 'sometimes|boolean',
        ]);

        $name = trim($validated['name']);

        // Per-user uniqueness — Eloquent unique check first for a friendly
        // 422, then a DB-level guard via the unique index for race safety.
        $duplicate = MapSavedSearch::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('user_id', $userId)
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->exists();
        if ($duplicate) {
            return response()->json([
                'error' => 'A saved search with this name already exists.',
            ], 422);
        }

        $row = DB::transaction(function () use ($agencyId, $userId, $name, $validated) {
            if ($validated['is_default'] ?? false) {
                $this->clearDefault($agencyId, $userId);
            }
            return MapSavedSearch::create([
                'agency_id'      => $agencyId,
                'user_id'        => $userId,
                'name'           => $name,
                'filter_payload' => $validated['filter_payload'],
                'is_default'     => (bool) ($validated['is_default'] ?? false),
            ]);
        });

        return response()->json(['saved_search' => $row], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        [$agencyId, $userId] = $this->ownerOrFail($request);

        $row = MapSavedSearch::withoutGlobalScope(AgencyScope::class)
            ->where('id', $id)
            ->where('agency_id', $agencyId)
            ->where('user_id', $userId)
            ->first();
        if (!$row) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        $validated = $request->validate([
            'name'           => 'sometimes|string|max:120',
            'filter_payload' => 'sometimes|array',
            'is_default'     => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($row, $validated, $agencyId, $userId) {
            if (array_key_exists('is_default', $validated) && $validated['is_default']) {
                $this->clearDefault($agencyId, $userId, exceptId: $row->id);
            }
            if (array_key_exists('name', $validated)) {
                $row->name = trim($validated['name']);
            }
            if (array_key_exists('filter_payload', $validated)) {
                $row->filter_payload = $validated['filter_payload'];
            }
            if (array_key_exists('is_default', $validated)) {
                $row->is_default = (bool) $validated['is_default'];
            }
            $row->save();
        });

        return response()->json(['saved_search' => $row->fresh()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        [$agencyId, $userId] = $this->ownerOrFail($request);

        $row = MapSavedSearch::withoutGlobalScope(AgencyScope::class)
            ->where('id', $id)
            ->where('agency_id', $agencyId)
            ->where('user_id', $userId)
            ->first();
        if (!$row) {
            return response()->json(['error' => 'Not found.'], 404);
        }
        $row->delete();
        return response()->json(['ok' => true]);
    }

    private function clearDefault(int $agencyId, int $userId, ?int $exceptId = null): void
    {
        $q = MapSavedSearch::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('user_id', $userId)
            ->where('is_default', true);
        if ($exceptId !== null) $q->where('id', '!=', $exceptId);
        $q->update(['is_default' => false]);
    }

    /** @return array{0:int,1:int} */
    private function ownerOrFail(Request $request): array
    {
        $user = $request->user();
        $agencyId = $user?->effectiveAgencyId();
        if (!$user || !$agencyId) {
            abort(response()->json(['error' => 'No agency context.'], 403));
        }
        return [(int) $agencyId, (int) $user->id];
    }
}
