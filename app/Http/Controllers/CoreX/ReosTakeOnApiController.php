<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\EnforcesRecordVisibility;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Services\Rentals\TakeOnImport\Reos\ReosAgentMatcher;
use App\Services\Rentals\TakeOnImport\Reos\ReosDecisionService;
use App\Services\Rentals\TakeOnImport\Reos\ReosRowQuery;
use App\Services\Rentals\TakeOnImport\Reos\ReosRunSummary;
use App\Services\Rentals\TakeOnImport\Reos\ReosSuburbLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * AT-479 — the reOS review screen's saves. /api/v1/rentals/take-on-import/* (non-negotiable #7: named, in the Admin → API
 * catalogue). Every call is behind `rentals_take_on_import.manage` AND re-checks the parent run's own/branch/agency
 * visibility (404 otherwise) — a row id from another agency or another colleague's batch reaches nothing.
 */
class ReosTakeOnApiController extends Controller
{
    use EnforcesRecordVisibility;

    public function summary(Request $request, RentalTakeOnImportRun $run): JsonResponse
    {
        $this->guardRun($request, $run);

        return response()->json(['summary' => ReosRunSummary::for($run)]);
    }

    /** One row's choices (tick, create-or-not, suburb, link a contact, agent, wallet). Returns the row re-drawn + the header numbers. */
    public function decisions(Request $request, RentalTakeOnImportRow $row, ReosDecisionService $service): JsonResponse
    {
        $run = $row->run()->withTrashed()->firstOrFail();
        $this->guardRun($request, $run);

        $input = $request->only(['selected', 'property_create', 'suburb_id', 'owner', 'tenant', 'agent_user_id', 'tenant_wallet_ref']);

        try {
            $row = $service->apply($row, $run, $input);
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        return $this->rowResponse($run, $row);
    }

    /** Whole-batch actions: tick all ready / tick all / untick all / apply suburb / apply agent to the ticked rows. */
    public function bulk(Request $request, RentalTakeOnImportRun $run, ReosDecisionService $service): JsonResponse
    {
        $this->guardRun($request, $run);

        $data = $request->validate([
            'action' => 'required|string|in:tick_ready,tick_all,untick_all,apply_suburb,apply_agent',
            'suburb_id' => 'nullable|integer',
            'agent_user_id' => 'nullable|integer',
        ]);

        try {
            $changed = $service->bulk($run, $data['action'], ReosRowQuery::only((array) $request->input('filters', [])), $data);
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        return response()->json(['changed' => $changed, 'summary' => ReosRunSummary::for($run)]);
    }

    public function suburbs(Request $request): JsonResponse
    {
        return response()->json(['data' => ReosSuburbLookup::search((string) $request->query('q', ''))]);
    }

    /** Link an owner / tenant to an existing contact: the whole agency's contacts (the same reach as every other link picker). */
    public function contacts(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }
        $agencyId = (int) $request->user()->effectiveAgencyId();

        $found = Contact::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNull('purged_at')
            ->search($q)
            ->limit(10)->get();

        return response()->json(['data' => $found->map(fn (Contact $c) => [
            'id' => (int) $c->id,
            'label' => $c->full_name !== '' ? $c->full_name : '(no name)',
            'detail' => trim(implode(' · ', array_filter([$c->phone, $c->email]))),
        ])->values()]);
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────

    private function guardRun(Request $request, RentalTakeOnImportRun $run): void
    {
        abort_unless($run->isReos(), 404);
        $this->assertVisible($request, $run);
        // An owner-role account has AgencyScope lifted; a batch is only ever reached from the agency being worked in.
        abort_unless((int) $run->agency_id === (int) $request->user()->effectiveAgencyId(), 404);
    }

    private function rowResponse(RentalTakeOnImportRun $run, RentalTakeOnImportRow $row): JsonResponse
    {
        return response()->json([
            'row_html' => view('corex.rentals.take-on-import._reos-row', [
                'row' => $row->fresh(),
                'run' => $run,
                'agents' => app(ReosAgentMatcher::class)->users((int) $run->agency_id),
                'canManage' => true,
            ])->render(),
            'summary' => ReosRunSummary::for($run),
        ]);
    }
}
