<?php

namespace App\Services;

// TODO(matcher-unification): see backlog ticket — both PropertyMatchScoringService and MatchingService
// currently exist as parallel engines. Both read from ContactMatch post-2026-05-13. Future work merges them.

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\PropertyBuyerMatch;
use App\Models\ProspectingBuyerMatch;
use App\Models\ProspectingListing;
use App\Models\AgencyContactSettings;
use App\Services\Matching\MatchingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Scores properties (and prospecting captures) against buyer wishlists.
 * Reads criteria from ContactMatch (post-2026-05-13 unification). Preapproval
 * read from the Contact pillar via $match->contact (spec D3).
 *
 * Weighted scoring (max 100):
 *   - Price          25
 *   - Area / suburb  20
 *   - Property type  10
 *   - Must-haves     15
 *   - Deal-breakers  10  (hard-excludes the property if any deal-breaker is present — spec D5)
 *   - Bedrooms       20  (hard-excludes outside [beds_min, bedrooms_max] — spec D4)
 *
 * Threshold to write a cached match row: score >= 50. Tier boundaries
 * (AT-73 — aligned to the canonical Core Matches/Path 1 thresholds):
 * perfect 90+, strong 80-89, approximate 50-79.
 *
 * Multi-wishlist-per-contact handling: a contact may have multiple active
 * ContactMatch rows. The match tables enforce UNIQUE(target, contact_id),
 * so we cache only the BEST score across the contact's active matches per
 * (target, contact). The breakdown / matched_features snapshot stored is
 * from the winning match. Future work (matcher-unification ticket) may
 * lift this restriction with a contact_match_id column on the match tables.
 */
class PropertyMatchScoringService
{
    public const MIN_SCORE_TO_CACHE = 50;

    /**
     * AT-74 — buyer_state values treated as "active / working" (looking now)
     * for the seller-facing presentation ACTIVE split. cold/lost are historic,
     * not active. (Johan's pillar doctrine: active = looking now.)
     */
    public const ACTIVE_BUYER_STATES = ['new', 'warm'];

    /**
     * True while RegenerateBuyerMatchesJob is rebuilding the cache tables.
     * Consumers (prospecting tab, demand-intelligence widgets) check this
     * to show a "rebuilding" banner instead of stale counts.
     *
     * Spec: .ai/specs/unified-buyer-wishlist-spec.md Section 9 (D7).
     */
    public function isRegenerating(): bool
    {
        return (bool) Cache::get('corex.matches.regenerating', false);
    }

    /* =========================================================
     |  Scoring
     * ========================================================= */

    public function calculateScore(ContactMatch $match, Property $property): array
    {
        // Eager-load the contact relation so preapproval reads do not N+1 the caller.
        $match->loadMissing('contact');

        // AT-71 — an uncountable (empty / below-bar) wishlist scores 0 and is
        // therefore never cached (< MIN_SCORE_TO_CACHE), excluding it from every
        // cached surface. This also neutralises the no-signal scoring defaults
        // (price 20 / area 15 / type 8 / must-have 12 / deal-breaker 10 /
        // bedrooms 20 ≈ 85 "strong") for empty wishlists — they never reach the
        // scorers. Genuinely-partial-but-countable wishlists still score via the
        // no-signal defaults, which is intentional (the % handles partial fit).
        if (!$match->isCountable()) {
            return $this->failedResult('uncountable-wishlist');
        }

        // Hard filters — return 0 immediately on any failure (spec D4, D5).
        if ($this->violatesBedroomFilter($match, $property)) {
            return $this->failedResult('out-of-range-bedrooms');
        }
        if ($this->violatesDealBreakers($match, $property)) {
            return $this->failedResult('deal-breaker-present');
        }

        $score     = 0;
        $breakdown = [];
        $missing   = [];

        // Price (25 max).
        $priceScore = $this->scorePrice($match, $property);
        $breakdown['price'] = $priceScore;
        $score += $priceScore['points'];
        if ($priceScore['points'] < 25 && $priceScore['gap']) {
            $missing[] = $priceScore['gap'];
        }

        // Area / suburb (20 max).
        $areaScore = $this->scoreArea($match, $property);
        $breakdown['area'] = $areaScore;
        $score += $areaScore['points'];
        if ($areaScore['points'] < 20 && $areaScore['gap']) {
            $missing[] = $areaScore['gap'];
        }

        // Property type (10 max). Uses propertyTypeList() so legacy property_type
        // is honoured when property_types is null (spec D2 deprecation window).
        $typeScore = $this->scorePropertyType($match, $property);
        $breakdown['type'] = $typeScore;
        $score += $typeScore['points'];

        // Must-have features (15 max). Property-side feature check stays a soft
        // proportion against features_json (no hard filter). The current code
        // gave a generous fallback when no features signal existed — that is
        // preserved here; a tighter must-have check is out of scope for this prompt.
        $featureScore = $this->scoreMustHaves($match, $property);
        $breakdown['features'] = $featureScore;
        $score += $featureScore['points'];
        if (!empty($featureScore['missing'])) {
            $missing = array_merge($missing, $featureScore['missing']);
        }

        // Deal-breakers (10 max). Hard-exclusion is handled above; reaching here
        // means no deal-breakers were present on the property → full points.
        $breakerScore = $this->scoreDealBreakers($match, $property);
        $breakdown['deal_breakers'] = $breakerScore;
        $score += $breakerScore['points'];

        // Bedrooms (20 max). Hard-filter is handled above; reaching here means
        // the property is within [beds_min, bedrooms_max] (or both are null).
        $bedScore = $this->scoreBedrooms($match, $property);
        $breakdown['bedrooms'] = $bedScore;
        $score += $bedScore['points'];

        $total = min(100, $score);

        return [
            'score'            => $total,
            'tier'             => $this->determineTier($total),
            'breakdown'        => $breakdown,
            'missing_features' => array_values(array_filter($missing)),
        ];
    }

    /**
     * Score a prospecting capture (off-market portal listing) against a wishlist.
     * Wraps the capture's fields onto an in-memory Property so all the existing
     * scorers work unchanged.
     */
    public function scoreProspectingCapture(ContactMatch $match, object $capture): array
    {
        $proxy = $this->wrapCaptureAsProperty($capture);
        return $this->calculateScore($match, $proxy);
    }

    /* =========================================================
     |  Read paths consumed by callers
     * ========================================================= */

    public function getMatchesForBuyer(int $contactId, ?string $tier = null, int $limit = 20): Collection
    {
        $query = DB::table('property_buyer_matches')
            ->where('contact_id', $contactId)
            ->where('score', '>=', self::MIN_SCORE_TO_CACHE)
            ->orderByDesc('score');

        if ($tier) {
            $query->where('tier', $tier);
        }

        return $query->limit($limit)->get();
    }

    public function getMatchesForProperty(int $propertyId, int $limit = 20): Collection
    {
        return DB::table('property_buyer_matches')
            ->where('property_id', $propertyId)
            ->where('score', '>=', self::MIN_SCORE_TO_CACHE)
            ->orderByDesc('score')
            ->limit($limit)
            ->get();
    }

    /**
     * Buyer demand summary for an internal property (used by Presentations).
     * Counts DISTINCT contacts. A buyer with multiple matching wishlists
     * counts once.
     */
    public function getBuyerDemandForProperty(int $propertyId, int $agencyId): array
    {
        $property = Property::withoutGlobalScopes()->find($propertyId);
        if (!$property) {
            return $this->emptyDemand();
        }

        // ── THIS-PROPERTY ACTIVE buyers ────────────────────────────────────
        // Scored by the CANONICAL engine (Path 1, AT-73 — the same engine the
        // Core Matches tab uses), deduped per buyer (best score), floored at the
        // canonical display floor, restricted to buyers whose state is
        // active/working (looking now). The AT-71 countable gate is inherited
        // from matchesForProperty(). This replaces the old property_buyer_matches
        // (Engine B) read so the seller panel and Core Matches never disagree.
        // AT-144 — the canonical active-buyer set is computed ONCE (contact_id =>
        // [score,tier], score-desc) and shared by this demand array (count +
        // anonymised) and the auditable basis accessor below, so the seller-outreach
        // snapshot records the EXACT buyers behind the claimed count.
        $activeMap        = $this->activeCanonicalBuyersForProperty($property);
        $activeContactIds = array_keys($activeMap);
        $activeBuyers     = array_values($activeMap); // already score-desc

        // ── THIS-PROPERTY HISTORIC buyers ──────────────────────────────────
        // Buyers who physically engaged THIS property before (a buyer_property_views
        // row — derived from calendar_event_feedback) but are NOT currently an
        // active match. Honest per-property "past interest", never area demand.
        $historicCount = DB::table('buyer_property_views as v')
            ->join('contacts as c', 'c.id', '=', 'v.contact_id')
            ->where('v.property_id', $propertyId)
            ->where('c.agency_id', $agencyId)
            ->where('c.is_buyer', 1)
            ->whereNull('c.deleted_at')
            ->when(!empty($activeContactIds), fn ($q) => $q->whereNotIn('c.id', $activeContactIds))
            ->distinct()
            ->count('c.id');

        // Pre-approved buyers (per spec D3 — preapproval lives on Contact).
        // Counted: agency buyers with a non-expired preapproval >= property price
        // AND at least one active ContactMatch (otherwise they're not in the buyer pool).
        $preapprovedCount = 0;
        if ($property && $property->price) {
            $q = DB::table('contacts as c')
                ->join('contact_matches as cm', 'cm.contact_id', '=', 'c.id')
                ->where('c.agency_id', $agencyId)
                ->where('c.is_buyer', 1)
                ->whereNull('c.deleted_at')
                ->whereNull('cm.deleted_at')
                ->where('cm.status', ContactMatch::STATUS_ACTIVE)
                ->whereNotNull('c.preapproval_amount')
                ->where('c.preapproval_amount', '>=', $property->price)
                ->where(function ($w) {
                    $w->whereNull('c.preapproval_expires_at')
                      ->orWhere('c.preapproval_expires_at', '>=', Carbon::today()->toDateString());
                });
            ContactMatch::applyCountableSql($q, $agencyId, 'cm.'); // AT-71 — empty wishlists are not real buyers
            $preapprovedCount = $q->distinct()->count('c.id');
        }

        // Area buyers — distinct contacts whose any active wishlist covers this
        // property's area. AT-71 fix: the legacy `cm.suburb` column was DROPPED
        // (2026_05_20_100001); match on the CANONICAL p24_suburb_ids (by the
        // property's p24_suburb_id) with the derived `suburbs` name list as a
        // fallback for records lacking P24 ids.
        $areaBuyers = 0;
        if ($property && $property->suburb) {
            $q = DB::table('contacts as c')
                ->join('contact_matches as cm', 'cm.contact_id', '=', 'c.id')
                ->where('c.agency_id', $agencyId)
                ->where('c.is_buyer', 1)
                ->whereNull('c.deleted_at')
                ->whereNull('cm.deleted_at')
                ->where('cm.status', ContactMatch::STATUS_ACTIVE)
                ->where(function ($w) use ($property) {
                    if ($property->p24_suburb_id) {
                        $w->whereRaw('JSON_CONTAINS(COALESCE(cm.p24_suburb_ids, JSON_ARRAY()), ?)', [(string) (int) $property->p24_suburb_id]);
                    }
                    $w->orWhereRaw("JSON_SEARCH(cm.suburbs, 'one', ?) IS NOT NULL", [$property->suburb]);
                });
            ContactMatch::applyCountableSql($q, $agencyId, 'cm.'); // AT-71
            $areaBuyers = $q->distinct()->count('c.id');
        }

        $activeCount = count($activeBuyers);

        return [
            // (this-property active) — canonical engine, buyer_state new/warm.
            'active' => [
                'count'             => $activeCount,
                'anonymised_buyers' => collect($activeBuyers)->take(5)->values()->map(fn ($b, $i) => [
                    'label' => 'Buyer ' . ($i + 1),
                    'score' => $b['score'],
                    'tier'  => $b['tier'],
                ])->toArray(),
            ],
            // (this-property historic) — prior engagement on THIS property.
            'historic' => [
                'count' => $historicCount,
            ],
            // (area demand) — the wider area / price band. NEVER labelled as
            // "buyers for this property"; rendered under its own explicit heading.
            'area' => [
                'suburb'            => $property->suburb,
                'area_buyers'       => $areaBuyers,
                'preapproved_count' => $preapprovedCount,
            ],
            'has_property_demand' => ($activeCount + $historicCount) > 0,
        ];
    }

    /**
     * AT-145 — the CANONICAL count of DISTINCT active buyers (buyer_state
     * new/warm) whose countable wishlist matches THIS property. This is the one
     * number a seller-outreach buyer-claim may state ("I have N buyers active
     * and looking for a property like yours"): it is the same figure the Core
     * Matches tab and the presentation seller panel show, because it is a thin
     * accessor over getBuyerDemandForProperty()['active']['count'] — which runs
     * the canonical MatchingService engine, inherits the AT-71 ->countable()
     * gate (honouring the agency's min_countable_criteria, never hardcoded),
     * dedupes per buyer, floors at MIN_SCORE_TO_DISPLAY, and excludes cold/lost
     * (active-vs-historic honesty). Kept as its own method so the composer never
     * reaches into the demand array's shape, and no matching logic is
     * reimplemented anywhere.
     */
    public function countableActiveBuyerCountForProperty(Property $property): int
    {
        if (!$property->id) {
            return 0;
        }
        $demand = $this->getBuyerDemandForProperty((int) $property->id, (int) $property->agency_id);
        return (int) ($demand['active']['count'] ?? 0);
    }

    /**
     * AT-144 — the canonical, countable, ACTIVE-state buyer set matching a property,
     * as an ordered map contact_id => ['score'=>int,'tier'=>string] (highest first).
     * SINGLE source for both getBuyerDemandForProperty()['active'] and the auditable
     * basis accessor — no matching logic reimplemented: it reads the canonical
     * MatchingService::matchesForProperty() (AT-71 ->countable() gate inherited),
     * dedupes per buyer, floors at MIN_SCORE_TO_DISPLAY, keeps only buyer_state
     * new/warm. Kept PRIVATE so buyer contact_ids never leak into a seller-facing
     * return (getBuyerDemandForProperty still exposes only count + anonymised).
     *
     * @return array<int, array{score:int, tier:string}>
     */
    private function activeCanonicalBuyersForProperty(Property $property): array
    {
        $matcher = app(MatchingService::class);
        $canonical = $matcher->matchesForProperty($property)
            ->filter(fn ($m) => (int) $m->match_score >= MatchingService::MIN_SCORE_TO_DISPLAY)
            // AT-289 — HARD suburb scope for the per-property DEMAND CLAIM. A buyer
            // whose wishlist explicitly targets OTHER suburbs is not demand for THIS
            // property's location, even if price+beds+type carry them over the score
            // floor. Open (no suburb list) or includes-this-suburb only. The global
            // browse engine (matchesForProperty direct callers) keeps its soft suburb
            // score; only this per-property claim figure is gated. See AT-289.
            ->filter(fn ($m) => $matcher->suburbCompatible($property, $m))
            ->groupBy('contact_id')
            ->map(fn ($g) => $g->sortByDesc('match_score')->first());

        $map = [];
        foreach ($canonical as $m) {
            $contact = $m->contact;
            if (!$contact || !in_array($contact->buyer_state, self::ACTIVE_BUYER_STATES, true)) {
                continue;
            }
            $score = (int) $m->match_score;
            $map[(int) $contact->id] = ['score' => $score, 'tier' => MatchingService::tierFor($score)];
        }
        uasort($map, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $map;
    }

    /**
     * AT-144 — the AUDITABLE basis behind a seller-outreach buyer claim: the exact
     * count PLUS the matched-buyer contact_ids and their score/tier. Frozen into the
     * immutable per-send facts_snapshot so any seller challenge ("prove you had N
     * active buyers looking for my property") is answerable with the ACTUAL buyers
     * held at send time — not a number that silently re-derives differently later.
     * Same canonical engine + countable/active gate as
     * countableActiveBuyerCountForProperty(); `count` equals that method's value.
     *
     * @return array{count:int, contact_ids:int[], buyers:array<int,array{score:int,tier:string}>}
     */
    public function countableActiveBuyerBasisForProperty(Property $property): array
    {
        if (!$property->id) {
            return ['count' => 0, 'contact_ids' => [], 'buyers' => []];
        }
        $map = $this->activeCanonicalBuyersForProperty($property);

        return [
            'count'       => count($map),
            'contact_ids' => array_keys($map),
            'buyers'      => array_values($map),
        ];
    }

    /**
     * AT-74 hotfix — chunked upsert.
     *
     * A broad wishlist (area + price + type, no hard exclusions) can match
     * thousands of the agency's prospecting listings. A single upsert of all
     * matched rows blows past MySQL's 65,535 bound-parameter limit (SQLSTATE
     * 1390 "too many placeholders") and the ENTIRE write throws — which is
     * caught per-contact by RegenerateBuyerMatchesJob and silently swallowed,
     * leaving prospecting_buyer_matches EMPTY. That is why MIC "Buyer matched"
     * read 0 while the same buyer scored fine on the live Core Matches/
     * Intelligence paths. Chunking keeps every statement well under the limit
     * (≈11 cols × 500 rows = 5,500 placeholders).
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private function chunkedUpsert(string $table, array $rows, array $uniqueBy, array $update, int $chunk = 500): void
    {
        foreach (array_chunk($rows, $chunk) as $batch) {
            DB::table($table)->upsert($batch, $uniqueBy, $update);
        }
    }

    /**
     * AT-74 — empty buyer-demand shape (property not found / no signal).
     */
    private function emptyDemand(): array
    {
        return [
            'active'              => ['count' => 0, 'anonymised_buyers' => []],
            'historic'           => ['count' => 0],
            'area'               => ['suburb' => null, 'area_buyers' => 0, 'preapproved_count' => 0],
            'has_property_demand' => false,
        ];
    }

    public function getProspectingDemand(int $listingId): array
    {
        $matches = DB::table('prospecting_buyer_matches')
            ->where('prospecting_listing_id', $listingId)
            ->whereNull('dismissed_at')
            ->orderByDesc('score')
            ->get();

        return [
            'total'       => $matches->count(),
            'perfect'     => $matches->where('tier', 'perfect')->count(),
            'strong'      => $matches->where('tier', 'strong')->count(),
            'approximate' => $matches->where('tier', 'approximate')->count(),
            'top_matches' => $matches->take(5)->values()->map(fn ($m) => [
                'contact_id'       => $m->contact_id,
                'score'            => $m->score,
                'tier'             => $m->tier,
                'matched_features' => json_decode($m->matched_features ?? '[]', true),
                'missing_features' => json_decode($m->missing_features ?? '[]', true),
            ])->toArray(),
        ];
    }

    /* =========================================================
     |  Recompute / write paths
     * ========================================================= */

    /**
     * Recompute property_buyer_matches for a single buyer across all the
     * agency's published properties. Best score across the buyer's active
     * wishlists wins per property (UNIQUE(property_id, contact_id) constraint).
     */
    public function recomputeForBuyer(int $contactId): int
    {
        $contact = Contact::withoutGlobalScopes()->find($contactId);
        if (!$contact) {
            return 0;
        }

        // AT-108 — CANONICAL stock cache. Score via the canonical engine
        // (MatchingService::propertiesForMatch — the SAME engine the Core Matches
        // surface uses), NOT the legacy Engine-B calculateScore. A property is
        // cached iff it is a VISIBLE canonical match (score >= MIN_SCORE_TO_DISPLAY,
        // not in that wishlist's hidden_property_ids) in >= ONE active+countable
        // wishlist; best score across wishlists wins. The agency-wide,
        // status-filtered, visible universe matches propertiesForMatch exactly, so
        // COUNT(property_buyer_matches WHERE score >= MIN_SCORE_TO_DISPLAY) for a
        // buyer EQUALS the live Core Matches count. Finishes the stock-side
        // matcher-unification (the TODO at the top of this file).
        $matches = ContactMatch::withoutGlobalScopes()
            ->where('contact_id', $contactId)
            ->whereNull('deleted_at')
            ->where('status', ContactMatch::STATUS_ACTIVE)
            ->with('contact')
            ->get()
            ->filter(fn (ContactMatch $m) => $m->isCountable())
            ->values();

        $matcher = $this->matcher();
        $best = []; // property_id => ['score' => int, 'tier' => ?string]
        foreach ($matches as $m) {
            // agent_id => null = agency-wide stock; include_hidden => false = visible only.
            foreach ($matcher->propertiesForMatch($m, ['agent_id' => null, 'include_hidden' => false]) as $p) {
                $score = (int) ($p->match_score ?? 0);
                if ($score < MatchingService::MIN_SCORE_TO_DISPLAY) {
                    continue; // belt-and-braces; propertiesForMatch already floors here
                }
                if (!isset($best[$p->id]) || $score > $best[$p->id]['score']) {
                    $best[$p->id] = ['score' => $score, 'tier' => MatchingService::tierFor($score)];
                }
            }
        }

        $now  = now();
        $rows = [];
        foreach ($best as $propertyId => $b) {
            $rows[] = [
                'property_id'      => $propertyId,
                'contact_id'       => $contactId,
                'agency_id'        => $contact->agency_id,
                'score'            => $b['score'],
                'tier'             => $b['tier'],
                'breakdown'        => json_encode(['engine' => 'canonical']),
                'missing_features' => json_encode([]),
                'computed_at'      => $now,
            ];
        }

        // Single source of truth: this buyer's cache rows must EXACTLY mirror the
        // current canonical match set. Drop stale rows (a property that no longer
        // matches, or all rows when the buyer has no countable wishlist) so the
        // count can never drift above the live truth. Raw DB::table upsert because
        // property_buyer_matches has only computed_at (no created/updated_at).
        DB::transaction(function () use ($contactId, $contact, $rows) {
            $keepIds = array_column($rows, 'property_id');
            $stale = DB::table('property_buyer_matches')
                ->where('contact_id', $contactId)
                ->where('agency_id', $contact->agency_id);
            if (!empty($keepIds)) {
                $stale->whereNotIn('property_id', $keepIds);
            }
            $stale->delete();

            if (!empty($rows)) {
                $this->chunkedUpsert(
                    'property_buyer_matches',
                    $rows,
                    ['property_id', 'contact_id'],
                    ['agency_id', 'score', 'tier', 'breakdown', 'missing_features', 'computed_at']
                );
            }
        });

        return count($rows);
    }

    /**
     * Recompute prospecting_buyer_matches for a single prospecting listing
     * against every agency buyer with an active wishlist. Best score across
     * a buyer's wishlists wins per (listing, contact).
     */
    public function recomputeProspectingMatches(int $listingId): int
    {
        $listing = ProspectingListing::withoutGlobalScopes()->find($listingId);
        if (!$listing) {
            return 0;
        }

        // Active wishlists in the same agency, grouped by contact, eager-loaded.
        $matchesByContact = ContactMatch::withoutGlobalScopes()
            ->where('agency_id', $listing->agency_id)
            ->whereNull('deleted_at')
            ->where('status', ContactMatch::STATUS_ACTIVE)
            ->whereHas('contact', function ($q) {
                $q->where('is_buyer', 1)->whereNull('deleted_at');
            })
            ->with('contact')
            ->get()
            ->groupBy('contact_id');

        if ($matchesByContact->isEmpty()) {
            return 0;
        }

        $bandPct = AgencyContactSettings::forAgency((int) $listing->agency_id)->micPriceBandFraction();
        $target  = $this->wrapCaptureAsProperty($listing);

        $rows = [];
        $now  = now();
        foreach ($matchesByContact as $contactId => $matches) {
            $best = $this->canonicalBestAcross($matches, $target, $bandPct);
            if (!$best || $best['score'] < self::MIN_SCORE_TO_CACHE) {
                continue;
            }
            $rows[] = [
                'prospecting_listing_id' => $listingId,
                'contact_id'             => (int) $contactId,
                'agency_id'              => $listing->agency_id,
                'score'                  => $best['score'],
                'tier'                   => $best['tier'],
                // Part 6 — demand source from the buyer's entry origin (kept separable).
                'source'                 => optional($matches->first()->contact)->buyer_source,
                'matched_features'       => json_encode($best['breakdown']),
                'missing_features'       => json_encode($best['missing_features']),
                'matched_at'             => $now,
                'last_recompute_at'      => $now,
                'updated_at'             => $now,
                'created_at'             => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        // Raw DB::table upsert for consistency with property_buyer_matches write path.
        // Reads still go through ProspectingBuyerMatch (BelongsToAgency scope applies).
        $this->chunkedUpsert(
            'prospecting_buyer_matches',
            $rows,
            ['prospecting_listing_id', 'contact_id'],
            ['agency_id', 'score', 'tier', 'source', 'matched_features', 'missing_features', 'last_recompute_at', 'updated_at']
        );

        return count($rows);
    }

    /**
     * Recompute prospecting_buyer_matches for a single buyer against every
     * active prospecting listing in the agency. Best score across the buyer's
     * wishlists wins per (listing, contact).
     */
    public function recomputeProspectingMatchesForBuyer(int $contactId): int
    {
        $contact = Contact::withoutGlobalScopes()->find($contactId);
        if (!$contact) {
            return 0;
        }

        $matches = ContactMatch::withoutGlobalScopes()
            ->where('contact_id', $contactId)
            ->whereNull('deleted_at')
            ->where('status', ContactMatch::STATUS_ACTIVE)
            ->with('contact')
            ->get();
        if ($matches->isEmpty()) {
            return 0;
        }

        $listings = ProspectingListing::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->get();

        $bandPct = AgencyContactSettings::forAgency((int) $contact->agency_id)->micPriceBandFraction();

        $rows = [];
        $now  = now();
        foreach ($listings as $listing) {
            $best = $this->canonicalBestAcross($matches, $this->wrapCaptureAsProperty($listing), $bandPct);
            if (!$best || $best['score'] < self::MIN_SCORE_TO_CACHE) {
                continue;
            }
            $rows[] = [
                'prospecting_listing_id' => $listing->id,
                'contact_id'             => $contactId,
                'agency_id'              => $contact->agency_id,
                'score'                  => $best['score'],
                'tier'                   => $best['tier'],
                // Part 6 — demand source from the buyer's entry origin (kept separable).
                'source'                 => $contact->buyer_source,
                'matched_features'       => json_encode($best['breakdown']),
                'missing_features'       => json_encode($best['missing_features']),
                'matched_at'             => $now,
                'last_recompute_at'      => $now,
                'updated_at'             => $now,
                'created_at'             => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        // Raw DB::table upsert for consistency with property_buyer_matches write path.
        // Reads still go through ProspectingBuyerMatch (BelongsToAgency scope applies).
        $this->chunkedUpsert(
            'prospecting_buyer_matches',
            $rows,
            ['prospecting_listing_id', 'contact_id'],
            ['agency_id', 'score', 'tier', 'source', 'matched_features', 'missing_features', 'last_recompute_at', 'updated_at']
        );

        return count($rows);
    }

    /* =========================================================
     |  Helpers
     * ========================================================= */

    // AT-108 — bestResultAcross() (legacy Engine-B stock scorer) removed: the
    // stock cache (recomputeForBuyer) now scores via the canonical engine. The
    // public calculateScore() remains for scoreProspectingCapture().

    /**
     * AT-75 — best CANONICAL score across a buyer's wishlists for one listing.
     *
     * Uses the shared canonical engine (MatchingService::score, the same scorer
     * Core Matches / the Intelligence tab use): it scores ONLY the criteria the
     * buyer actually specified, so a near-empty wishlist no longer inflates to
     * ~85 on everything. Price drift decays the % (band widened by the agency's
     * configurable tolerance), never hard-excludes. Only genuine DEAL-BREAKERS
     * hard-exclude (bedrooms are scored soft, per Johan's rule b). This is what
     * makes the MIC % reconcile with the pipeline / Core Matches truth.
     */
    private function canonicalBestAcross(iterable $matches, Property $target, float $bandPct): ?array
    {
        $best = null;
        foreach ($matches as $m) {
            if (!$m->isCountable()) {
                continue; // AT-71 gate — uncountable wishlists never match
            }
            if ($this->violatesDealBreakers($m, $target)) {
                continue; // only deal-breakers hard-exclude
            }
            $score = $this->matcher()->score($target, $m, $bandPct);
            if ($best === null || $score > $best['score']) {
                $best = [
                    'score'            => $score,
                    'tier'             => $this->determineTier($score),
                    'breakdown'        => ['engine' => 'canonical', 'price_band_pct' => $bandPct],
                    'missing_features' => [],
                ];
            }
        }
        return $best;
    }

    /** Shared canonical matching engine (resolved once per instance). */
    private ?MatchingService $matcher = null;
    private function matcher(): MatchingService
    {
        return $this->matcher ??= app(MatchingService::class);
    }

    /** AT-75 — per-request lowercased p24 suburb-name → id map (one query). */
    private ?array $suburbNameToId = null;
    private function resolveSuburbId(?string $name): ?int
    {
        if (!$name) {
            return null;
        }
        if ($this->suburbNameToId === null) {
            $this->suburbNameToId = [];
            foreach (DB::table('p24_suburbs')->select('id', 'name')->get() as $r) {
                $key = strtolower(trim((string) $r->name));
                // First id wins for a given name (good enough for soft area scoring).
                if ($key !== '' && !isset($this->suburbNameToId[$key])) {
                    $this->suburbNameToId[$key] = (int) $r->id;
                }
            }
        }
        return $this->suburbNameToId[strtolower(trim($name))] ?? null;
    }

    private function violatesBedroomFilter(ContactMatch $match, Property $property): bool
    {
        $beds = $property->beds;
        if ($beds === null) {
            return false; // unknown property bedrooms — don't hard-fail
        }
        if ($match->beds_min !== null && $beds < $match->beds_min) {
            return true;
        }
        if ($match->bedrooms_max !== null && $beds > $match->bedrooms_max) {
            return true;
        }
        return false;
    }

    private function violatesDealBreakers(ContactMatch $match, Property $property): bool
    {
        $breakers = is_array($match->deal_breakers) ? $match->deal_breakers : [];
        if (empty($breakers)) {
            return false;
        }
        $features = $this->propertyFeatureTokens($property);
        if (empty($features)) {
            return false; // no property features known — cannot prove violation
        }
        foreach ($breakers as $b) {
            if (in_array(strtolower(trim((string) $b)), $features, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalise a property's features_json into a flat list of lower-snake_case
     * tokens. Handles both shapes the codebase has historically used:
     *   - JSON array:  ["pool","garage"]
     *   - JSON object: {"pool":true,"garage":false}  (only truthy values kept)
     *
     * @return string[]
     */
    private function propertyFeatureTokens(Property $property): array
    {
        $raw = $property->features_json ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $tokens = [];
        foreach ($raw as $k => $v) {
            if (is_int($k)) {
                if (is_string($v) && $v !== '') {
                    $tokens[] = strtolower(trim($v));
                }
            } elseif ($v) {
                $tokens[] = strtolower(trim((string) $k));
            }
        }
        return $tokens;
    }

    private function scorePrice(ContactMatch $match, Property $property): array
    {
        if (!$match->price_min && !$match->price_max) {
            return ['points' => 20, 'gap' => null]; // no-signal default (preserved)
        }
        $price = $property->price ?? 0;
        if (!$price) {
            return ['points' => 15, 'gap' => null];
        }

        $min = $match->price_min ?? 0;
        $max = $match->price_max ?? PHP_INT_MAX;

        if ($price >= $min && $price <= $max) {
            return ['points' => 25, 'gap' => null];
        }
        if ($max > 0 && $price <= $max * 1.1 && $price >= $min * 0.9) {
            return ['points' => 18, 'gap' => 'R ' . number_format($price) . ' vs budget R ' . number_format($max)];
        }
        if ($max > 0 && $price <= $max * 1.2) {
            return ['points' => 8, 'gap' => 'Over budget by ' . round(($price - $max) / max($max, 1) * 100) . '%'];
        }
        return ['points' => 0, 'gap' => 'Significantly over budget'];
    }

    private function scoreArea(ContactMatch $match, Property $property): array
    {
        // Hard-cutover: score by P24 suburb id (exact match) — same source as
        // the Properties picker, so wishlists line up site-wide.
        $preferredIds = method_exists($match, 'p24SuburbIdList') ? $match->p24SuburbIdList() : [];
        if (empty($preferredIds)) {
            return ['points' => 15, 'gap' => null]; // no-signal default (preserved)
        }
        if (!$property->p24_suburb_id) {
            return ['points' => 10, 'gap' => null];
        }
        if (in_array((int) $property->p24_suburb_id, $preferredIds, true)) {
            return ['points' => 20, 'gap' => null];
        }
        return ['points' => 5, 'gap' => "Different area: {$property->suburb}"];
    }

    private function scorePropertyType(ContactMatch $match, Property $property): array
    {
        // propertyTypeList() handles property_types (json) → property_type (string) fallback per spec D2.
        $preferred = $match->propertyTypeList();
        if (empty($preferred)) {
            return ['points' => 8, 'gap' => null]; // no-signal default (preserved)
        }
        if (!$property->property_type) {
            return ['points' => 5, 'gap' => null];
        }
        if (in_array($property->property_type, $preferred, true)) {
            return ['points' => 10, 'gap' => null];
        }
        return ['points' => 3, 'gap' => null];
    }

    private function scoreMustHaves(ContactMatch $match, Property $property): array
    {
        $mustHaves = is_array($match->must_have_features) ? $match->must_have_features : [];
        if (empty($mustHaves)) {
            return ['points' => 12, 'missing' => []]; // no-signal default (preserved)
        }

        // Out-of-scope for this prompt to introduce a hard property-side must-have
        // filter — the audit's recommendation is to migrate features-on-properties
        // separately. Preserve current generous default.
        return ['points' => 10, 'missing' => []];
    }

    private function scoreDealBreakers(ContactMatch $match, Property $property): array
    {
        // Hard exclusion is performed earlier in violatesDealBreakers(). If we
        // reach here either there are no deal_breakers or none are present on
        // the property — full points either way (preserves current behaviour
        // when no breakers exist).
        return ['points' => 10];
    }

    private function scoreBedrooms(ContactMatch $match, Property $property): array
    {
        // Hard filter is enforced earlier. Reaching here means:
        //  - both beds_min and bedrooms_max are null (no buyer signal), OR
        //  - property.beds is within [beds_min, bedrooms_max].
        // Either way: full 20. Per spec D4 + build prompt explicit no-signal=no-penalty choice.
        return ['points' => 20];
    }

    private function determineTier(int $score): string
    {
        // AT-73 — "strong" must mean the SAME thing here as on Core Matches
        // (Path 1) and the MIC strong-buyer reads, which all gate at score >= 80
        // (MatchingService::TIER_STRONG_MIN / BuyerMatchTier strong_min_score=80).
        // This engine previously stamped tier='strong' at >= 70, so a 70-79 row
        // was labelled "strong" in the column yet excluded by every >= 80 reader
        // — an internal inconsistency. Aligned to the canonical threshold; the
        // finer 'perfect' (>= 90) sub-band of strong is retained.
        if ($score >= 90)                                return 'perfect';
        if ($score >= MatchingService::TIER_STRONG_MIN)  return 'strong';      // 80 — canonical
        if ($score >= self::MIN_SCORE_TO_CACHE)          return 'approximate'; // 50
        return 'none';
    }

    private function failedResult(string $reason): array
    {
        return [
            'score'            => 0,
            'tier'             => 'none',
            'breakdown'        => ['hard_filter' => $reason],
            'missing_features' => [$reason],
        ];
    }

    /**
     * Wrap an arbitrary capture/listing object (ProspectingListing, raw stdClass)
     * onto an in-memory Property so scorers work uniformly. Maps the prospecting
     * `bedrooms` column → Property `beds`, and copies features_json if present.
     */
    private function wrapCaptureAsProperty(object $data): Property
    {
        $p = new Property();
        $p->price         = $data->price ?? null;
        $p->suburb        = $data->suburb ?? null;
        $p->property_type = $data->property_type ?? null;
        $p->beds          = $data->beds ?? ($data->bedrooms ?? null);
        $p->baths         = $data->bathrooms ?? ($data->baths ?? null);
        $p->garages       = $data->garages ?? null;
        $p->size_m2       = $data->property_size_m2 ?? ($data->size_m2 ?? null);
        $p->erf_size_m2   = $data->erf_size_m2 ?? null;
        $p->features_json = $data->features_json ?? null;
        // AT-75 — prospecting listings store only a suburb NAME; resolve it to a
        // p24_suburb_id so the canonical area scorer (suburbFitRatio) works.
        $p->p24_suburb_id = $this->resolveSuburbId($data->suburb ?? null);
        return $p;
    }
}
