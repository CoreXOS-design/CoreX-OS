<?php

declare(strict_types=1);

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationOutcome;
use App\Services\Presentations\OutcomeLockedException;
use App\Services\Presentations\PresentationOutcomeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 8 — record / update / view outcomes on a single presentation.
 *
 * Dashboard (index across all presentations) lives in
 * PresentationOutcomesDashboardController.
 */
final class PresentationOutcomeController extends Controller
{
    public function __construct(private readonly PresentationOutcomeService $svc) {}

    /** POST /presentations/{presentation}/outcome */
    public function record(Request $request, Presentation $presentation): RedirectResponse
    {
        $this->guardAgency($request, $presentation);

        $data = $this->validateInput($request);

        // v4 (road-to-sold.md 23.9 / 23.10): the panel answers from the ONE result list. When the presentation is on a belt record, the one result service
        // sets the record, the claim and the property together and writes this outcome through the same service; otherwise this is the outcome alone.
        if (! empty($data['result_key'])) {
            $data['outcome'] = \App\Services\RoadToSold\ResultOptionService::enumForKey($data['result_key']);
            $prospect = $this->beltRecordOf($request, $presentation);
            if ($prospect) {
                $r = app(\App\Services\RoadToSold\ResultService::class)->record(
                    $prospect, $data['result_key'], null, $data['notes'] ?? null, $request->user(), 'presentation_outcome',
                    ['presentation' => $presentation, 'presentation_data' => array_diff_key($data, ['result_key' => 1])]
                );
                if (! $r['ok']) {
                    return back()->withErrors(['outcome' => $r['message']])->withInput();
                }

                return redirect()->route('presentations.show', $presentation->id)->with('status', 'Outcome recorded. ' . $r['message']);
            }
        }

        try {
            $outcomeRow = $this->svc->record($presentation, $data, $request->user());
            if (! empty($data['result_key']) && \Illuminate\Support\Facades\Schema::hasColumn('presentation_outcomes', 'result_key')) {
                \Illuminate\Support\Facades\DB::table('presentation_outcomes')->where('id', $outcomeRow->id)->update(['result_key' => $data['result_key']]);
            }
        } catch (OutcomeLockedException $e) {
            return back()->withErrors(['outcome' => $e->getMessage()])->withInput();
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['outcome' => $e->getMessage()])->withInput();
        }

        return redirect()->route('presentations.show', $presentation->id)
            ->with('status', 'Outcome recorded.');
    }

    /** PATCH /presentations/{presentation}/outcome */
    public function update(Request $request, Presentation $presentation): RedirectResponse
    {
        return $this->record($request, $presentation);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'outcome' => ['required_without:result_key', 'nullable', Rule::in(PresentationOutcome::ALL_OUTCOMES)],
            'result_key' => ['nullable', 'string', 'max:40'],
            'cancellation_reason' => ['nullable', Rule::in(PresentationOutcome::ALL_CANCELLATION_REASONS)],
            'cancellation_competitor_agency' => 'nullable|string|max:200',
            'cancellation_competitor_price'  => 'nullable|integer|min:0|max:1000000000',
            'decision_at'         => 'nullable|date|before_or_equal:today',
            'notes'               => 'nullable|string|max:5000',
            'resulted_in_deal_id' => 'nullable|integer|exists:deals,id',
        ]);
    }

    /** The belt record this presentation is on: its own link, else the viewing agent's single open record for the property. */
    private function beltRecordOf(Request $request, Presentation $presentation): ?\App\Models\RoadToSold\RtsProspect
    {
        $links = app(\App\Services\RoadToSold\BeltLinks::class);
        $agencyId = (int) $presentation->agency_id;
        if (\App\Services\RoadToSold\BeltLinks::presentationColumns() && ! empty($presentation->rts_prospect_id)) {
            $p = \App\Models\RoadToSold\RtsProspect::withoutGlobalScopes()->find((int) $presentation->rts_prospect_id);
        } else {
            $tracked = $links->trackedOf($presentation->property_id ? (int) $presentation->property_id : null, $agencyId);
            $p = $tracked ? $links->openProspect($agencyId, (int) $request->user()->ownershipUserId(), $tracked) : null;
        }

        return ($p && $p->closed_at === null && $p->released_at === null) ? $p : null;
    }

    private function guardAgency(Request $request, Presentation $presentation): void
    {
        $effective = $request->user()?->effectiveAgencyId();
        if (!$effective || (int) $presentation->agency_id !== (int) $effective) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
