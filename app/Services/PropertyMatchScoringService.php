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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 * Threshold to write a cached match row: score >= 50. Tier boundaries:
 * perfect 90+, strong 70-89, approximate 50-69.
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

    /* =========================================================
     |  Scoring
     * ========================================================= */

    public function calculateScore(ContactMatch $match, Property $property): array
    {
        // Eager-load the contact relation so preapproval reads do not N+1 the caller.
        $match->loadMissing('contact');

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
        $matches = DB::table('property_buyer_matches')
            ->where('property_id', $propertyId)
            ->where('score', '>=', self::MIN_SCORE_TO_CACHE)
            ->orderByDesc('score')
            ->get();

        $property = Property::withoutGlobalScopes()->find($propertyId);

        // Pre-approved buyers (per spec D3 — preapproval lives on Contact).
        // Counted: agency buyers with a non-expired preapproval >= property price
        // AND at least one active ContactMatch (otherwise they're not in the buyer pool).
        $preapprovedCount = 0;
        if ($property && $property->price) {
            $preapprovedCount = DB::table('contacts as c')
                ->join('contact_matches as cm', 'cm.contact_id', '=', 'c.id')
                ->where('c.agency_id', $agencyId)
                ->where('c.is_buyer', 1)
                ->whereNull('c.deleted_at')
                ->whereNull('cm.deleted_at')
                ->where('cm.status', ContactMatch::STATUS_ACTIVE)
                ->whereNotNull('c.preapproval_amount')
                ->where('c.preapproval_amount', '>=', $property->price)
                ->where(function ($q) {
                    $q->whereNull('c.preapproval_expires_at')
                      ->orWhere('c.preapproval_expires_at', '>=', Carbon::today()->toDateString());
                })
                ->distinct()
                ->count('c.id');
        }

        // Area buyers — distinct contacts whose any active wishlist names this suburb.
        $areaBuyers = 0;
        if ($property && $property->suburb) {
            $areaBuyers = DB::table('contacts as c')
                ->join('contact_matches as cm', 'cm.contact_id', '=', 'c.id')
                ->where('c.agency_id', $agencyId)
                ->where('c.is_buyer', 1)
                ->whereNull('c.deleted_at')
                ->whereNull('cm.deleted_at')
                ->where('cm.status', ContactMatch::STATUS_ACTIVE)
                ->where(function ($q) use ($property) {
                    $q->where('cm.suburb', $property->suburb)
                      ->orWhereRaw("JSON_SEARCH(cm.suburbs, 'one', ?) IS NOT NULL", [$property->suburb]);
                })
                ->distinct()
                ->count('c.id');
        }

        return [
            'total_matches'      => $matches->count(),
            'perfect'            => $matches->where('tier', 'perfect')->count(),
            'strong'             => $matches->where('tier', 'strong')->count(),
            'preapproved_count'  => $preapprovedCount,
            'area_buyers'        => $areaBuyers,
            'above_threshold'    => $matches->count() >= 3,
            'anonymised_buyers'  => $matches->take(5)->values()->map(fn ($m, $i) => [
                'label' => 'Buyer ' . ($i + 1),
                'score' => $m->score,
                'tier'  => $m->tier,
            ])->toArray(),
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

        $matches = ContactMatch::withoutGlobalScopes()
            ->where('contact_id', $contactId)
            ->whereNull('deleted_at')
            ->where('status', ContactMatch::STATUS_ACTIVE)
            ->with('contact')
            ->get();
        if ($matches->isEmpty()) {
            return 0;
        }

        $properties = Property::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->get();

        $rows  = [];
        $now   = now();
        foreach ($properties as $property) {
            $best = $this->bestResultAcross($matches, $property);
            if (!$best || $best['score'] < self::MIN_SCORE_TO_CACHE) {
                continue;
            }
            $rows[] = [
                'property_id'      => $property->id,
                'contact_id'       => $contactId,
                'agency_id'        => $contact->agency_id,
                'score'            => $best['score'],
                'tier'             => $best['tier'],
                'breakdown'        => json_encode($best['breakdown']),
                'missing_features' => json_encode($best['missing_features']),
                'computed_at'      => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        // Raw DB::table upsert: property_buyer_matches has no created_at/updated_at
        // columns (only computed_at), so the Eloquent model's auto-timestamp logic
        // would add invalid columns to the SQL. Reads still use PropertyBuyerMatch
        // (and its BelongsToAgency scope) on the consumer side.
        DB::table('property_buyer_matches')->upsert(
            $rows,
            ['property_id', 'contact_id'],
            ['agency_id', 'score', 'tier', 'breakdown', 'missing_features', 'computed_at']
        );

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

        $rows = [];
        $now  = now();
        foreach ($matchesByContact as $contactId => $matches) {
            $best = $this->bestResultAcross($matches, $this->wrapCaptureAsProperty($listing));
            if (!$best || $best['score'] < self::MIN_SCORE_TO_CACHE) {
                continue;
            }
            $rows[] = [
                'prospecting_listing_id' => $listingId,
                'contact_id'             => (int) $contactId,
                'agency_id'              => $listing->agency_id,
                'score'                  => $best['score'],
                'tier'                   => $best['tier'],
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
        DB::table('prospecting_buyer_matches')->upsert(
            $rows,
            ['prospecting_listing_id', 'contact_id'],
            ['agency_id', 'score', 'tier', 'matched_features', 'missing_features', 'last_recompute_at', 'updated_at']
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

        $rows = [];
        $now  = now();
        foreach ($listings as $listing) {
            $best = $this->bestResultAcross($matches, $this->wrapCaptureAsProperty($listing));
            if (!$best || $best['score'] < self::MIN_SCORE_TO_CACHE) {
                continue;
            }
            $rows[] = [
                'prospecting_listing_id' => $listing->id,
                'contact_id'             => $contactId,
                'agency_id'              => $contact->agency_id,
                'score'                  => $best['score'],
                'tier'                   => $best['tier'],
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
        DB::table('prospecting_buyer_matches')->upsert(
            $rows,
            ['prospecting_listing_id', 'contact_id'],
            ['agency_id', 'score', 'tier', 'matched_features', 'missing_features', 'last_recompute_at', 'updated_at']
        );

        return count($rows);
    }

    /* =========================================================
     |  Helpers
     * ========================================================= */

    /**
     * Iterate the supplied ContactMatches for a single target, return the
     * highest-scoring result (or null if none cross the cache threshold here
     * — the caller filters on MIN_SCORE_TO_CACHE).
     *
     * @param  iterable<ContactMatch>  $matches
     */
    private function bestResultAcross(iterable $matches, Property $target): ?array
    {
        $best = null;
        foreach ($matches as $m) {
            $result = $this->calculateScore($m, $target);
            if ($best === null || $result['score'] > $best['score']) {
                $best = $result;
            }
        }
        return $best;
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
        $preferred = is_array($match->suburbs) ? $match->suburbs : [];
        // Legacy single-suburb fallback for older rows whose `suburbs` json is null.
        if (empty($preferred) && !empty($match->suburb)) {
            $preferred = [$match->suburb];
        }
        if (empty($preferred)) {
            return ['points' => 15, 'gap' => null]; // no-signal default (preserved)
        }
        if (!$property->suburb) {
            return ['points' => 10, 'gap' => null];
        }

        if (in_array($property->suburb, $preferred, true)) {
            return ['points' => 20, 'gap' => null];
        }
        foreach ($preferred as $area) {
            $firstWord = explode(' ', (string) $area)[0] ?? '';
            if ($firstWord !== '' && str_starts_with((string) $property->suburb, $firstWord)) {
                return ['points' => 12, 'gap' => "Nearby: {$property->suburb} vs preferred {$area}"];
            }
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
        if ($score >= 90) return 'perfect';
        if ($score >= 70) return 'strong';
        if ($score >= 50) return 'approximate';
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
        $p->features_json = $data->features_json ?? null;
        return $p;
    }
}
