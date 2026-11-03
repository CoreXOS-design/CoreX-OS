<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsOutcomeDefinition as Def;
use App\Models\RoadToSold\RtsProspect;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\OutcomeService;
use App\Services\RoadToSold\PitchPathService;
use App\Services\RoadToSold\ProspectStages;
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

    /**
     * Road to Sold v4 (road-to-sold.md 23.8): the Presentation step. The property is made from the Market Intelligence address first when there is none (as the
     * pitch path does), then the agent lands on that property with the Generate Presentation window already open. This replaces the old link to the
     * presentations list, which dropped the agent on a page that said "open a property" with no property.
     */
    public function presentation(Request $request, int $prospect): RedirectResponse|\Illuminate\Http\Response
    {
        $row = $this->own($request, $prospect);
        // never create on a click (23.25.2): a record with no property file says so and offers the one button that makes it
        if (! $row->property_id || ! \App\Models\Property::query()->withoutGlobalScopes()->whereNull('deleted_at')->find((int) $row->property_id)) {
            return response()->view('road-to-sold.pitch-create-property', ['prospect' => $row, 'address' => $this->addressOf($row), 'carry' => $this->path->carry($request) + ['embed' => 1]]);
        }
        $property = $this->path->ensureProperty($row, $request->user());
        if (! $property) {
            return redirect()->to($this->path->next($row, $request->user(), $this->path->carry($request))['url'])
                ->with('error', 'Add the property address first - there is nothing to prepare a presentation about yet.');
        }

        return redirect()->to(route('corex.properties.show', $property->id) . '?' . http_build_query($this->path->carry($request) + ['generate_presentation' => 1, 'rts_prospect' => $row->id]));
    }

    /** The one button of the step-6 screen for a record with no property file: it makes the file (as the other steps do) and goes on to the Generate window. */
    public function createProperty(Request $request, int $prospect): RedirectResponse
    {
        $row = $this->own($request, $prospect);
        $property = $this->path->ensureProperty($row, $request->user());
        if (! $property) {
            return redirect()->to(route('road-to-sold.prospects.presentation-path', ['prospect' => $row->id] + $this->path->carry($request)))
                ->with('error', 'The property file could not be made - the address is not captured yet.');
        }

        return redirect()->to(route('road-to-sold.prospects.presentation-path', ['prospect' => $row->id] + $this->path->carry($request)));
    }

    /**
     * Road to Sold 23.25 step 7 (Presentation results): how the appointment went. While the appointment has not been answered it is the calendar's own feedback form
     * on that appointment (held / no-show / moved); once it was held it is the Result form (23.9). The same two screens the row buttons already open - one hop, decided
     * from the record's station when the agent clicks, so the pill never goes stale.
     */
    public function results(Request $request, int $prospect): RedirectResponse
    {
        $row = $this->own($request, $prospect);
        $carry = $this->path->carry($request) + ['embed' => 1];
        $held = ProspectStages::order((string) $row->stage) >= ProspectStages::order(ProspectStages::EVALUATION_DONE);
        $eventId = $held ? null : app(\App\Services\RoadToSold\BeltLinks::class)->latestEvaluationEvent((int) $row->id);
        if ($eventId) {
            return redirect()->to(route('command-center.calendar', $carry + ['rts_prospect' => $row->id, 'rts_stage' => $row->stage, 'capture_feedback' => $eventId]));
        }

        return redirect()->to(route('road-to-sold.prospects.result-form', ['prospect' => $row->id] + $carry));
    }

    /** The Result form (23.9) as a screen of the popup: the agency's own result list, posting to the one result route. */
    public function resultForm(Request $request, int $prospect)
    {
        $row = $this->own($request, $prospect);
        // 23.25 step 7: this form keeps only the positive answers (Won, Undecided / revisit); every "lost / not selling" answer is the popup's own "Stops here" control, one list.
        // The route still accepts every old value.
        $options = collect(app(\App\Services\RoadToSold\ResultOptionService::class)->list((int) $row->agency_id, (string) $row->track))
            ->filter(fn ($o) => in_array($o['class'] ?? '', ['won', 'pending'], true))
            ->map(fn ($o) => ['key' => $o['key'], 'label' => $o['label'], 'needs_date' => (bool) ($o['class'] !== 'won'), 'days' => $o['revisit_days'] ?? null])->values()->all();

        return view('road-to-sold.pitch-result', ['prospect' => $row, 'options' => $options, 'address' => $this->addressOf($row)]);
    }

    /**
     * Road to Sold 23.25 steps 8 and 9 (Draft, Active): the property's own gates, read from the property exactly as the belt reads them (BlockerService, the readiness
     * service, the two Active events), with the existing actions beside them. Draft: the checklist and "Move to Draft" (the property page's own move, Prospecting -> Draft).
     * Active: the compliance gate and the syndication gate, and the existing publish. Nothing is decided here; the record moves when the property does (BeltLinks).
     */
    public function propertyStep(Request $request, int $prospect, string $step)
    {
        abort_unless(in_array($step, ['draft', 'active'], true), 404);
        $row = $this->own($request, $prospect);
        $property = $row->property_id ? \App\Models\Property::query()->where('agency_id', $row->agency_id)->whereNull('deleted_at')->find((int) $row->property_id) : null;
        abort_unless($property, 404, 'This record has no property yet.');
        $report = app(\App\Services\Compliance\MarketingReadinessService::class)->statusFor($property);
        $portal = fn (?string $s) => strtolower(trim((string) $s));
        $syndication = [
            ['label' => 'Property24', 'status' => $portal($property->p24_syndication_status)],
            ['label' => 'Private Property', 'status' => $portal($property->pp_syndication_status)],
        ];

        return view('road-to-sold.pitch-property-step', [
            'prospect' => $row, 'property' => $property, 'step' => $step, 'address' => $this->addressOf($row, $property),
            'report' => $report, 'syndication' => $syndication,
            'complianceDone' => $property->compliance_snapshot_at !== null, 'published' => $property->published_at !== null,
            'isProspecting' => method_exists($property, 'isProspecting') && $property->isProspecting(), 'isDraft' => (string) $property->status === 'draft',
            'carry' => $this->path->carry($request) + ['embed' => 1],
        ]);
    }

    private function addressOf(RtsProspect $row, ?\App\Models\Property $property = null): string
    {
        $property ??= $row->property_id ? \App\Models\Property::query()->withoutGlobalScopes()->find((int) $row->property_id) : null;
        $tp = \App\Models\Prospecting\TrackedProperty::query()->withoutGlobalScopes()->find((int) $row->tracked_property_id);

        return trim((string) ($property?->address ?: $tp?->displayAddress())) ?: 'This property';
    }

    /**
     * Road to Sold v4 drop 4 (road-to-sold.md 23.23): the Book evaluation step. The property is made from the Market Intelligence address first when there is none
     * (as the other steps do), then the agent lands on the calendar's own evaluation form with the property, the owner as the seller and themselves as the
     * creator prefilled. Nobody found yet: the pitch path's Find owner, with the reason.
     */
    public function evaluation(Request $request, int $prospect): RedirectResponse
    {
        $row = $this->own($request, $prospect);
        $carry = $this->path->carry($request);
        $property = $this->path->ensureProperty($row, $request->user());
        $ownerId = (int) \App\Models\Prospecting\TrackedProperty::query()->withoutGlobalScopes()->whereKey($row->tracked_property_id)->value('owner_contact_id');
        $owner = $ownerId ? \App\Models\Contact::query()->withoutGlobalScopes()->where('agency_id', $row->agency_id)->whereNull('deleted_at')->find($ownerId) : null;
        if (! $property || ! $owner) {
            return redirect()->to($this->path->next($row, $request->user(), $carry)['url'])
                ->with('error', 'Find the owner first - an evaluation needs the property and the person who owns it.');
        }

        return redirect()->to(app(\App\Services\SellerOutreach\PitchBookService::class)->url($property, $owner, $carry + ['rts_prospect' => (string) $row->id]));
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
