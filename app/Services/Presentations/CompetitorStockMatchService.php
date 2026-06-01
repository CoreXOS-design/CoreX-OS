<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Agency;
use App\Models\ContactMatch;
use App\Models\ListingStock;
use App\Models\Property;
use App\Services\PropertyMatchScoringService;
use App\Services\TitleTypeClassifier;
use App\Support\Presentations\SuburbMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Competitor Stock matcher for presentations.
 *
 * Reuses the Core Matches engine (PropertyMatchScoringService) by
 * synthesising a ContactMatch from the subject property's profile
 * and feeding it to scoreProspectingCapture() against
 * prospecting_listings candidates. NO engine change.
 *
 * Thresholds are agency-configurable (the standing rule — never
 * hardcoded):
 *   agencies.competitor_stock_default_beds_tolerance
 *   agencies.competitor_stock_default_price_tolerance_pct
 *   agencies.competitor_stock_min_score
 *
 * Returns an ordered Collection of matches with score, tier
 * (perfect/strong/approximate), per-component breakdown, and HFC-
 * owned enrichment (days_on_market + portal views from PropCon).
 *
 * Spec: built on Core Matches scoring; surface is the presentation
 * review screen's "Active Competition" section (parallel to comp
 * picker with included_competitor_ids_json on the version).
 */
final class CompetitorStockMatchService
{
    /**
     * Find competitor listings for a subject property.
     *
     * @return Collection<int, array{
     *   listing_id: int,
     *   address: ?string,
     *   suburb: ?string,
     *   property_type: ?string,
     *   bedrooms: ?int,
     *   bathrooms: ?int,
     *   property_size_m2: ?float,
     *   erf_size_m2: ?float,
     *   price: int,
     *   portal_url: ?string,
     *   agent_name: ?string,
     *   agency_name: ?string,
     *   thumbnail_path: ?string,
     *   first_seen_at: ?string,
     *   score: int,
     *   tier: string,
     *   breakdown: array,
     *   is_hfc_owned: bool,
     *   days_on_market: ?int,
     *   views: ?int,
     *   matches: ?int,
     * }>
     */
    public function findCompetitors(Property $subject, ?int $overrideMinScore = null): Collection
    {
        $criteria = $this->buildCriteria($subject);
        if ($criteria === null) {
            // Subject can't be processed (missing agency_id/price/suburb,
            // or title_type can't be classified — e.g. commercial subject).
            return collect();
        }

        $agency      = Agency::find($subject->agency_id);
        $threshold   = $overrideMinScore ?? (int) ($agency?->competitor_stock_min_score ?? 50);
        $minSameType = (int) ($agency?->competitor_stock_min_same_type ?? 5);

        $synthMatch = $this->buildSyntheticMatch($criteria);
        $candidates = $this->loadCandidates($criteria);
        if ($candidates->isEmpty()) {
            return collect();
        }

        $hfcStockMap = $this->loadHfcStockMap((int) $subject->agency_id);
        $scorer      = app(PropertyMatchScoringService::class);

        $subjectFamily = $criteria['family'];
        $subjectKind   = $criteria['subject_kind'];

        return $candidates->map(function (object $listing) use ($scorer, $synthMatch, $hfcStockMap, $criteria) {
            // PHP belt-and-braces: drop any candidate whose property_type
            // classifies outside the subject's Level-1 family. Catches
            // rows the SQL family-whereIn missed (new portal strings, mis-
            // cased values) — strict TITLE_OTHER drop semantics.
            $candidateFamily = $this->candidateFamilyFor($listing);
            if ($candidateFamily !== $criteria['family']) {
                return null;
            }
            return $this->scoreAndMapRow($listing, $scorer, $synthMatch, $hfcStockMap, $criteria);
        })
        ->filter(fn (?array $row) => $row !== null && $row['score'] >= $threshold)
        ->values()
        ->pipe(function (Collection $rows) use ($minSameType) {
            // STEP-UP fallback. When exact-kind matches are plentiful
            // (>= floor), restrict to them — keeps the section focused.
            // When they're sparse, widen to the whole Level-1 family
            // so the section isn't empty. NEVER reaches outside Level 1
            // (the gate above already enforced that).
            //
            // floor === 0 is the "disabled" signal — keep the full
            // family every time. Treating 0 as a literal threshold
            // would always trigger the restriction (since any count
            // is >= 0), which inverts the operator's intent.
            if ($minSameType <= 0) {
                return $rows;
            }
            $exact = $rows->where('level2_match', 'exact');
            if ($exact->count() >= $minSameType) {
                return $exact->values();
            }
            return $rows;
        })
        ->sortByDesc('score')
        ->values();
    }

    /**
     * Build the unified CRITERIA struct for a subject — the single
     * source of truth that both findCompetitors and the manual-picker
     * modal consume. Returns null when the subject can't be processed
     * (missing agency_id/price/suburb, or family can't be resolved).
     *
     * Centralising the criteria here means the modal opens pre-populated
     * to the EXACT filter values the auto-picker used. No drift between
     * "what was scored" and "what the modal showed".
     *
     * @return ?array{
     *   agency_id:int, subject:Property,
     *   suburb:string, suburb_core:string,
     *   property_type:?string, subject_kind:?string,
     *   family:string, family_types:string[],
     *   beds:?int, beds_min:?int, beds_max:?int,
     *   price:int, price_min:int, price_max:int,
     *   beds_tol:int, price_pct:int,
     * }
     */
    public function buildCriteria(Property $subject): ?array
    {
        if (!$subject->agency_id || !$subject->price || !$subject->suburb) {
            return null;
        }

        $family = $this->resolveSubjectFamily($subject);
        if ($family === null) {
            return null;
        }

        $agency   = Agency::find($subject->agency_id);
        $bedsTol  = (int) ($agency?->competitor_stock_default_beds_tolerance      ?? 1);
        $pricePct = (int) ($agency?->competitor_stock_default_price_tolerance_pct ?? 20);

        $price    = (int) $subject->price;
        $priceMin = (int) round($price * (1 - $pricePct / 100));
        $priceMax = (int) round($price * (1 + $pricePct / 100));

        $bedsMin = null;
        $bedsMax = null;
        if ($subject->beds !== null) {
            $bedsMin = max(0, (int) $subject->beds - $bedsTol);
            $bedsMax = (int) $subject->beds + $bedsTol;
        }

        return [
            'agency_id'     => (int) $subject->agency_id,
            'subject'       => $subject,
            'suburb'        => (string) $subject->suburb,
            'suburb_core'   => SuburbMatcher::normaliseSuburbToken((string) $subject->suburb),
            'property_type' => $subject->property_type ? (string) $subject->property_type : null,
            'subject_kind'  => $this->normalizeTypeKind($subject->property_type),
            'family'        => $family,
            'family_types'  => $this->familyPropertyTypeStrings($subject, $family),
            'beds'          => $subject->beds !== null ? (int) $subject->beds : null,
            'beds_min'      => $bedsMin,
            'beds_max'      => $bedsMax,
            'price'         => $price,
            'price_min'     => $priceMin,
            'price_max'     => $priceMax,
            'beds_tol'      => $bedsTol,
            'price_pct'     => $pricePct,
        ];
    }

    /**
     * Manual-picker search — the modal's backend. Same Level-1 hard gate
     * as loadCandidates (whereIn family_types + whereNotIn commercial/
     * industrial), but accepts agent-loosened filters on top (wider
     * price band, different suburb, looser beds, free-text search).
     * Scores every result via the same scoreProspectingCapture pipeline
     * so the modal can sort by score DESC and the cards render with the
     * same shape the review screen uses.
     *
     * The Level-1 family gate is NEVER loosened — even if the agent
     * submits a tampered request, the SQL whereIn + PHP belt-and-braces
     * keep cross-family stock out.
     *
     * User filters accepted (all optional):
     *   suburb        string  — exact suburb match (loose LIKE on the
     *                           normalised token, like the auto-picker)
     *   property_type string  — restrict to one specific family member
     *                           (e.g. "Apartment" only, no Townhouses)
     *   price_min     int     — agent-set floor
     *   price_max     int     — agent-set ceiling
     *   beds_min      int     — agent-set min beds
     *   beds_max      int     — agent-set max beds
     *   search        string  — LIKE on address/agent_name/agency_name
     *   limit         int     — cap result set (default 200; modal pages)
     *
     * @return Collection<int, array>
     */
    public function searchForManualPicker(Property $subject, array $userFilters = []): Collection
    {
        $criteria = $this->buildCriteria($subject);
        if ($criteria === null) {
            return collect();
        }

        // Agent-loosened filters override the auto-picker defaults but
        // never the family gate. Build a "customised criteria" for the
        // candidate query.
        $custom = $criteria;
        foreach (['suburb', 'price_min', 'price_max', 'beds_min', 'beds_max'] as $k) {
            if (array_key_exists($k, $userFilters) && $userFilters[$k] !== null && $userFilters[$k] !== '') {
                $custom[$k] = $k === 'suburb' ? (string) $userFilters[$k] : (int) $userFilters[$k];
            }
        }
        // Suburb override → recompute the core token used by the LIKE.
        if (($userFilters['suburb'] ?? null) !== null && $userFilters['suburb'] !== '') {
            $custom['suburb_core'] = SuburbMatcher::normaliseSuburbToken((string) $userFilters['suburb']);
        }
        // Property-type filter — restrict to one family member only.
        // Validated against the family set so a tampered value can't
        // smuggle in a cross-family type.
        $pickedType = $userFilters['property_type'] ?? null;
        if ($pickedType !== null && $pickedType !== '' && in_array((string) $pickedType, $custom['family_types'], true)) {
            $custom['picked_property_type'] = (string) $pickedType;
        }
        $custom['search_q'] = isset($userFilters['search']) ? trim((string) $userFilters['search']) : '';
        $custom['limit']    = max(1, min(500, (int) ($userFilters['limit'] ?? 200)));

        $candidates = $this->loadCandidates($custom);
        if ($candidates->isEmpty()) {
            return collect();
        }

        $synthMatch  = $this->buildSyntheticMatch($criteria);  // base (unloosened) synth — scoring rules stay anchored to subject
        $hfcStockMap = $this->loadHfcStockMap((int) $subject->agency_id);
        $scorer      = app(PropertyMatchScoringService::class);

        return $candidates->map(function (object $listing) use ($scorer, $synthMatch, $hfcStockMap, $criteria) {
            $candidateFamily = $this->candidateFamilyFor($listing);
            if ($candidateFamily !== $criteria['family']) {
                return null;
            }
            return $this->scoreAndMapRow($listing, $scorer, $synthMatch, $hfcStockMap, $criteria);
        })
        ->filter()
        ->sortByDesc('score')
        ->values();
    }

    /**
     * Score one prospecting_listings row + its in-memory proxy object
     * against an arbitrary subject, returning the same row shape used by
     * findCompetitors / searchForManualPicker / compileCompetitorStock.
     * Public so AnalysisDataService can score whitelist-only rows that
     * weren't in the auto-pool (decision B — agent-added rows beyond
     * the default price/suburb band).
     */
    public function scoreSingleListing(Property $subject, object $listing): ?array
    {
        $criteria = $this->buildCriteria($subject);
        if ($criteria === null) return null;

        $candidateFamily = $this->candidateFamilyFor($listing);
        if ($candidateFamily !== $criteria['family']) return null;

        $synthMatch  = $this->buildSyntheticMatch($criteria);
        $hfcStockMap = $this->loadHfcStockMap((int) $subject->agency_id);
        $scorer      = app(PropertyMatchScoringService::class);

        return $this->scoreAndMapRow($listing, $scorer, $synthMatch, $hfcStockMap, $criteria);
    }

    /**
     * Bulk score by listing IDs. Used by compileCompetitorStock to
     * resolve the UNION of (auto-pool) + (whitelist-only IDs that fall
     * OUTSIDE the auto-pool's default suburb / price / beds band).
     *
     * Returns scored rows for IDs that exist + pass the Level-1 family
     * gate. Cross-family or unknown IDs are silently dropped — defensive
     * against a stale whitelist row whose underlying prospecting_listing
     * was later reclassified or deleted.
     *
     * @param  int[]  $listingIds
     * @return Collection<int, array>
     */
    public function scoreListingsByIds(Property $subject, array $listingIds): Collection
    {
        $listingIds = array_values(array_unique(array_map('intval', $listingIds)));
        if (empty($listingIds)) return collect();

        $criteria = $this->buildCriteria($subject);
        if ($criteria === null) return collect();

        $rows = DB::table('prospecting_listings')
            ->where('agency_id', $subject->agency_id)
            ->whereIn('id', $listingIds)
            ->whereNull('deleted_at')
            ->whereNotIn(DB::raw('LOWER(property_type)'), ['commercial', 'industrial'])
            ->select([
                'id', 'address', 'suburb', 'price', 'bedrooms', 'bathrooms', 'garages',
                'property_size_m2', 'erf_size_m2', 'property_type',
                'latitude', 'longitude',
                'portal_url', 'portal_source', 'portal_ref',
                'agent_name', 'agency_name', 'thumbnail_path',
                'first_seen_at', 'last_seen_at',
            ])
            ->get();

        // Same eager geocode hook as the auto-pool path.
        $this->geocodeMissingCompetitionRows($rows);
        $rows = $rows->map(fn ($row) => $this->adaptCandidateRow($row));

        if ($rows->isEmpty()) return collect();

        $synthMatch  = $this->buildSyntheticMatch($criteria);
        $hfcStockMap = $this->loadHfcStockMap((int) $subject->agency_id);
        $scorer      = app(PropertyMatchScoringService::class);

        return $rows->map(function (object $listing) use ($scorer, $synthMatch, $hfcStockMap, $criteria) {
            $candidateFamily = $this->candidateFamilyFor($listing);
            if ($candidateFamily !== $criteria['family']) {
                return null;
            }
            return $this->scoreAndMapRow($listing, $scorer, $synthMatch, $hfcStockMap, $criteria);
        })
        ->filter()
        ->values();
    }

    /**
     * Build an unsaved ContactMatch from the criteria struct. The Core
     * Matches scorer reads attributes + p24SuburbIdList() +
     * propertyTypeList(); a freshly-instantiated ContactMatch with the
     * right attribute values exposes both methods unchanged (they read
     * $this->p24_suburb_ids and $this->property_types via the array
     * casts).
     *
     * `propertyTypeList` is set to the SET of same-family property_type
     * strings (NOT just the subject's literal value). This fixes the
     * literal-string brittleness — a subject "Sectional Title" no
     * longer fails to type-match a candidate "Apartment" just because
     * the strings differ. The scorer's `in_array` check now returns
     * true for any candidate in the Level-1 family → 10/10 type
     * points. Level-2 (exact-kind preference) is layered on top in
     * findCompetitors() via the +5 bonus, NOT here.
     *
     * No must-haves or deal-breakers — scoring is soft for the
     * competitor view (we're identifying "near competitors", not
     * filtering for a buyer's hard rules).
     */
    private function buildSyntheticMatch(array $criteria): ContactMatch
    {
        $subject = $criteria['subject'];

        $match = new ContactMatch();
        $match->agency_id = $criteria['agency_id'];
        $match->status    = ContactMatch::STATUS_ACTIVE;
        $match->price_min = $criteria['price_min'];
        $match->price_max = $criteria['price_max'];

        if ($criteria['beds_min'] !== null) {
            $match->beds_min     = $criteria['beds_min'];
            $match->bedrooms_max = $criteria['beds_max'];
        }
        if ($subject->p24_suburb_id) {
            $match->p24_suburb_ids = [(int) $subject->p24_suburb_id];
        }

        // Preferred types = Level-1 family set. Includes the subject's
        // own property_type string so a literal-match candidate
        // (subject=House → candidate=House) still satisfies in_array.
        $preferred = $criteria['family_types'];
        if ($criteria['property_type'] !== null && !in_array($criteria['property_type'], $preferred, true)) {
            $preferred[] = $criteria['property_type'];
        }
        if (!empty($preferred)) {
            $match->property_types = array_values($preferred);
            $match->property_type  = $criteria['property_type'] ?? $preferred[0];
        }
        // Soft scoring — leave must_have_features + deal_breakers empty.
        $match->must_have_features = [];
        $match->deal_breakers      = [];

        return $match;
    }

    /**
     * Resolve the subject's Level-1 family. Returns:
     *   'sectional' for sectional_title
     *   'freehold'  for full_title OR vacant_land
     *   null        when classification fails or the subject is not
     *               residential (commercial/industrial) — caller emits
     *               an empty result rather than mismatching.
     */
    private function resolveSubjectFamily(Property $subject): ?string
    {
        $titleType = $subject->title_type
            ?? app(TitleTypeClassifier::class)->forProperty($subject);
        if ($titleType === TitleTypeClassifier::TITLE_SECTIONAL) return 'sectional';
        if ($titleType === TitleTypeClassifier::TITLE_FULL)      return 'freehold';
        if ($titleType === TitleTypeClassifier::TITLE_VACANT)    return 'freehold';
        return null;
    }

    /**
     * Same family classification, but for a candidate row pulled from
     * prospecting_listings. Goes through TitleTypeClassifier so the
     * existing keyword heuristic (apartment / townhouse / flat / etc.)
     * is the single source of truth — same path used by MicSnapshotHydrator
     * for sold comps. Returns null on unrecognised property_type so the
     * caller can drop the row (strict TITLE_OTHER drop semantic).
     */
    private function candidateFamilyFor(object $listing): ?string
    {
        $raw = $listing->property_type ?? null;
        if ($raw === null) return null;
        $kind = app(TitleTypeClassifier::class)->fromPropertyType((string) $raw);
        if ($kind === TitleTypeClassifier::TITLE_SECTIONAL) return 'sectional';
        if ($kind === TitleTypeClassifier::TITLE_FULL)      return 'freehold';
        if ($kind === TitleTypeClassifier::TITLE_VACANT)    return 'freehold';
        return null;
    }

    /**
     * Set of prospecting_listings.property_type strings that fall into
     * the subject's Level-1 family. Computed dynamically by classifying
     * every distinct value currently in the table — handles future
     * portal strings automatically without code changes. Always
     * includes the subject's own property_type (covers the case where
     * the subject's value is the only one of its kind locally).
     *
     * @return string[]
     */
    private function familyPropertyTypeStrings(Property $subject, string $family): array
    {
        $distinct = DB::table('prospecting_listings')
            ->where('agency_id', $subject->agency_id)
            ->whereNotNull('property_type')
            ->distinct()
            ->pluck('property_type')
            ->all();

        $classifier = app(TitleTypeClassifier::class);
        $out = [];
        foreach ($distinct as $str) {
            $kind = $classifier->fromPropertyType((string) $str);
            $candidateFamily = match ($kind) {
                TitleTypeClassifier::TITLE_SECTIONAL => 'sectional',
                TitleTypeClassifier::TITLE_FULL      => 'freehold',
                TitleTypeClassifier::TITLE_VACANT    => 'freehold',
                default                              => null,
            };
            if ($candidateFamily === $family) {
                $out[] = (string) $str;
            }
        }

        // Always include the subject's own property_type so a literal
        // match in the scorer's `in_array` works even when the local
        // prospecting pool has zero rows with that exact string.
        if (!empty($subject->property_type) && !in_array($subject->property_type, $out, true)) {
            $out[] = (string) $subject->property_type;
        }
        return array_values(array_unique($out));
    }

    /**
     * Map a free-text property_type string to a finer-grained kind so
     * Level-2 exact-kind preference works regardless of literal string
     * casing or punctuation. Apartment / Townhouse / House / Farm /
     * Vacant — sits between TitleTypeClassifier's three buckets and the
     * raw varchar(50) column.
     *
     * Order matters: "townhouse" is checked BEFORE "house" because
     * str_contains('townhouse', 'house') === true.
     */
    private function normalizeTypeKind(?string $raw): ?string
    {
        if ($raw === null) return null;
        $t = strtolower(trim($raw));
        if ($t === '') return null;
        if (str_contains($t, 'apartment') || str_contains($t, 'flat')
            || str_contains($t, 'sectional') || $t === 'unit') {
            return 'apartment';
        }
        if (str_contains($t, 'townhouse') || str_contains($t, 'duplex')) {
            return 'townhouse';
        }
        if (str_contains($t, 'house')) {
            return 'house';
        }
        if (str_contains($t, 'farm') || str_contains($t, 'smallhold')) {
            return 'farm';
        }
        if (str_contains($t, 'vacant') || str_contains($t, 'plot')
            || str_contains($t, 'stand') || str_contains($t, 'erf') || $t === 'land') {
            return 'vacant';
        }
        return 'other';
    }

    /**
     * Recompute the tier label after the Level-2 bonus has been added —
     * keep parity with PropertyMatchScoringService::determineTier
     * boundaries so the rich-card colour palette stays consistent.
     */
    private function retier(int $score): string
    {
        if ($score >= 90) return 'perfect';
        if ($score >= 70) return 'strong';
        if ($score >= 50) return 'approximate';
        return 'none';
    }

    /**
     * Per-row scoring + mapping. Extracted from findCompetitors so
     * searchForManualPicker and scoreSingleListing (decision B —
     * whitelist-only IDs scored as a union) share the same shape.
     * Caller has already enforced the family gate.
     *
     * @return array  row shape consumed by the review screen +
     *                CoreXBuildListingCard helper.
     */
    private function scoreAndMapRow(
        object $listing,
        PropertyMatchScoringService $scorer,
        ContactMatch $synthMatch,
        array $hfcStockMap,
        array $criteria,
    ): array {
        $result = $scorer->scoreProspectingCapture($synthMatch, $listing);

        // LEVEL 2 — preference bonus for exact subject-kind match.
        // Scorer already gives 10/10 type points to anything in the
        // family set (synth match's propertyTypeList = familyTypes).
        // The Level-2 bonus lifts exact-kind matches above same-
        // family-other-kind so apartments rank above townhouses for
        // an apartment subject. Capped at 100 total.
        $candidateKind = $this->normalizeTypeKind($listing->property_type ?? null);
        $isExactKind = $criteria['subject_kind'] !== null && $candidateKind === $criteria['subject_kind'];
        if ($isExactKind) {
            $result['score'] = min(100, (int) $result['score'] + 5);
            $result['tier']  = $this->retier((int) $result['score']);
        }

        $stock = $hfcStockMap[$this->stockKey($listing)] ?? null;

        // Rich-card additions — thumbnail served via the existing
        // corex.market.thumbnail route for the review screen, plus
        // the absolute local-file path so DomPDF renders without
        // a remote fetch.
        $thumbPath = $listing->thumbnail_path ?? null;
        $thumbUrl  = null;
        $thumbAbs  = null;
        if ($thumbPath) {
            try {
                $thumbUrl = route('corex.market.thumbnail', ['listing' => $listing->id]);
            } catch (\Throwable) {
                $thumbUrl = null;
            }
            try {
                $candidate = Storage::disk('local')->path($thumbPath);
                if (is_file($candidate)) $thumbAbs = $candidate;
            } catch (\Throwable) {
                $thumbAbs = null;
            }
        }

        return [
            'listing_id'       => (int) $listing->id,
            'address'          => $listing->address ?? null,
            'suburb'           => $listing->suburb ?? null,
            'property_type'    => $listing->property_type ?? null,
            // CMA-map — surface lat/lng on the result so review +
            // PDF can plot. null when the row is still unresolvable
            // (no address or resolver failed); the map's "X of Y
            // plotted · Z no location" caption surfaces the residual.
            'latitude'         => isset($listing->latitude)  && $listing->latitude  !== null ? (float) $listing->latitude  : null,
            'longitude'        => isset($listing->longitude) && $listing->longitude !== null ? (float) $listing->longitude : null,
            'bedrooms'         => $listing->beds ?? null,
            'bathrooms'        => isset($listing->bathrooms) ? (int) $listing->bathrooms : null,
            'garages'          => isset($listing->garages) ? (int) $listing->garages : null,
            'property_size_m2' => isset($listing->property_size_m2) && $listing->property_size_m2 !== null
                ? (float) $listing->property_size_m2 : null,
            'erf_size_m2'      => isset($listing->erf_size_m2) && $listing->erf_size_m2 !== null
                ? (float) $listing->erf_size_m2 : null,
            'price'            => (int) $listing->price,
            'portal_url'       => $listing->portal_url ?? null,
            'portal_ref'       => $listing->portal_ref ?? null,
            'agent_name'       => $listing->agent_name ?? null,
            'agency_name'      => $listing->agency_name ?? null,
            'thumbnail_path'   => $thumbPath,
            'thumbnail_url'    => $thumbUrl,
            'thumbnail_abs_path' => $thumbAbs,
            'first_seen_at'    => $listing->first_seen_at ?? null,
            'score'            => (int) $result['score'],
            'tier'             => (string) $result['tier'],
            'breakdown'        => $result['breakdown'] ?? [],
            'level2_match'     => $isExactKind ? 'exact' : 'family',
            'is_hfc_owned'     => $stock !== null,
            'days_on_market'   => $stock ? $this->intOrNull($stock->days_on_market) : null,
            'views'            => $stock ? $this->extractPayloadInt($stock, ['Views', 'views', 'Portal Views', 'portal views', 'PortalViews']) : null,
            'matches'          => $stock ? $this->extractPayloadInt($stock, ['Matches', 'matches', 'Buyer Matches', 'buyer matches', 'BuyerMatches']) : null,
        ];
    }

    /**
     * Pull prospecting_listings candidates within the price band and
     * loose suburb match. Beds tolerance + the full scoring run as
     * the PHP-side narrow; SQL is conservative-but-broad so the
     * engine sees every plausible row.
     *
     * SQL applies the LEVEL 1 HARD GATE — `whereIn(property_type, $familyTypes)`
     * — so freehold/sectional crossover never reaches the scorer.
     * Commercial/Industrial are always excluded (residential subjects
     * never match non-residential stock — see CompetitorStockMatchService
     * docblock). The PHP-side belt-and-braces (candidateFamilyFor) in
     * findCompetitors handles any edge case the SQL missed.
     *
     * @param  string[]  $familyTypes  Level-1 family property_type strings.
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function loadCandidates(array $criteria): \Illuminate\Support\Collection
    {
        $coreLike = $criteria['suburb_core'] !== '' ? '%' . $criteria['suburb_core'] . '%' : '%';

        $query = DB::table('prospecting_listings')
            ->where('agency_id', $criteria['agency_id'])
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->whereBetween('price', [$criteria['price_min'], $criteria['price_max']])
            ->whereRaw('LOWER(suburb) LIKE ?', [$coreLike]);

        // LEVEL 1 — SQL HARD GATE. family_types is computed dynamically
        // from the live distinct values; subjects ALWAYS get at least
        // their own property_type in the list (see familyPropertyTypeStrings).
        // This is the architectural invariant — never crossed, never
        // loosened, even when the modal widens other filters.
        if (!empty($criteria['family_types'])) {
            $query->whereIn('property_type', $criteria['family_types']);
        }

        // Hard-exclude non-residential stock so a residential subject
        // can NEVER match Commercial/Industrial. SQL safety net; the
        // PHP gate in findCompetitors / searchForManualPicker is the
        // belt-and-braces.
        $query->whereNotIn(DB::raw('LOWER(property_type)'), ['commercial', 'industrial']);

        // Beds clamp — only apply when the criteria says so. The auto-
        // picker leaves beds_min/max null when the subject has no beds
        // (vacant land); the modal can also send loose beds bounds.
        if (isset($criteria['beds_min']) && $criteria['beds_min'] !== null) {
            $query->where(function ($q) use ($criteria) {
                $q->whereNull('bedrooms')->orWhere('bedrooms', '>=', $criteria['beds_min']);
            });
        }
        if (isset($criteria['beds_max']) && $criteria['beds_max'] !== null) {
            $query->where(function ($q) use ($criteria) {
                $q->whereNull('bedrooms')->orWhere('bedrooms', '<=', $criteria['beds_max']);
            });
        }

        // Manual-picker single-type filter: agent restricts to one
        // family member (e.g. "Apartment" only inside the sectional
        // family). Validated upstream against family_types so a tampered
        // value can't cross-class.
        if (!empty($criteria['picked_property_type'])) {
            $query->where('property_type', $criteria['picked_property_type']);
        }

        // Manual-picker free-text search (LIKE on address / agent / agency).
        if (!empty($criteria['search_q'])) {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $criteria['search_q']) . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('address', 'like', $needle)
                  ->orWhere('agent_name', 'like', $needle)
                  ->orWhere('agency_name', 'like', $needle)
                  ->orWhere('suburb', 'like', $needle);
            });
        }

        if (!empty($criteria['limit'])) {
            $query->limit($criteria['limit']);
        }

        $rows = $query
            ->select([
                'id', 'address', 'suburb', 'price', 'bedrooms', 'bathrooms', 'garages',
                'property_size_m2', 'erf_size_m2', 'property_type',
                'latitude', 'longitude',
                'portal_url', 'portal_source', 'portal_ref',
                'agent_name', 'agency_name', 'thumbnail_path',
                'first_seen_at', 'last_seen_at',
            ])
            ->get();

        // Eager geocode hook — prospecting_listings starts with 100%
        // address but 0% GPS. Resolve any row that has an address but
        // no coords via AddressResolverService and persist back to the
        // new latitude/longitude columns. The map plots the resolved
        // result; future presentations read the column directly.
        //
        // Bounded by the per-candidate-pool count (already narrowed by
        // price band + suburb LIKE + family whereIn so typically <100
        // rows per call). Cache + cache-as-failed prevents repeated
        // Google calls for unresolvable addresses.
        $this->geocodeMissingCompetitionRows($rows);

        // Adapt each row to the loose shape scoreProspectingCapture
        // expects (price / suburb / property_type / beds; everything
        // else passes through for the card).
        return $rows->map(fn ($row) => $this->adaptCandidateRow($row));
    }

    /**
     * For each loaded candidate row that has an address but no GPS,
     * resolve via AddressResolverService and persist the result back
     * to prospecting_listings.latitude/longitude. Mutates the row
     * objects in place so downstream adaptCandidateRow + scoreAndMapRow
     * see the fresh coords.
     */
    private function geocodeMissingCompetitionRows(\Illuminate\Support\Collection $rows): void
    {
        $resolver = null;
        foreach ($rows as $row) {
            $hasGps = $row->latitude !== null && $row->longitude !== null
                && (float) $row->latitude !== 0.0 && (float) $row->longitude !== 0.0;
            if ($hasGps) continue;
            if (empty($row->address)) continue;

            $resolver ??= new \App\Services\Geocoding\AddressResolverService();
            try {
                $result = $resolver->resolve(
                    (string) $row->address,
                    $row->suburb ?: null,
                    null,
                    context: 'prospecting_listing:' . (int) $row->id,
                );
                if ($result->hasGps()) {
                    $row->latitude  = $result->latitude;
                    $row->longitude = $result->longitude;
                    DB::table('prospecting_listings')
                        ->where('id', (int) $row->id)
                        ->update([
                            'latitude'  => $result->latitude,
                            'longitude' => $result->longitude,
                            'updated_at'=> now(),
                        ]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::debug('competition row geocode failed', [
                    'listing_id' => $row->id ?? null,
                    'err'        => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Convert a stdClass row from DB::table('prospecting_listings') into
     * the in-memory shape the scorer (via wrapCaptureAsProperty) + the
     * card mapper expect. Centralised so loadCandidates AND
     * scoreListingsByIds produce identical objects.
     */
    private function adaptCandidateRow(object $row): object
    {
        return (object) [
            'id'               => (int) $row->id,
            'price'            => (int) $row->price,
            'suburb'           => $row->suburb,
            'property_type'    => $row->property_type,
            'latitude'         => $row->latitude  !== null ? (float) $row->latitude  : null,
            'longitude'        => $row->longitude !== null ? (float) $row->longitude : null,
            'beds'             => $row->bedrooms !== null ? (int) $row->bedrooms : null,
            'bedrooms'         => $row->bedrooms !== null ? (int) $row->bedrooms : null,
            'bathrooms'        => $row->bathrooms !== null ? (int) $row->bathrooms : null,
            'garages'          => $row->garages   !== null ? (int) $row->garages   : null,
            'property_size_m2' => $row->property_size_m2,
            'erf_size_m2'      => $row->erf_size_m2,
            'address'          => $row->address,
            'portal_url'       => $row->portal_url,
            'portal_source'    => $row->portal_source,
            'portal_ref'       => $row->portal_ref,
            'agent_name'       => $row->agent_name,
            'agency_name'      => $row->agency_name,
            'thumbnail_path'   => $row->thumbnail_path,
            'first_seen_at'    => $row->first_seen_at,
            'last_seen_at'     => $row->last_seen_at,
            'features_json'    => null,
            // The wrapper sets `p24_suburb_id` to null — scorer falls
            // through to its no-signal default for the area branch
            // when missing on the candidate. Acceptable; we still
            // get price/beds/type signal.
        ];
    }

    /**
     * Load HFC's PropCon stock keyed by portal_ref AND external_id so
     * a prospecting_listings row can be enriched with days_on_market
     * + views. Uses the Eloquent model so the `days_on_market`
     * accessor (computed from listed_at / created_at) resolves.
     * Same join shape PropConInsightsService uses.
     *
     * @return array<string, ListingStock>
     */
    private function loadHfcStockMap(int $agencyId): array
    {
        $rows = ListingStock::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('source', 'propcon')
            ->whereNull('deleted_at')
            ->get(['id', 'external_id', 'external_ref', 'listed_at', 'created_at', 'raw_payload', 'status']);

        $map = [];
        foreach ($rows as $r) {
            $ref = (string) ($r->external_ref ?? '');
            $ext = (string) ($r->external_id  ?? '');
            if ($ref !== '') $map['ref:' . $ref] = $r;
            if ($ext !== '') $map['ext:' . $ext] = $r;
        }
        return $map;
    }

    private function stockKey(object $listing): string
    {
        // Prefer portal_ref (P24/PP listing id) — matches PropCon's
        // external_ref / external_id key shape.
        if (isset($listing->portal_ref) && $listing->portal_ref !== null && $listing->portal_ref !== '') {
            return 'ref:' . (string) $listing->portal_ref;
        }
        return 'ref:__none__';
    }

    /**
     * Pluck the first matching integer key from a listing_stocks
     * raw_payload JSON column. Mirrors PropConInsightsService's
     * extractPayloadInt — same payload shape, same key aliases.
     */
    private function extractPayloadInt(object $stockRow, array $aliases): ?int
    {
        $raw = is_string($stockRow->raw_payload)
            ? json_decode($stockRow->raw_payload, true)
            : (array) ($stockRow->raw_payload ?? []);
        if (!is_array($raw)) return null;

        foreach ($aliases as $k) {
            if (isset($raw[$k]) && is_numeric($raw[$k])) {
                return (int) $raw[$k];
            }
        }
        return null;
    }

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (int) $v;
        return null;
    }
}
