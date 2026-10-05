<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\PpraInspectionPack;
use App\Services\Compliance\PpraSamplePickerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * PPRA Inspection Pack Phase F — .ai/specs/ppra-inspection-pack.md §6.8a.
 * Shared sample-picker backend, reused by items k/l/m (Phases G/H/I). No
 * standalone checklist row of its own — see the preview harness route
 * (index()) added purely so this phase's own deliverable is verifiable in
 * a browser ahead of G/H/I wiring the real checklist rows to it.
 */
class PpraSamplePickerController extends Controller
{
    public function __construct(private PpraSamplePickerService $picker = new PpraSamplePickerService())
    {
    }

    /** Phase F verification harness — not a spec-required screen. Removed/absorbed once G/H/I wire real rows. */
    public function preview(Request $request)
    {
        $agency = $this->resolveAgency($request);

        return view('admin.ppra-inspection-pack.sample-picker-preview', compact('agency'));
    }

    public function search(Request $request, string $mode): JsonResponse
    {
        abort_unless(in_array($mode, PpraSamplePickerService::MODES, true), 422, 'Invalid sample-picker mode.');

        $agency = $this->resolveAgency($request);

        $filters = $request->only(['search', 'date_from', 'date_to', 'status', 'agent_id']);
        $page = (int) $request->input('page', 1);

        $results = $this->picker->search($agency, $mode, $filters, max($page, 1));

        // Read-only: a GET must not create a draft pack as a side effect.
        $draft = PpraInspectionPack::currentDraftFor($agency);
        $column = PpraInspectionPack::sampleColumnForMode($mode);

        return response()->json([
            'data'         => $results->items(),
            'current_page' => $results->currentPage(),
            'last_page'    => $results->lastPage(),
            'total'        => $results->total(),
            'sample_size'  => $this->picker->sampleSizeFor($agency, $mode),
            'selected_ids' => $draft?->{$column} ?? [],
        ]);
    }

    public function mostRecent(Request $request, string $mode): JsonResponse
    {
        abort_unless(in_array($mode, PpraSamplePickerService::MODES, true), 422, 'Invalid sample-picker mode.');

        $agency = $this->resolveAgency($request);
        $n = $this->picker->sampleSizeFor($agency, $mode);

        return response()->json(['ids' => $this->picker->mostRecentIds($agency, $mode, $n)]);
    }

    /** Persist the confirmed selection onto the agency's current draft pack (§6.8a "Persistence"). */
    public function store(Request $request, string $mode): JsonResponse
    {
        abort_unless(in_array($mode, PpraSamplePickerService::MODES, true), 422, 'Invalid sample-picker mode.');

        $agency = $this->resolveAgency($request);
        $user = $request->user();

        $validated = $request->validate([
            'ids'   => 'present|array',
            'ids.*' => 'integer',
        ]);

        // Every id must belong to the effective agency (tenant isolation).
        $ids = array_values(array_unique(array_map('intval', $validated['ids'])));
        $model = match ($mode) {
            'deal'    => \App\Models\Deal::class,
            'rental'  => \App\Models\Lease::class,
            'listing' => \App\Models\Property::class,
        };
        if ($ids !== []) {
            $owned = $model::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('agency_id', $agency->id)->whereIn('id', $ids)->count();
            abort_if($owned !== count($ids), 422, 'One or more selected items do not belong to this agency.');
        }
        $validated['ids'] = $ids;

        $sampleSize = $this->picker->sampleSizeFor($agency, $mode);
        abort_if(count($validated['ids']) > $sampleSize, 422, "Selection exceeds the agency's configured sample size of {$sampleSize}.");

        $draft = PpraInspectionPack::findOrCreateDraftFor($agency, $user);
        $column = PpraInspectionPack::sampleColumnForMode($mode);

        $draft->update([$column => array_values($validated['ids'])]);

        return response()->json([
            'saved'        => true,
            'pack_id'      => $draft->id,
            'selected_ids' => $draft->{$column},
        ]);
    }

    private function resolveAgency(Request $request): Agency
    {
        $user = $request->user() ?? Auth::user();
        $agency = Agency::find($user->effectiveAgencyId());
        abort_unless($agency, 403, 'No agency context.');

        return $agency;
    }
}
