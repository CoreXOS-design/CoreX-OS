<?php

declare(strict_types=1);

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\AgentOverride;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Models\PropertySettingItem;
use App\Services\Presentations\AnalysisDataService;
use App\Services\Presentations\ConditionAdjustmentService;
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

        // title_type resolution for subject + cross-type warning flags
        // per comp. Mirrors MicSnapshotHydrator's classifier.
        $subjectTitleType = $this->resolveSubjectTitleType($version, $presentation);

        $compRows = $allComps->map(function ($c) use ($includedIds, $subjectTitleType) {
            $raw = is_string($c->raw_row_json)
                ? (json_decode($c->raw_row_json, true) ?: [])
                : ((array) $c->raw_row_json ?: []);
            $compTitleType = $this->classifyCompTitleType($c->property_type);
            return [
                'id'              => $c->id,
                'address'         => $raw['address'] ?? '—',
                'sale_date'       => optional($c->sold_date)->format('Y-m-d'),
                'sold_price_inc'  => $c->sold_price_inc,
                'property_type'   => $c->property_type,
                'size_m2'         => $c->size_m2,
                'r_per_m2'        => ($c->size_m2 && $c->sold_price_inc)
                    ? (int) round($c->sold_price_inc / $c->size_m2) : null,
                'lat'             => $raw['latitude'] ?? null,
                'lng'             => $raw['longitude'] ?? null,
                'title_type'      => $compTitleType,
                'is_included'     => in_array($c->id, $includedIds, true),
                'is_cross_type'   => $subjectTitleType !== null && $subjectTitleType !== $compTitleType,
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
        $analysis    = (new AnalysisDataService())->compile($presentation, $version);
        $cmaValue    = $analysis['cma_valuation'] ?? [];

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

        return response()->json([
            'ok'           => true,
            'comp_id'      => $comp->id,
            'is_included'  => $wantIncluded,
            'override_id'  => AgentOverride::where('presentation_version_id', $version->id)
                                  ->where('target_id', (string) $comp->id)
                                  ->latest('id')->value('id'),
        ]);
    }

    /**
     * Publish the version. Idempotent — re-publish is a no-op.
     * Returns JSON with the public/show URL for the JS to navigate
     * the (already-open) review tab to.
     */
    public function publish(Request $request, PresentationVersion $version): JsonResponse
    {
        $this->authoriseReviewer($request, $version);

        // Build 5 — republish: an already-published version that gets re-
        // published refreshes the snapshot. Same URL, new content. This
        // is Johan's explicit ruling — live edits override the prior
        // snapshot, age resets to 0, the freshness CTA disappears.
        $isRepublish = $version->review_status === PresentationVersion::REVIEW_PUBLISHED;

        // Build 3 — snapshot the resolved condition on the version BEFORE
        // status flips. The snapshot defends the PDF against future
        // agency-settings drift; without it the agency could edit
        // adjustment_pct after publish and silently change historic
        // valuations.
        $presentation = $version->presentation;
        $resolver = app(ConditionAdjustmentService::class);
        $resolved = $resolver->resolveLive($version, $presentation);
        $resolver->snapshotOnVersion($version, $resolved['level']);

        // Build 5 — freeze the full compiled report payload onto the
        // version. The public view reads ONLY from this column, so the
        // seller always sees what was true on publish day even if the
        // underlying property / comps / suburb stats shift later.
        // Republishes overwrite snapshot_payload + refresh
        // snapshot_taken_at, restarting the freshness clock.
        $freshPresentation = $version->presentation()->with(['property', 'fields', 'soldComps', 'activeListings'])->first();
        $payload = (new AnalysisDataService())->compile($freshPresentation, $version);

        $version->forceFill([
            'review_status'     => PresentationVersion::REVIEW_PUBLISHED,
            'published_at'      => $isRepublish ? $version->published_at : now(),
            'snapshot_payload'  => $payload,
            'snapshot_taken_at' => now(),
        ])->save();

        return response()->json([
            'ok'         => true,
            'already'    => $isRepublish, // legacy flag for the JS path
            'public_url' => route('presentations.show', $version->presentation_id),
        ]);
    }

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

    /** Mirrors MicSnapshotHydrator::resolveSubjectTitleType but on the
     *  PresentationVersion's frozen presentation. Returns null when the
     *  subject has no resolvable category — page surfaces this. */
    private function resolveSubjectTitleType(PresentationVersion $version, $presentation): ?string
    {
        $categoryName = $presentation->property?->category ?? null;
        if (!is_string($categoryName) || trim($categoryName) === '') return null;

        $row = PropertySettingItem::withoutGlobalScopes()
            ->where('agency_id', $version->agency_id)
            ->where('group', PropertySettingItem::GROUP_CATEGORY)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($categoryName))])
            ->first(['title_type']);

        return $row?->title_type;
    }

    /** Mirrors MicSnapshotHydrator::classifyCompTitleType. */
    private function classifyCompTitleType(?string $compType): string
    {
        $t = strtolower((string) $compType);
        if ($t === '') return PropertySettingItem::TITLE_OTHER;
        if (str_contains($t, 'sectional') || str_contains($t, 'apartment') || str_contains($t, 'flat')
            || str_contains($t, 'unit') || str_contains($t, 'townhouse') || str_contains($t, 'duplex')) {
            return PropertySettingItem::TITLE_SECTIONAL;
        }
        if (str_contains($t, 'vacant') || str_contains($t, 'plot') || str_contains($t, 'stand')
            || str_contains($t, 'erf')) {
            return PropertySettingItem::TITLE_VACANT;
        }
        return PropertySettingItem::TITLE_FULL;
    }
}
