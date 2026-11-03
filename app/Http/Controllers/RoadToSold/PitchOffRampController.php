<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\Prospecting\TrackedProperty;
use App\Models\ProspectingListing;
use App\Models\RoadToSold\RtsProspect;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\ResultService;
use App\Services\SellerOutreach\PitchBeltResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Road to Sold v4 (road-to-sold.md 23.25 / 23.11, Johan 3 Nov): "Stops here (say why)" on EVERY step of the pitch popup - one control, the same everywhere. Two entry points
 * (a Road to Sold row, MIC Pitch now) reach the same record, so both go through here. The reasons are the agency's own list for the step (ParkReasonService::forStep); the
 * one result service does the leaving: out of everyone's daily lists, the claim closed, the property marked Not selling (or lost, as the reason counts), counted at the station
 * the record was at. Only the agent's OWN open record can be stopped.
 */
class PitchOffRampController extends Controller
{
    public function __construct(private readonly ParkReasonService $reasons, private readonly ResultService $results, private readonly PitchBeltResolver $belt)
    {
    }

    /** The agent's own open record the popup is working on: the record itself, else the open record on the advert's / tracked file. */
    private function recordOf(Request $request): ?RtsProspect
    {
        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return null;
        }
        $mine = (int) $user->ownershipUserId();
        $base = fn () => RtsProspect::withoutGlobalScopes()->where('agency_id', $agencyId)->where('user_id', $mine)->whereNull('deleted_at')->whereNull('closed_at')->whereNull('released_at');
        if ((int) $request->input('prospect') > 0) {
            return $base()->find((int) $request->input('prospect'));
        }
        $tpId = (int) $request->input('tracked') ?: null;
        if (! $tpId && (int) $request->input('listing') > 0) {
            $tpId = (int) ProspectingListing::withoutGlobalScopes()->where('agency_id', $agencyId)->whereKey((int) $request->input('listing'))->value('tracked_property_id') ?: null;
        }

        return $tpId ? $this->belt->openProspectFor($user, $tpId) : null;
    }

    private function step(Request $request): int
    {
        return max(1, min(9, (int) $request->input('step', 1)));
    }

    public function choices(Request $request): JsonResponse
    {
        $step = $this->step($request);
        // Belt v2: the Swap on a Today's work row asks for the step 1 reasons of a LIST ITEM (its record may not exist yet - the swap places it)
        if ((int) $request->query('item') > 0) {
            $user = $request->user();
            $row = \App\Models\RoadToSold\RtsListItem::query()->visibleTo($user)->where('user_id', (int) $user->id)->find((int) $request->query('item'));
            if (! $row) {
                return response()->json(['record' => null, 'step' => 1, 'message' => 'That property is not on your list.', 'reasons' => []]);
            }

            return response()->json(['record' => ['id' => (int) $row->id, 'address' => null, 'stopped' => false], 'step' => 1, 'message' => null,
                'reasons' => $this->reasons->forStep((int) ($user->effectiveAgencyId() ?: 0), (string) ($row->track ?: 'sales'), 1)->map(fn ($r) => ['key' => $r->key, 'label' => $r->label, 'other' => (bool) $r->is_other])->values()->all()]);
        }
        $p = $this->recordOf($request);
        if (! $p) {
            return response()->json(['record' => null, 'step' => $step, 'message' => 'This property is not on your Road to Sold list yet - claim it first, then you can say why it stops here.', 'reasons' => []]);
        }
        $tp = TrackedProperty::query()->withoutGlobalScopes()->whereKey($p->tracked_property_id)->first();

        return response()->json([
            'record' => ['id' => (int) $p->id, 'address' => $tp ? ($tp->displayAddress() ?: null) : null, 'stopped' => $p->parked_at !== null],
            'step' => $step,
            'reasons' => $this->reasons->forStep((int) $p->agency_id, (string) $p->track, $step)->map(fn ($r) => ['key' => $r->key, 'label' => $r->label, 'other' => (bool) $r->is_other])->values()->all(),
            'message' => $p->parked_at !== null ? 'This property has already been stopped (set aside).' : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:255'], 'step' => ['nullable', 'integer', 'min:1', 'max:9']],
            ['reason.required' => 'Pick why it stops here.']);
        $p = $this->recordOf($request);
        if (! $p) {
            return response()->json(['ok' => false, 'message' => 'This property is not on your Road to Sold list, so there is nothing to stop.'], 422);
        }
        if ($p->parked_at !== null) {
            return response()->json(['ok' => false, 'message' => 'This property has already been stopped (set aside).'], 422);
        }
        $r = $this->results->offRamp($p, $data['reason'], $data['note'] ?? null, $request->user(), 'pitch_popup', $this->step($request));
        if ($r['ok']) {
            $request->session()->flash('status', $r['message']);
        }

        return response()->json(['ok' => $r['ok'], 'message' => $r['message']], $r['ok'] ? 200 : 422);
    }
}
