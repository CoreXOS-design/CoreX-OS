<?php

declare(strict_types=1);

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\AgentOverride;
use App\Services\Presentations\CompetitorStockMatchService;
use App\Models\HoldingCostDataPoint;
use App\Services\Presentations\HoldingCostEstimator;
use App\Support\Presentations\SuburbMatcher;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Models\PropertySettingItem;
use App\Services\Presentations\AnalysisDataService;
use App\Services\Presentations\ConditionAdjustmentService;
use App\Support\Presentations\CompLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Build 2 — agent's pre-flight review screen.
 *
 * Routes:
 *   GET  /corex/presentations/{version}/review                 → show
 *   POST /corex/presentations/{version}/review/comps/{comp}    → toggleComp
 *   POST /corex/presentations/{version}/publish                → publish
 *   POST /corex/presentations/{version}/revert                 → revert
 *
 * Status flow:
 *   compile → awaiting_review → published
 *                             → archived (revert)
 *
 * Concurrent reviewer guard:
 *   reviewer_user_id + reviewer_locked_at; window of REVIEWER_LOCK_MINUTES.
 *   A second agent gets a banner; if they confirm takeover, the original
 *   lock is overwritten and a 'review_takeover' override is logged.
 */
final class PresentationReviewController extends Controller
{
    /** Render the review screen. */
    public function show(Request $request, PresentationVersion $version)
    {
        $this->authoriseReviewer($request, $version);

        // AT-27 C1a — if the source property changed since the comps were
        // hydrated, re-hydrate + reset curation, then reload so the refreshed
        // set + the "comparable set refreshed" banner render this request.
        if (app(\App\Services\Presentations\PresentationCompFreshnessService::class)
                ->refreshIfStale($version->presentation)) {
            return redirect()->route('presentations.review.show', $version->id);
        }

        // Build 2 robustness — re-validate the comp set against
        // soft-deleted rows in case anything was archived between
        // compile and review. Excluded comps are auto-logged with
        // override_type='comp_unavailable' so the audit captures the
        // implicit drop. The "should-be included" list is recomputed
        // from the version snapshot every render so it self-heals.
        $unavailableLogged = $this->reconcileSoftDeletedComps($request, $version);

        // Concurrent-reviewer detection. Lock is per-agent; a second
        // agent sees the banner and can take over (separate POST).
        $currentReviewer = $version->reviewerUser;
        $isLockedByOther = $version->reviewer_user_id
            && $version->reviewer_user_id !== $request->user()->id
            && $version->isReviewerLockActive();

        // Take the lock for this agent if no other live lock exists.
        if (!$isLockedByOther) {
            $version->forceFill([
                'reviewer_user_id'    => $request->user()->id,
                'reviewer_locked_at'  => now(),
            ])->save();
        }

        // Hydrate the data the Blade renders. Compiler already stored a
        // data_snapshot_json on the version; for the review screen we
        // need the LIVE comp list (with the included-set applied) and
        // a slim subject dict.
        $presentation = $version->presentation()->with('property')->first();
        $allComps     = PresentationSoldComp::query()
            ->where('presentation_id', $version->presentation_id)
            ->whereNull('deleted_at')
            ->orderByDesc('sold_date')
            ->get();

        $includedIds = $version->included_comp_ids_json
            ?: $allComps->pluck('id')->all();

        // Keystone — title_type now lives on properties.title_type,
        // derived from property_type by TitleTypeClassifier on every save.
        // Read the column first; fall back to the classifier (which
        // re-derives + tries category) only when the column is NULL,
        // covering rows pre-dating the backfill.
        $classifier = app(\App\Services\TitleTypeClassifier::class);
        $subjectTitleType = $presentation->property?->title_type
            ?? ($presentation->property ? $classifier->forProperty($presentation->property) : null);

        // AT-22 — subject coords so each comp carries a distance (the toolkit
        // sorts by distance, and the browse-beyond panel seeds off it).
        $subjLat = $presentation->property?->latitude  ?? $presentation->property?->cma_gps_lat;
        $subjLng = $presentation->property?->longitude ?? $presentation->property?->cma_gps_lng;

        $compRows = $allComps->map(function ($c) use ($includedIds, $subjectTitleType, $classifier, $subjLat, $subjLng) {
            $raw = is_string($c->raw_row_json)
                ? (json_decode($c->raw_row_json, true) ?: [])
                : ((array) $c->raw_row_json ?: []);
            // Preserve Build 1 strict-drop semantic on blank comp type.
            $compTitleType = $classifier->fromPropertyType($c->property_type)
                ?? \App\Services\TitleTypeClassifier::TITLE_OTHER;
            // Build 7 — mirror MicSnapshotHydrator's same-subject-report
            // exemption to the review-screen cross-flag. Comps from the
            // subject's own analyst-vetted market report are flagged
            // `subject_match_used` in raw_row_json by encodeRaw (L472)
            // at hydration time. Trust the analyst's grouping and do not
            // visually mark them as cross-type.
            $subjectMatchUsed = (bool) ($raw['subject_match_used'] ?? false);
            return [
                'id'              => $c->id,
                // Route through CompLabel — same never-blank 5-step
                // fallback the PDF and analysis tab use. Sectional comps
                // with scheme_name + section_number (and no street
                // address) used to render as "—" here while displaying
                // correctly elsewhere; this collapses the three consumers
                // onto one source of truth.
                'address'         => CompLabel::build($raw, $c->suburb ?? null, $c->id ?? null),
                'sale_date'       => optional($c->sold_date)->format('Y-m-d'),
                'sold_price_inc'  => $c->sold_price_inc,
                'property_type'   => $c->property_type,
                'size_m2'         => $c->size_m2,
                'r_per_m2'        => ($c->size_m2 && $c->sold_price_inc)
                    ? (int) round($c->sold_price_inc / $c->size_m2) : null,
                'lat'             => $raw['latitude'] ?? null,
                'lng'             => $raw['longitude'] ?? null,
                'distance_m'      => ($subjLat !== null && $subjLng !== null
                    && isset($raw['latitude'], $raw['longitude']) && $raw['latitude'] !== null && $raw['longitude'] !== null)
                    ? (int) round(\App\Support\MarketAnalytics\HaversineDistance::distanceMetres(
                        (float) $subjLat, (float) $subjLng, (float) $raw['latitude'], (float) $raw['longitude']))
                    : null,
                'title_type'      => $compTitleType,
                'is_included'     => in_array($c->id, $includedIds, true),
                'is_cross_type'   => !$subjectMatchUsed
                    && $subjectTitleType !== null
                    && $subjectTitleType !== $compTitleType,
            ];
        })->all();

        // Build 3 — condition picker data. We surface ALL the agency's
        // active condition levels in the dropdown, plus the current
        // resolution (override / property / none).
        $conditionLevels = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $version->agency_id)
            ->where('group', PropertySettingItem::GROUP_CONDITION_LEVEL)
            ->where('active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'adjustment_pct']);

        $resolver        = app(ConditionAdjustmentService::class);
        $resolved        = $resolver->resolveLive($version, $presentation);
        $currentCondId   = $resolved['level']?->id;
        $currentCondPct  = $resolved['level'] ? (float) $resolved['level']->adjustment_pct : null;
        $currentCondName = $resolved['level']?->name;

        // Live compile of the CMA bands so the review screen renders the
        // condition-adjusted Middle in-place (no extra round-trip on
        // first paint).
        $analysis        = (new AnalysisDataService())->compile($presentation, $version);
        $cmaValue        = $analysis['cma_valuation']    ?? [];
        $competitorStock = $analysis['competitor_stock'] ?? ['matches' => [], 'included_ids' => null, 'visible' => []];

        // Build 4 — section toggle state for Section 3 of the review.
        $sectionsCatalogue = PresentationVersion::SECTIONS_CATALOGUE;
        $sectionFloor      = PresentationVersion::SECTION_FLOOR;
        $sectionDeps       = PresentationVersion::SECTION_DEPENDENCIES;
        $sectionSnapshot   = [];
        foreach ($sectionsCatalogue as $sKey => $_label) {
            $sectionSnapshot[$sKey] = $version->isSectionEnabled($sKey);
        }
        $pageEstimate = $version->estimatedPageCount();

        return view('presentations.review', [
            'version'              => $version,
            'presentation'         => $presentation,
            'compRows'             => $compRows,
            'subjectTitleType'     => $subjectTitleType,
            'isLockedByOther'      => $isLockedByOther,
            'currentReviewer'      => $currentReviewer,
            'unavailableLogged'    => $unavailableLogged,
            // Build 3 — condition picker + initial valuation.
            'conditionLevels'      => $conditionLevels,
            'currentConditionId'   => $currentCondId,
            'currentConditionPct'  => $currentCondPct,
            'currentConditionName' => $currentCondName,
            'currentConditionSrc'  => $resolved['source'],
            'cmaValuation'         => $cmaValue,
            // Competitor Stock — scored Active Competition cards.
            'competitorStock'      => $competitorStock,
            // Build 4 — section toggles.
            'sectionsCatalogue'    => $sectionsCatalogue,
            'sectionFloor'         => $sectionFloor,
            'sectionDeps'          => $sectionDeps,
            'sectionSnapshot'      => $sectionSnapshot,
            'pageEstimate'         => $pageEstimate,
        ]);
    }

    /**
     * Build 4 — toggle a report section on/off, enforcing dependencies.
     *
     * Behaviour:
     *   - applySectionToggle() on the version mutates enabled_sections_json
     *     and returns any cascaded sections (Pricing Strategy follows CMA).
     *   - Floor sections coerce to ON regardless of POST payload.
     *   - Every flip (triggering + cascaded) writes an agent_overrides
     *     row so the audit log captures both.
     *   - Idempotent: a no-op POST writes no override.
     *
     * Returns the updated section map + cascaded diff + new estimated
     * page count so the JS can update the checkboxes, the "Estimated
     * pages" hint, and surface a toast for any forced cascade.
     */
    public function toggleSection(Request $request, PresentationVersion $version): JsonResponse
    {
        $this->authoriseReviewer($request, $version);

        $request->validate([
            'section_key' => 'required|string|in:' . implode(',', array_keys(PresentationVersion::SECTIONS_CATALOGUE)),
            'enabled'     => 'required|boolean',
        ]);

        $key      = (string) $request->input('section_key');
        $enabled  = $request->boolean('enabled');

        // Floor sections silently coerce — UI shows them locked, but a
        // crafted POST that tries to flip them off lands here and we
        // refuse to record the spurious change.
        if (in_array($key, PresentationVersion::SECTION_FLOOR, true) && !$enabled) {
            return response()->json([
                'ok'           => true,
                'no_op'        => true,
                'reason'       => 'floor_section',
                'snapshot'     => $version->enabled_sections_json ?? [],
                'cascaded'     => [],
                'page_estimate'=> $version->estimatedPageCount(),
            ]);
        }

        $prevValue = $version->isSectionEnabled($key);
        if ($prevValue === $enabled) {
            return response()->json([
                'ok'            => true,
                'no_op'         => true,
                'snapshot'      => $version->enabled_sections_json ?? [],
                'cascaded'      => [],
                'page_estimate' => $version->estimatedPageCount(),
            ]);
        }

        $result = DB::transaction(function () use ($version, $key, $enabled, $prevValue, $request) {
            $applied = $version->applySectionToggle($key, $enabled);

            // Log the triggering toggle itself.
            AgentOverride::create([
                'agency_id'               => $version->agency_id,
                'presentation_version_id' => $version->id,
                'user_id'                 => $request->user()->id,
                'override_type'           => AgentOverride::TYPE_SECTION_TOGGLED,
                'target_id'               => $key,
                'before_value'            => ['enabled' => $prevValue],
                'after_value'             => ['enabled' => $enabled, 'triggered_by' => 'agent'],
            ]);
            // Log each cascade so the audit captures the implicit flip.
            foreach ($applied['cascaded'] as $cascadeKey => $cascadeValue) {
                AgentOverride::create([
                    'agency_id'               => $version->agency_id,
                    'presentation_version_id' => $version->id,
                    'user_id'                 => $request->user()->id,
                    'override_type'           => AgentOverride::TYPE_SECTION_TOGGLED,
                    'target_id'               => $cascadeKey,
                    'before_value'            => ['enabled' => !$cascadeValue],
                    'after_value'             => ['enabled' => $cascadeValue, 'triggered_by' => 'cascade', 'cause' => $key],
                ]);
            }
            return $applied;
        });

        return response()->json([
            'ok'            => true,
            'snapshot'      => $result['snapshot'],
            'cascaded'      => $result['cascaded'],
            'page_estimate' => $version->fresh()->estimatedPageCount(),
        ]);
    }

    /**
     * Build 3 — agent picks (or changes) the condition on the review
     * screen. Writes a TYPE_CONDITION_CHANGED override row and returns
     * the recomputed CMA bands so the JS can update the displayed
     * valuation without a page reload.
     */
    public function setCondition(Request $request, PresentationVersion $version): JsonResponse
    {
        $this->authoriseReviewer($request, $version);

        $request->validate([
            // Null clears the override → falls back to property condition
            // (or baseline if property has none).
            'condition_level_id' => 'nullable|integer|exists:property_setting_items,id',
        ]);

        $previousId = $version->condition_level_id;
        $newId      = $request->input('condition_level_id') ?: null;

        // Agency isolation: a malicious POST that smuggles a foreign
        // level id must be rejected. Verify the picked level (if any)
        // belongs to this version's agency AND is a condition_level.
        if ($newId !== null) {
            $level = PropertySettingItem::withoutGlobalScopes()
                ->where('id', $newId)
                ->where('agency_id', $version->agency_id)
                ->where('group', PropertySettingItem::GROUP_CONDITION_LEVEL)
                ->first();
            if (!$level) {
                return response()->json(['error' => 'invalid_condition_level'], 422);
            }
        }

        DB::transaction(function () use ($version, $previousId, $newId, $request) {
            $version->forceFill(['condition_level_id' => $newId])->save();

            AgentOverride::create([
                'agency_id'               => $version->agency_id,
                'presentation_version_id' => $version->id,
                'user_id'                 => $request->user()->id,
                'override_type'           => AgentOverride::TYPE_CONDITION_CHANGED,
                'target_id'               => 'condition_level_id',
                'before_value'            => ['condition_level_id' => $previousId],
                'after_value'             => ['condition_level_id' => $newId],
            ]);
        });

        // Recompute the CMA bands with the new condition and return
        // them so the JS can patch the valuation strip in-place.
        $version->refresh();
        $presentation = $version->presentation()->with('property')->first();
        $analysis     = (new AnalysisDataService())->compile($presentation, $version);
        $cma          = $analysis['cma_valuation'] ?? [];

        return response()->json([
            'ok'        => true,
            'condition' => [
                'level_id'   => $newId,
                'pct'        => $cma['condition_pct'] ?? null,
                'label'      => $cma['condition_label'] ?? null,
                'source'     => $cma['condition_source'] ?? 'none',
                'applied'    => (bool) ($cma['condition_applied'] ?? false),
            ],
            'cma'       => [
                'lower'           => $cma['cma_lower'] ?? null,
                'middle'          => $cma['cma_middle'] ?? null,
                'middle_baseline' => $cma['cma_middle_baseline'] ?? null,
                'upper'           => $cma['cma_upper'] ?? null,
                'pool_n'          => $cma['compute_pool_n'] ?? 0,
            ],
        ]);
    }

    /**
     * Toggle a comp's included flag. Writes a row to agent_overrides
     * and returns the updated row state so the JS can render
     * optimistically. Idempotent — re-POSTing the same intent is a
     * no-op log entry skipped silently.
     */
    public function toggleComp(Request $request, PresentationVersion $version, PresentationSoldComp $comp): JsonResponse
    {
        $this->authoriseReviewer($request, $version);

        $request->validate([
            'included' => 'required|boolean',
        ]);

        if ((int) $comp->presentation_id !== (int) $version->presentation_id) {
            return response()->json(['error' => 'comp_not_in_version'], 422);
        }

        $current = $version->included_comp_ids_json ?? PresentationSoldComp::query()
            ->where('presentation_id', $version->presentation_id)
            ->whereNull('deleted_at')
            ->pluck('id')->all();
        $current = array_values(array_unique(array_map('intval', $current)));

        $wantIncluded = (bool) $request->boolean('included');
        $wasIncluded  = in_array((int) $comp->id, $current, true);

        if ($wantIncluded === $wasIncluded) {
            // No-op — idempotent re-toggle (e.g. double-click). Return
            // current state without logging.
            return response()->json([
                'ok'           => true,
                'comp_id'      => $comp->id,
                'is_included'  => $wasIncluded,
                'no_op'        => true,
            ]);
        }

        if ($wantIncluded) {
            $current[] = (int) $comp->id;
        } else {
            $current = array_values(array_diff($current, [(int) $comp->id]));
        }

        DB::transaction(function () use ($version, $current, $comp, $request, $wantIncluded, $wasIncluded) {
            $version->forceFill(['included_comp_ids_json' => $current])->save();

            AgentOverride::create([
                'agency_id'               => $version->agency_id,
                'presentation_version_id' => $version->id,
                'user_id'                 => $request->user()->id,
                'override_type'           => $wantIncluded
                    ? AgentOverride::TYPE_COMP_INCLUDED
                    : AgentOverride::TYPE_COMP_EXCLUDED,
                'target_id'               => (string) $comp->id,
                'before_value'            => ['is_included' => $wasIncluded],
                'after_value'             => ['is_included' => $wantIncluded],
            ]);
        });

        // Tick-wire build — recompute the CMA bands against the new
        // included-comp set so the JS can patch the valuation tiles
        // in place. Mirrors the response shape setCondition returns
        // (L321-336) so review.blade.php's applyCmaUpdate helper
        // works for both triggers.
        $version->refresh();
        $presentation = $version->presentation()->with('property')->first();
        $analysis     = (new AnalysisDataService())->compile($presentation, $version);
        $cma          = $analysis['cma_valuation'] ?? [];

        return response()->json([
            'ok'           => true,
            'comp_id'      => $comp->id,
            'is_included'  => $wantIncluded,
            'override_id'  => AgentOverride::where('presentation_version_id', $version->id)
                                  ->where('target_id', (string) $comp->id)
                                  ->latest('id')->value('id'),
            'condition'    => [
                'level_id'   => $version->condition_level_id,
                'pct'        => $cma['condition_pct']    ?? null,
                'label'      => $cma['condition_label']  ?? null,
                'source'     => $cma['condition_source'] ?? 'none',
                'applied'    => (bool) ($cma['condition_applied'] ?? false),
            ],
            'cma'          => [
                'lower'           => $cma['cma_lower']           ?? null,
                'middle'          => $cma['cma_middle']          ?? null,
                'middle_baseline' => $cma['cma_middle_baseline'] ?? null,
                'upper'           => $cma['cma_upper']           ?? null,
                'pool_n'          => $cma['compute_pool_n']      ?? 0,
            ],
        ]);
    }

    /**
     * AT-22 — single CMA payload shape shared by the curation endpoints, so
     * the client's applyCmaUpdate() patches the valuation tiles identically
     * whether the change came from a single toggle, the slider/bulk set, or a
     * browse-add.
     */
    private function cmaPayload(PresentationVersion $version): array
    {
        $version->refresh();
        $presentation = $version->presentation()->with('property')->first();
        $analysis     = (new AnalysisDataService())->compile($presentation, $version);
        $cma          = $analysis['cma_valuation'] ?? [];
        return [
            'cma' => [
                'lower'           => $cma['cma_lower']           ?? null,
                'middle'          => $cma['cma_middle']          ?? null,
                'middle_baseline' => $cma['cma_middle_baseline'] ?? null,
                'upper'           => $cma['cma_upper']           ?? null,
                'pool_n'          => $cma['compute_pool_n']      ?? 0,
            ],
        ];
    }

    /**
     * AT-22 (AT-21 fold-in) — batch include-set write for the curation toolkit
     * (price-range slider / column sort / select-all / bulk tick). The client
     * computes the full desired included-comp set and posts it once; we persist
     * it in a SINGLE write + one audit row + one CMA recompute, instead of N
     * per-comp round-trips. One source of truth: included_comp_ids_json.
     */
    public function setComps(Request $request, PresentationVersion $version): JsonResponse
    {
        $this->authoriseReviewer($request, $version);
        $data = $request->validate([
            'included_ids'   => 'present|array',
            'included_ids.*' => 'integer',
        ]);

        $validIds = PresentationSoldComp::query()
            ->where('presentation_id', $version->presentation_id)
            ->whereNull('deleted_at')
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        $included = array_values(array_intersect(
            array_values(array_unique(array_map('intval', $data['included_ids']))),
            $validIds
        ));

        DB::transaction(function () use ($version, $included, $request) {
            $version->forceFill(['included_comp_ids_json' => $included])->save();
            AgentOverride::create([
                'agency_id'               => $version->agency_id,
                'presentation_version_id' => $version->id,
                'user_id'                 => $request->user()->id,
                'override_type'           => AgentOverride::TYPE_COMP_BULK_SET,
                'target_id'               => 'bulk',
                'before_value'            => null,
                'after_value'             => ['count' => count($included), 'ids' => $included],
            ]);
        });

        return response()->json(array_merge(
            ['ok' => true, 'included_count' => count($included)],
            $this->cmaPayload($version)
        ));
    }

    /**
     * AT-22 — browse freehold sold comps NEAR the subject that are NOT already
     * in the pool, so the agent can pull in genuine comparables a little
     * further out (e.g. premium sales just past the auto radius). Type-matched
     * (freehold — mirrors the gate), bounded by an agent radius + price range.
     */
    public function browseComps(Request $request, PresentationVersion $version): JsonResponse
    {
        $this->authoriseReviewer($request, $version);
        $data = $request->validate([
            'radius_m'  => 'nullable|integer|min:100|max:20000',
            'price_min' => 'nullable|integer|min:0',
            'price_max' => 'nullable|integer|min:0',
        ]);
        $radius   = (int) ($data['radius_m']  ?? 3000);
        $priceMin = (int) ($data['price_min'] ?? 0);
        $priceMax = (int) ($data['price_max'] ?? 0);

        $presentation = $version->presentation()->with('property')->first();
        $prop = $presentation->property;
        $sLat = $prop?->latitude ?? $prop?->cma_gps_lat;
        $sLng = $prop?->longitude ?? $prop?->cma_gps_lng;
        if ($sLat === null || $sLng === null) {
            return response()->json(['ok' => true, 'candidates' => [], 'reason' => 'subject_no_coords']);
        }
        $sLat = (float) $sLat; $sLng = (float) $sLng;

        // Already-materialised market rows — never offer a duplicate.
        $haveRowIds = [];
        foreach (PresentationSoldComp::where('presentation_id', $version->presentation_id)->whereNull('deleted_at')->get(['raw_row_json']) as $r) {
            $raw = is_string($r->raw_row_json) ? json_decode($r->raw_row_json, true) : (array) $r->raw_row_json;
            if (is_array($raw) && !empty($raw['mic_comp_row_id'])) {
                $haveRowIds[(int) $raw['mic_comp_row_id']] = true;
            }
        }

        $latDelta = $radius / 111000.0;
        $lngDelta = $radius / (111000.0 * max(0.1, cos(deg2rad($sLat))));

        $q = \App\Models\MarketReports\MarketReportCompRow::query()
            ->where('row_type', \App\Models\MarketReports\MarketReportCompRow::ROW_COMP)
            ->whereNotNull('sale_price')->where('sale_price', '>', 0)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereBetween('latitude', [$sLat - $latDelta, $sLat + $latDelta])
            ->whereBetween('longitude', [$sLng - $lngDelta, $sLng + $lngDelta])
            // Freehold only — no sectional signal (mirrors the type gate).
            ->where(fn ($w) => $w->whereNull('scheme_name')->orWhere('scheme_name', ''))
            ->where(fn ($w) => $w->whereNull('section_number')->orWhere('section_number', ''));
        if ($priceMin > 0) $q->where('sale_price', '>=', $priceMin);
        if ($priceMax > 0) $q->where('sale_price', '<=', $priceMax);

        $candidates = [];
        foreach ($q->limit(400)->get() as $row) {
            if (isset($haveRowIds[(int) $row->id])) continue;
            $d = \App\Support\MarketAnalytics\HaversineDistance::distanceMetres($sLat, $sLng, (float) $row->latitude, (float) $row->longitude);
            if ($d > $radius) continue;
            $candidates[] = [
                'comp_row_id' => $row->id,
                'address'     => CompLabel::build($row->toArray(), $row->suburb_normalised ?? null, $row->id),
                'sold_price'  => (int) $row->sale_price,
                'size_m2'     => $row->extent_m2 ? (int) $row->extent_m2 : null,
                'distance_m'  => (int) round($d),
                'sale_date'   => optional($row->sale_date)->format('Y-m-d'),
            ];
        }
        usort($candidates, fn ($a, $b) => $a['distance_m'] <=> $b['distance_m']);

        return response()->json(['ok' => true, 'candidates' => array_slice($candidates, 0, 60)]);
    }

    /**
     * AT-22 — pull selected browse candidates into the pool: materialise each
     * MarketReportCompRow as a PresentationSoldComp (agent-tagged so a later
     * re-hydration does NOT wipe it) and add it to included_comp_ids_json.
     */
    public function addComps(Request $request, PresentationVersion $version): JsonResponse
    {
        $this->authoriseReviewer($request, $version);
        $data = $request->validate([
            'comp_row_ids'   => 'required|array|min:1',
            'comp_row_ids.*' => 'integer',
        ]);
        $presentation = $version->presentation()->with('property')->first();

        $rows = \App\Models\MarketReports\MarketReportCompRow::query()
            ->where('row_type', \App\Models\MarketReports\MarketReportCompRow::ROW_COMP)
            ->whereIn('id', array_map('intval', $data['comp_row_ids']))
            ->get();

        $newIds = [];
        DB::transaction(function () use ($rows, $presentation, $version, &$newIds, $request) {
            foreach ($rows as $row) {
                $price = (int) $row->sale_price;
                if ($price <= 0) { continue; }
                $raw = [
                    'source'          => 'agent_browse',
                    'mic_comp_row_id' => $row->id,
                    'address'         => $row->address,
                    'scheme_name'     => $row->scheme_name,
                    'section_number'  => $row->section_number,
                    'extent_m2'       => $row->extent_m2,
                    'sale_date'       => optional($row->sale_date)->format('Y-m-d'),
                    'sale_price'      => $price,
                    'latitude'        => $row->latitude,
                    'longitude'       => $row->longitude,
                ];
                $comp = PresentationSoldComp::create([
                    'agency_id'       => $version->agency_id,
                    'presentation_id' => $version->presentation_id,
                    'sold_date'       => $row->sale_date,
                    'sold_price_inc'  => $price,
                    'suburb'          => $row->suburb_normalised ?? $presentation->suburb,
                    'property_type'   => $row->property_type,
                    'size_m2'         => $row->extent_m2 ? (int) $row->extent_m2 : null,
                    'raw_row_json'    => json_encode($raw),
                    'parser_version'  => 'agent_browse',
                ]);
                $newIds[] = (int) $comp->id;
            }

            // Newly-added comps must be INCLUDED. If the version had no explicit
            // set yet (null = "all persisted"), make it explicit so both the
            // existing default pool and the new comps are honoured.
            $current = $version->included_comp_ids_json ?? PresentationSoldComp::query()
                ->where('presentation_id', $version->presentation_id)
                ->whereNull('deleted_at')
                ->whereNotIn('id', $newIds)
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
            $current = array_values(array_unique(array_merge(array_map('intval', $current), $newIds)));
            $version->forceFill(['included_comp_ids_json' => $current])->save();

            AgentOverride::create([
                'agency_id'               => $version->agency_id,
                'presentation_version_id' => $version->id,
                'user_id'                 => $request->user()->id,
                'override_type'           => AgentOverride::TYPE_COMP_ADDED,
                'target_id'               => 'browse',
                'before_value'            => null,
                'after_value'             => ['added_ids' => $newIds],
            ]);
        });

        return response()->json(array_merge(
            ['ok' => true, 'added_count' => count($newIds), 'added_ids' => $newIds],
            $this->cmaPayload($version)
        ));
    }

    /**
     * Toggle a competitor listing's included flag on the Active
     * Competition section. Mirrors toggleComp's contract — persists
     * to presentation_versions.included_competitor_ids_json, writes
     * an agent_overrides row, returns JSON with the listing's new
     * is_included state for optimistic UI patch.
     *
     * The listing is identified by its prospecting_listings.id —
     * passed as a route param (no Eloquent binding because we just
     * need the id). Validates the listing is in the same agency as
     * the version.
     */
    public function toggleCompetitor(Request $request, PresentationVersion $version, int $listingId): JsonResponse
    {
        $this->authoriseReviewer($request, $version);

        $request->validate([
            'included' => 'required|boolean',
        ]);

        // Listing must exist in the same agency.
        $listingRow = DB::table('prospecting_listings')
            ->where('id', $listingId)
            ->where('agency_id', $version->agency_id)
            ->whereNull('deleted_at')
            ->first(['id', 'property_type']);
        if (!$listingRow) {
            return response()->json(['error' => 'listing_not_in_agency'], 422);
        }

        // LEVEL 1 HARD GATE on the toggle path itself — even if the
        // modal accidentally surfaces a cross-family row (it shouldn't,
        // but defence in depth), reject the toggle here. Without this
        // check a tampered request could whitelist a House for a
        // sectional subject and the union path in
        // compileCompetitorStock would happily score it as 'family
        // mismatch → dropped', but the audit row would still land.
        $presentation = $version->presentation()->with('property')->first();
        $subjectProperty = $presentation?->property;
        if ($subjectProperty) {
            $service = app(CompetitorStockMatchService::class);
            $criteria = $service->buildCriteria($subjectProperty);
            if ($criteria !== null) {
                $candidateKind = app(\App\Services\TitleTypeClassifier::class)
                    ->fromPropertyType((string) $listingRow->property_type);
                $candidateFamily = match ($candidateKind) {
                    \App\Services\TitleTypeClassifier::TITLE_SECTIONAL => 'sectional',
                    \App\Services\TitleTypeClassifier::TITLE_FULL      => 'freehold',
                    \App\Services\TitleTypeClassifier::TITLE_VACANT    => 'freehold',
                    default                                            => null,
                };
                if ($candidateFamily !== $criteria['family']) {
                    return response()->json([
                        'error'           => 'cross_family_pick_blocked',
                        'subject_family'  => $criteria['family'],
                        'candidate_family'=> $candidateFamily,
                        'message'         => 'Cross-family picks are not allowed (sectional ↔ freehold boundary).',
                    ], 422);
                }
            }
        }

        // Default whitelist = TOP N auto-picks by score. When the agent
        // first ticks anything, we materialise the whitelist from the
        // top N of the scored set so the visible cards on screen are
        // preserved (the agent's tick removes one row, not all the
        // others). The N is the agency display cap setting.
        $current = $version->included_competitor_ids_json;
        if ($current === null) {
            if ($subjectProperty) {
                $scored = app(CompetitorStockMatchService::class)
                    ->findCompetitors($subjectProperty);
                $agency = $version->agency_id ? \App\Models\Agency::find($version->agency_id) : null;
                $cap = (int) ($agency?->competitor_stock_default_display_count ?? 10);
                $scored = $cap > 0 ? $scored->take($cap) : $scored;
                $current = $scored->pluck('listing_id')->map(fn ($v) => (int) $v)->all();
            } else {
                $current = [];
            }
        }
        $current = array_values(array_unique(array_map('intval', $current)));

        $wantIncluded = (bool) $request->boolean('included');
        $wasIncluded  = in_array($listingId, $current, true);

        if ($wantIncluded === $wasIncluded) {
            return response()->json([
                'ok'           => true,
                'listing_id'   => $listingId,
                'is_included'  => $wasIncluded,
                'no_op'        => true,
            ]);
        }

        if ($wantIncluded) {
            $current[] = $listingId;
        } else {
            $current = array_values(array_diff($current, [$listingId]));
        }

        DB::transaction(function () use ($version, $current, $listingId, $request, $wantIncluded, $wasIncluded) {
            $version->forceFill(['included_competitor_ids_json' => $current])->save();

            AgentOverride::create([
                'agency_id'               => $version->agency_id,
                'presentation_version_id' => $version->id,
                'user_id'                 => $request->user()->id,
                'override_type'           => $wantIncluded
                    ? AgentOverride::TYPE_COMP_INCLUDED
                    : AgentOverride::TYPE_COMP_EXCLUDED,
                'target_id'               => 'competitor:' . $listingId,
                'before_value'            => ['is_included' => $wasIncluded],
                'after_value'             => ['is_included' => $wantIncluded],
            ]);
        });

        return response()->json([
            'ok'           => true,
            'listing_id'   => $listingId,
            'is_included'  => $wantIncluded,
            'override_id'  => AgentOverride::where('presentation_version_id', $version->id)
                                  ->where('target_id', 'competitor:' . $listingId)
                                  ->latest('id')->value('id'),
        ]);
    }

    /**
     * GET — return the freshly-computed competitor_stock payload so the
     * review screen can re-render its Active Competition section in
     * place after the manual-picker modal closes. Same shape
     * AnalysisDataService::compileCompetitorStock produces (matches /
     * included_ids / visible / display_cap).
     */
    public function competitorData(Request $request, PresentationVersion $version): JsonResponse
    {
        $this->authoriseReviewer($request, $version);
        $presentation = $version->presentation()->with(['property', 'fields'])->first();
        if (!$presentation) {
            return response()->json(['error' => 'presentation_not_found'], 404);
        }

        $analysis = (new AnalysisDataService())->compile($presentation, $version);
        return response()->json($analysis['competitor_stock'] ?? [
            'matches'      => [],
            'included_ids' => null,
            'visible'      => [],
            'display_cap'  => null,
        ]);
    }

    /**
     * GET — manual-picker modal search endpoint. Accepts agent-loosened
     * filters via query string, returns scored rows from
     * searchForManualPicker (same shape the review screen uses).
     * Also returns the bootstrap criteria (so the modal can populate
     * its filter inputs to the auto-picker defaults on first open).
     */
    public function competitorPickerSearch(Request $request, PresentationVersion $version): JsonResponse
    {
        $this->authoriseReviewer($request, $version);
        $presentation = $version->presentation()->with('property')->first();
        if (!$presentation || !$presentation->property) {
            return response()->json(['error' => 'no_subject_property'], 422);
        }

        $service = app(CompetitorStockMatchService::class);
        $subject = $presentation->property;

        $criteria = $service->buildCriteria($subject);
        if ($criteria === null) {
            return response()->json([
                'error'    => 'subject_not_pickable',
                'message'  => 'Subject has no price/suburb or is not residential — manual picker unavailable.',
            ], 422);
        }

        // Strip the Property/object-typed entries before exposing the
        // criteria to the frontend (XHR JSON, not domain payload).
        $criteriaPublic = $criteria;
        unset($criteriaPublic['subject']);

        $userFilters = $request->validate([
            'suburb'        => 'sometimes|nullable|string|max:120',
            'property_type' => 'sometimes|nullable|string|max:120',
            'price_min'     => 'sometimes|nullable|integer|min:0|max:9999999999',
            'price_max'     => 'sometimes|nullable|integer|min:0|max:9999999999',
            'beds_min'      => 'sometimes|nullable|integer|min:0|max:99',
            'beds_max'      => 'sometimes|nullable|integer|min:0|max:99',
            'search'        => 'sometimes|nullable|string|max:120',
            'limit'         => 'sometimes|nullable|integer|min:1|max:500',
        ]);

        $rows = $service->searchForManualPicker($subject, $userFilters);

        // Annotate each row with the current included-state so the modal
        // can render the tick checkbox correctly.
        $whitelist = $version->included_competitor_ids_json ?? [];
        $whitelistSet = array_flip(array_map('intval', $whitelist));
        $annotated = $rows->map(function (array $row) use ($whitelistSet) {
            $row['is_included'] = isset($whitelistSet[(int) $row['listing_id']]);
            return $row;
        })->values()->all();

        return response()->json([
            'criteria' => $criteriaPublic,
            'results'  => $annotated,
        ]);
    }

    /**
     * Holding-cost component override. Writes to THREE places in one
     * transaction:
     *   1. presentations.monthly_<component>     — the persisted breakdown
     *   2. holding_cost_data_points (source=agent_override) — grows
     *                                              the learned-average dataset
     *   3. agent_overrides (TYPE_FIELD_EDITED)   — audit trail
     *
     * Returns the recomputed holding-cost breakdown so the JS can
     * live-patch the totals + 3/6/12-month projections in place.
     */
    public function setHoldingCostComponent(Request $request, PresentationVersion $version): JsonResponse
    {
        $this->authoriseReviewer($request, $version);

        $validComponents = [
            HoldingCostDataPoint::COMPONENT_RATES,
            HoldingCostDataPoint::COMPONENT_LEVY,
            HoldingCostDataPoint::COMPONENT_INSURANCE,
            HoldingCostDataPoint::COMPONENT_UTILITIES,
            HoldingCostDataPoint::COMPONENT_GARDEN,
            HoldingCostDataPoint::COMPONENT_POOL,
            HoldingCostDataPoint::COMPONENT_SECURITY,
            HoldingCostDataPoint::COMPONENT_BOND,
            HoldingCostDataPoint::COMPONENT_OPPORTUNITY_COST,
        ];

        $data = $request->validate([
            'component'         => 'required|string|in:' . implode(',', $validComponents),
            'monthly_value_zar' => 'required|numeric|min:0|max:9999999999',
        ]);

        $estimator = app(HoldingCostEstimator::class);
        $column    = $estimator->columnFor($data['component']);
        if ($column === null) {
            return response()->json(['error' => 'no_persisted_column_for_component'], 422);
        }

        $presentation = $version->presentation()->with(['property', 'fields'])->first();
        if (!$presentation) {
            return response()->json(['error' => 'presentation_not_found'], 404);
        }

        $previousValue = $presentation->{$column};
        $newValue      = (int) round((float) $data['monthly_value_zar']);

        // Build the context once so the data-point captures all the
        // averaging keys for future Tier 1 lookups.
        $property = $presentation->property;
        $asking   = $presentation->asking_price_inc !== null ? (int) $presentation->asking_price_inc : 0;
        $context  = $estimator->buildContext($presentation, $property, $asking);

        DB::transaction(function () use (
            $version, $presentation, $property, $column, $newValue, $previousValue,
            $data, $context, $request, $estimator
        ) {
            // 1. Persist on the presentation column.
            $presentation->{$column} = $newValue;
            $presentation->save();

            // 2. Capture as a learning data point. Even when the
            //    value equals what tiering would have produced, store
            //    it — the agency exclude-grid is the future tightening
            //    lever, not pre-filtering here.
            HoldingCostDataPoint::create([
                'agency_id'               => $version->agency_id,
                'presentation_version_id' => $version->id,
                'property_id'             => $property?->id,
                'component'               => $data['component'],
                'monthly_value_zar'       => $newValue,
                'scheme_name'             => $context['scheme_name'],
                'suburb_normalised'       => $context['suburb_normalised'],
                'municipality'            => $context['municipality'],
                'property_type'           => $context['property_type'],
                'title_type'              => $context['title_type'],
                'property_value_band'     => $context['property_value_band'],
                'source'                  => HoldingCostDataPoint::SOURCE_AGENT_OVERRIDE,
                'source_ref'              => 'presentation_version:' . $version->id . ':' . $column,
                'entered_by_user_id'      => $request->user()->id,
            ]);

            // 3. Audit row.
            AgentOverride::create([
                'agency_id'               => $version->agency_id,
                'presentation_version_id' => $version->id,
                'user_id'                 => $request->user()->id,
                'override_type'           => AgentOverride::TYPE_FIELD_EDITED,
                'target_id'               => 'holding_cost:' . $data['component'],
                'before_value'            => [$column => $previousValue],
                'after_value'             => [$column => $newValue],
            ]);
        });

        // Recompute the holding-cost block + return for live patch.
        $analysis = (new AnalysisDataService())->compile($presentation->fresh(['property', 'fields']), $version);
        $holding  = $analysis['holding_cost'] ?? [];

        return response()->json([
            'ok'           => true,
            'component'    => $data['component'],
            'column'       => $column,
            'value'        => $newValue,
            'holding_cost' => $holding,
        ]);
    }

    /**
     * AT-27 Phase A — "Continue to Analysis" (replaces publish() as the
     * review-screen forward action).
     *
     * Curation (comp include/exclude, section toggles) is already persisted by
     * its own endpoints, so this does NOT freeze a snapshot and does NOT
     * publish — the single draft version stays mutable. It marks the version
     * in-analysis and hands the agent to the Analysis working surface, where
     * the numbers are finalised and the exec summary is generated only at
     * "Confirm & Generate" (AT-27 Phase B). The old publish()/freeze path below
     * is retired when Phase B relocates the freeze to the Analysis-confirm step.
     */
    public function continueToAnalysis(Request $request, PresentationVersion $version): JsonResponse
    {
        $this->authoriseReviewer($request, $version);

        // Advance a draft forward; never demote an already-published version
        // (re-opening a published version for rework is a separate flow).
        if (in_array($version->review_status, [
            PresentationVersion::REVIEW_DRAFT,
            PresentationVersion::REVIEW_AWAITING,
            PresentationVersion::REVIEW_IN_ANALYSIS,
        ], true)) {
            $version->forceFill([
                'review_status' => PresentationVersion::REVIEW_IN_ANALYSIS,
            ])->save();
        }

        return response()->json([
            'ok'           => true,
            'redirect_url' => route('presentations.analysis', $version->presentation_id),
        ]);
    }

    // AT-27 Phase B — publish() RETIRED. The snapshot freeze (resolved
    // condition + full payload) it performed now lives in
    // PresentationController::confirmAndGenerate, fired by the Analysis
    // "Confirm & Generate" action, so the freeze happens once, after the agent
    // has confirmed the numbers, alongside exec-summary generation.

    /**
     * Revert the version — soft-delete it and bounce the agent back
     * to the source property. Logged with override_type=field_edited
     * (target_id='review_status').
     */
    public function revert(Request $request, PresentationVersion $version): JsonResponse
    {
        $this->authoriseReviewer($request, $version);

        DB::transaction(function () use ($version, $request) {
            AgentOverride::create([
                'agency_id'               => $version->agency_id,
                'presentation_version_id' => $version->id,
                'user_id'                 => $request->user()->id,
                'override_type'           => AgentOverride::TYPE_FIELD_EDITED,
                'target_id'               => 'review_status',
                'before_value'            => ['review_status' => $version->review_status],
                'after_value'             => ['review_status' => PresentationVersion::REVIEW_ARCHIVED],
            ]);

            $version->forceFill([
                'review_status' => PresentationVersion::REVIEW_ARCHIVED,
                'archived_at'   => now(),
            ])->save();
            $version->delete();
        });

        // Bounce the agent's review tab back to the source property —
        // the property tab they came from stays open separately.
        $propertyId = $version->presentation->property_id ?? null;
        return response()->json([
            'ok'           => true,
            'property_url' => $propertyId
                ? route('corex.properties.show', $propertyId)
                : route('presentations.index'),
        ]);
    }

    /**
     * Take over the review lock from another agent.
     */
    public function takeover(Request $request, PresentationVersion $version): JsonResponse
    {
        $this->authoriseReviewer($request, $version);

        $previousUserId = $version->reviewer_user_id;
        DB::transaction(function () use ($version, $request, $previousUserId) {
            $version->forceFill([
                'reviewer_user_id'   => $request->user()->id,
                'reviewer_locked_at' => now(),
            ])->save();

            AgentOverride::create([
                'agency_id'               => $version->agency_id,
                'presentation_version_id' => $version->id,
                'user_id'                 => $request->user()->id,
                'override_type'           => AgentOverride::TYPE_REVIEW_TAKEOVER,
                'target_id'               => 'reviewer_user_id',
                'before_value'            => ['reviewer_user_id' => $previousUserId],
                'after_value'             => ['reviewer_user_id' => $request->user()->id],
            ]);
        });

        return response()->json([
            'ok'         => true,
            'review_url' => route('presentations.review.show', $version->id),
        ]);
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** Permission gate. Throws 403 on mismatch. */
    private function authoriseReviewer(Request $request, PresentationVersion $version): void
    {
        $user = $request->user();
        abort_unless($user, 403);

        if (!$user->hasPermission('access_presentations')) {
            abort(403, 'You do not have permission to access presentations.');
        }

        if ((int) $version->agency_id !== (int) $user->effectiveAgencyId()) {
            abort(403, 'Presentation is outside your agency scope.');
        }
    }

    /**
     * Drop soft-deleted comps from the included set if any were removed
     * between compile and review. Log a comp_unavailable row per dropped
     * comp so the audit trail captures the implicit change.
     *
     * Returns the number of comps that were auto-dropped, so the Blade
     * can surface a banner.
     */
    private function reconcileSoftDeletedComps(Request $request, PresentationVersion $version): int
    {
        $included = $version->included_comp_ids_json;
        if (empty($included)) return 0;

        $existing = PresentationSoldComp::query()
            ->whereIn('id', $included)
            ->whereNull('deleted_at')
            ->pluck('id')->all();
        $missing = array_diff($included, array_map('intval', $existing));
        if (empty($missing)) return 0;

        $surviving = array_values(array_diff($included, $missing));
        DB::transaction(function () use ($version, $missing, $surviving, $request) {
            $version->forceFill(['included_comp_ids_json' => $surviving])->save();
            foreach ($missing as $compId) {
                AgentOverride::create([
                    'agency_id'               => $version->agency_id,
                    'presentation_version_id' => $version->id,
                    'user_id'                 => $request->user()->id,
                    'override_type'           => AgentOverride::TYPE_COMP_UNAVAILABLE,
                    'target_id'               => (string) $compId,
                    'before_value'            => ['is_included' => true],
                    'after_value'             => ['is_included' => false, 'reason' => 'soft_deleted'],
                ]);
            }
        });
        return count($missing);
    }

    // Keystone — resolveSubjectTitleType + classifyCompTitleType were
    // duplicates of MicSnapshotHydrator's pair. Both retired. The
    // controller now reads $presentation->property?->title_type
    // directly (line ~83 above), with App\Services\TitleTypeClassifier
    // as the fallback path for legacy rows pre-dating the backfill.
}
