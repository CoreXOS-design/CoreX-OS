<?php

namespace App\Services\Prospecting;

use App\Models\ProspectingListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * MIC speed round 4 — SQL-side pagination for the Work-tab listing query.
 *
 * .ai/specs/mic-speed-option1-full-set-pagination-design.md — "Option A:
 * two-step ID pagination". Resolves exactly the current page's group-keys in
 * SQL (LIMIT/OFFSET at the GROUP level, not the raw row level), then
 * hydrates only those groups' rows — not the whole matching set.
 *
 * SCOPE — the fast path here handles the everyday canvass-pool screen (the
 * thing 39k+ rows makes slow). It deliberately does NOT cover every filter
 * combination on this screen:
 *   - AT-75 %-match band (score_min/score_max) and matched_only=1 filter
 *     rows by a buyer-match aggregate computed across the WHOLE result set
 *     before slicing — a fundamentally different query shape.
 *   - sort=buyer_matches / sort=match_score, and buyer-mode's implicit
 *     "strongest match first" default sort, order by that same aggregate.
 *   - include_in_stock=1 (manager audit toggle) injects synthetic
 *     property-backed rows from a DIFFERENT table (properties) and floats
 *     every stock row to the front of the WHOLE list, spanning as many
 *     pages as the agency's on-market stock needs — the correctness-
 *     critical, hardest-to-verify part of this whole redesign per the
 *     design spec's own risk section.
 * Any of those states falls back to MarketIntelligenceController::work()'s
 * existing, byte-for-byte-unchanged full-hydration code — canUseFastPath()
 * is the single gate. This is a deliberate scope decision, not an
 * oversight: none of it is the hot path (none of Johan's 12 baseline
 * profiling cases touch buyer mode, score bands, or the stock toggle), and
 * forcing those states through SQL-side grouping would mean re-deriving a
 * buyer-match-aggregate-aware GROUP BY and a page-scoped stock-float
 * design in the same pass as the base rewrite — exactly the "rushed"
 * scenario the design spec warns against. They stay exactly as they are
 * today; nothing about them changes.
 */
class ProspectingListingPageResolver
{
    public const ALLOWED_SORTS = ['last_seen_at', 'first_seen_at', 'price', 'suburb'];

    /**
     * Whether the fast (SQL-paginated) path is safe to use for this request.
     * See class docblock for exactly what's excluded and why.
     */
    public function canUseFastPath(
        Request $request,
        bool $isProspectingManager,
        ?int $selectedBuyerId,
    ): bool {
        $bandActive = $request->filled('score_min') || $request->filled('score_max');
        $matchedOnly = $request->filled('matched_only') && $request->matched_only === '1';
        $sortParam = $request->get('sort');
        $buyerImplicitSort = $selectedBuyerId !== null && !$request->filled('sort');
        $includeStockManager = $request->boolean('include_in_stock') && $isProspectingManager;

        if ($bandActive || $matchedOnly || $buyerImplicitSort || $includeStockManager) {
            return false;
        }
        if ($sortParam === 'buyer_matches' || $sortParam === 'match_score') {
            return false;
        }
        // Suburb sort's tie-break is NOT reproducible via an explicit id ASC
        // secondary key (proven 2026-08-23 — see config/prospecting.php).
        // Stays on the slow path until config('prospecting.suburb_sort_explicit_tiebreak')
        // is deliberately turned on.
        if ($sortParam === 'suburb' && !config('prospecting.suburb_sort_explicit_tiebreak')) {
            return false;
        }

        return true;
    }

    /**
     * Resolve exactly this page's REPRESENTATIVE listing ids, in the correct
     * final order, plus the grouped total and last page — without hydrating
     * anything beyond `id` for the full matching set.
     *
     * The group-key expression and its "which member wins the group" rule
     * are the SAME as the existing in-memory groupBy/map (property_group_id,
     * falling back to a per-row synthetic single-<id> key). "Wins" is
     * defined identically too: whichever member would sort FIRST under
     * `ORDER BY <sortCol> <dir>, id ASC` — the plain, ungrouped ordering —
     * decides both which row represents the group AND where the group
     * itself lands relative to every other group. That is mechanically
     * identical to what today's PHP does: sort all rows first, then group
     * (Collection::groupBy preserves the incoming order; a group's position
     * in the result is the position of whichever member appeared first).
     *
     * `id ASC` as the tiebreaker is not cosmetic — it is MySQL's actual
     * observed tie-break for every one of the 4 allowed sort columns under
     * this exact query shape (verified empirically 2026-08-23 against real
     * tie clusters up to 336 rows on `price`, 119 on `last_seen_at`/
     * `first_seen_at`, 1870 on `suburb` — none of the 4 sort columns come
     * close to being unique per row). It was NEVER a documented guarantee —
     * an accident of InnoDB's default scan order — so today's screen order
     * has been silently reproducible only by luck. Declaring it explicitly
     * here is a correctness fix in its own right, not just a rewrite
     * artefact: without it, ties in the group-resolution query could
     * legitimately return a DIFFERENT row on a re-run, which would shuffle
     * rows between pages under an agent's feet.
     *
     * @return array{ids: array<int>, total: int, lastPage: int}
     */
    public function resolvePage(Builder $filteredQuery, string $sortBy, string $sortDir, int $page, int $perPage): array
    {
        $sortBy = in_array($sortBy, self::ALLOWED_SORTS, true) ? $sortBy : 'last_seen_at';
        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';
        $groupKeyExpr = "COALESCE(property_group_id, CONCAT('single_', id))";

        $total = (int) (clone $filteredQuery)
            ->selectRaw("COUNT(DISTINCT {$groupKeyExpr}) as c")
            ->value('c');

        if ($total === 0) {
            return ['ids' => [], 'total' => 0, 'lastPage' => 1];
        }

        $lastPage = (int) max(1, ceil($total / $perPage));
        // Lower bound only — deliberately NOT clamped to $lastPage. A page
        // beyond the last must return EMPTY (LIMIT/OFFSET naturally does
        // this — MySQL returns 0 rows for an offset past the end, no
        // error), matching Collection::forPage()'s existing behaviour on
        // the slow path exactly. Clamping here would substitute the LAST
        // page's content for an out-of-range request — a real behaviour
        // change, not a preservation of today's.
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $ranked = (clone $filteredQuery)
            ->select([
                'id',
                $sortBy,
                DB::raw("{$groupKeyExpr} as group_key"),
                DB::raw(
                    "ROW_NUMBER() OVER (PARTITION BY {$groupKeyExpr} ORDER BY {$sortBy} {$sortDir}, id ASC) as rn"
                ),
            ]);

        $ids = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', 1)
            ->orderBy($sortBy, $sortDir)
            ->orderBy('id', 'asc')
            ->limit($perPage)
            ->offset($offset)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return ['ids' => $ids, 'total' => $total, 'lastPage' => $lastPage];
    }

    /**
     * Hydrate FULL rows for the given representative ids AND every sibling
     * sharing the same group (property_group_id, or the single-row
     * fallback), then run the EXISTING grouping/portals logic
     * (buildGroupedRows) against just that small set. Deliberately reuses
     * the SAME grouping code the slow path uses — see
     * MarketIntelligenceController::buildGroupedRows() — so this is a
     * pre-filtered INPUT change, not a second implementation of grouping
     * that could drift from the first.
     *
     * MUST fetch in the SAME order resolvePage() used to pick the winning
     * representative (`ORDER BY <sortCol> <dir>, id ASC`) — buildGroupedRows()
     * (the shared, unchanged grouping code) picks `$group->first()` as the
     * representative, exactly like the slow path does. Without this
     * ordering, `first()` picks whatever order MySQL happens to return an
     * unordered fetch in, which can — and, verified 2026-08-23, does —
     * disagree with the representative resolvePage() already determined
     * was correct, silently swapping WHICH sibling of a portal-duplicate
     * group represents it on the page.
     *
     * @param  array<int>  $representativeIds
     */
    public function hydrateGroups(Builder $unfilteredAgencyScopeQuery, array $representativeIds, string $sortBy, string $sortDir): Collection
    {
        if (empty($representativeIds)) {
            return collect();
        }
        $sortBy = in_array($sortBy, self::ALLOWED_SORTS, true) ? $sortBy : 'last_seen_at';
        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        $representatives = (clone $unfilteredAgencyScopeQuery)
            ->whereIn('id', $representativeIds)
            ->get(['id', 'property_group_id']);

        $groupIds = $representatives->pluck('property_group_id')->filter()->unique()->values()->all();
        $singleIds = $representatives->whereNull('property_group_id')->pluck('id')->all();

        return (clone $unfilteredAgencyScopeQuery)
            ->where(function ($q) use ($groupIds, $singleIds) {
                if (!empty($groupIds)) {
                    $q->orWhereIn('property_group_id', $groupIds);
                }
                if (!empty($singleIds)) {
                    $q->orWhereIn('id', $singleIds);
                }
            })
            ->orderBy($sortBy, $sortDir)
            ->orderBy('id', 'asc')
            ->get();
    }
}
