<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsProspect;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\PitchPathService;
use App\Services\RoadToSold\RoadToSoldException;
use App\Services\RoadToSold\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Road to Sold v3 - the pitch path from a row (road-to-sold.md 22.10, R8): the two endpoints the pop-up asks.
 *
 *   GET  road-to-sold/prospects/{prospect}/pitch-path  what the row's button does next: the composer when the owner is known (the property
 *                                                       is made from the MIC address first if there is none), otherwise Find owner.
 *                                                       JSON for the pop-up host, a plain redirect for a normal page - same answer.
 *   POST road-to-sold/prospects/{prospect}/no-owner    the "No owner found" button on the Find owner screen: records the outcome (22.7).
 *
 * Both are the signed-in person's OWN prospect only (an assistant works their agent's). Another agent's prospect is a 403, a prospect in
 * another agency is a 404 (the agency scope); a manager viewing an agent is read-only, so they get no pitch buttons and the route refuses.
 */
class PitchPathController extends Controller
{
    public function __construct(
        private readonly PitchPathService $path,
        private readonly OutcomeService $outcomes,
        private readonly OutcomeDefinitionService $definitions,
    ) {
    }

    private function own(Request $request, int $id): RtsProspect
    {
        $prospect = RtsProspect::query()->whereKey($id)->first();
        abort_if(! $prospect, 404);
        abort_unless((int) $prospect->user_id === (int) $request->user()->ownershipUserId(), 403, 'This prospect belongs to another agent. A manager sees it read-only.');

        return $prospect;
    }

    public function show(Request $request, int $prospect): JsonResponse|RedirectResponse
    {
        $row = $this->own($request, $prospect);
        $next = $this->path->next($row, $request->user(), $this->path->carry($request));

        return $request->expectsJson() ? response()->json($next) : redirect()->to($next['url']);
    }

    public function noOwner(Request $request, int $prospect)
    {
        $row = $this->own($request, $prospect);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $track = Track::fromRequest($request);

        $this->definitions->ensureDefaults((int) $row->agency_id, $track);
        $def = Def::query()->where('agency_id', $row->agency_id)->where('track', $track)->where('key', 'no_owner_found')->whereNull('deleted_at')->first();
        if (! $def) {
            return $this->answer($request, false, 'This agency has no "No owner found" outcome. Ask your administrator to switch it back on in the Recipe area.', $row);
        }

        try {
            $outcome = $this->outcomes->record($request->user(), (int) $row->tracked_property_id, (int) $def->id, [
                'note' => $data['note'] ?? null, 'prospecting_listing_id' => $row->prospecting_listing_id, 'property_id' => $row->property_id,
            ], $track);
        } catch (RoadToSoldException $e) {
            return $this->answer($request, false, $e->getMessage(), $row);
        }
        $message = 'Saved: ' . $outcome->outcome_label . '.' . ($outcome->next_action_at ? ' It comes back on ' . $outcome->next_action_at->format('j M Y') . '.' : '');

        return $this->answer($request, true, $message, $row);
    }

    /** JSON for the pop-up host's own calls; otherwise a small page (chrome-less inside the pop-up) that says what happened. */
    private function answer(Request $request, bool $ok, string $message, RtsProspect $row)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
        }

        return response()->view('road-to-sold.pitch-path-done', [
            'ok' => $ok, 'message' => $message, 'prospect' => $row, 'back' => route('road-to-sold.index'),
        ], $ok ? 200 : 422);
    }
}
