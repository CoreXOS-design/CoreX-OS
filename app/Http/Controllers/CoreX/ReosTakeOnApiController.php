<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\EnforcesRecordVisibility;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Services\Rentals\TakeOnImport\Reos\ReosAgentMatcher;
use App\Services\Rentals\TakeOnImport\Reos\ReosDecisionService;
use App\Services\Rentals\TakeOnImport\Reos\ReosQuestions;
use App\Services\Rentals\TakeOnImport\Reos\ReosRunDefaults;
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

    /** One lease's answers (property, person, suburb, agent, wallet, retry). Returns the lease re-drawn (its line) and the header numbers. */
    public function decisions(Request $request, RentalTakeOnImportRow $row, ReosDecisionService $service): JsonResponse
    {
        $run = $row->run()->withTrashed()->firstOrFail();
        $this->guardRun($request, $run);

        $input = $request->only(['property_choice', 'person', 'suburb_id', 'owner', 'tenant', 'confirm', 'agent_user_id', 'tenant_wallet_ref', 'retry']);

        try {
            $row = $service->apply($row, $run, $input);
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        return $this->rowResponse($run, $row);
    }

    /** The agent for all leases and the suburb for all new properties: saved on the batch and given to every lease that has none of its own. */
    public function defaults(Request $request, RentalTakeOnImportRun $run, ReosDecisionService $service): JsonResponse
    {
        $this->guardRun($request, $run);

        $request->validate(['agent_user_id' => 'nullable|integer', 'suburb_id' => 'nullable|integer']);

        try {
            $changed = $service->setDefaults($run, $request->only(['agent_user_id', 'suburb_id']));
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first()], 422);
        }

        return response()->json(['changed' => $changed, 'summary' => ReosRunSummary::for($run), 'defaults' => ReosRunDefaults::get($run->fresh())]);
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
        return response()->json($this->parts($run, $row->fresh()) + ['summary' => ReosRunSummary::for($run)]);
    }

    /** @return array{state:string, line_html:string} */
    private function parts(RentalTakeOnImportRun $run, RentalTakeOnImportRow $row): array
    {
        return [
            'state' => ReosQuestions::state($row),
            'line_html' => view('corex.rentals.take-on-import._reos-line', [
                'row' => $row, 'run' => $run, 'canManage' => true,
                'agents' => app(ReosAgentMatcher::class)->users((int) $run->agency_id),
                'defaults' => ReosRunDefaults::get($run),
            ])->render(),
        ];
    }
}
