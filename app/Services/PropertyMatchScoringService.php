<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Property;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Scores properties against buyer wishlists.
 * Weighted scoring: price (25), area (20), property_type (10),
 * deal_breakers (10), must_haves (15), bedrooms (20 — when available).
 */
class PropertyMatchScoringService
{
    public function calculateScore(object $prefs, Property $property): array
    {
        $score = 0;
        $breakdown = [];
        $missing = [];

        // Price (25 points)
        $priceScore = $this->scorePrice($prefs, $property);
        $breakdown['price'] = $priceScore;
        $score += $priceScore['points'];
        if ($priceScore['points'] < 25) $missing[] = $priceScore['gap'];

        // Area/suburb (20 points)
        $areaScore = $this->scoreArea($prefs, $property);
        $breakdown['area'] = $areaScore;
        $score += $areaScore['points'];
        if ($areaScore['points'] < 20 && $areaScore['gap']) $missing[] = $areaScore['gap'];

        // Property type (10 points)
        $typeScore = $this->scorePropertyType($prefs, $property);
        $breakdown['type'] = $typeScore;
        $score += $typeScore['points'];

        // Must-have features (15 points)
        $featureScore = $this->scoreMustHaves($prefs, $property);
        $breakdown['features'] = $featureScore;
        $score += $featureScore['points'];
        if (!empty($featureScore['missing'])) $missing = array_merge($missing, $featureScore['missing']);

        // Deal-breakers (10 points — 0 if any breaker present)
        $breakerScore = $this->scoreDealBreakers($prefs, $property);
        $breakdown['deal_breakers'] = $breakerScore;
        $score += $breakerScore['points'];

        // Bedrooms (20 points — placeholder max if data unavailable)
        $score += 15; // Default generous score until bedrooms normalised on properties
        $breakdown['bedrooms'] = ['points' => 15, 'note' => 'bedrooms not yet normalised on properties'];

        $total = min(100, $score);
        $tier = $this->determineTier($total);

        return [
            'score' => $total,
            'tier' => $tier,
            'breakdown' => $breakdown,
            'missing_features' => array_filter($missing),
        ];
    }

    public function getMatchesForBuyer(int $contactId, ?string $tier = null, int $limit = 20): Collection
    {
        $query = DB::table('property_buyer_matches')
            ->where('contact_id', $contactId)
            ->where('score', '>=', 50)
            ->orderByDesc('score');

        if ($tier) $query->where('tier', $tier);

        return $query->limit($limit)->get();
    }

    public function getMatchesForProperty(int $propertyId, int $limit = 20): Collection
    {
        return DB::table('property_buyer_matches')
            ->where('property_id', $propertyId)
            ->where('score', '>=', 50)
            ->orderByDesc('score')
            ->limit($limit)
            ->get();
    }

    /**
     * Compute and cache matches for a buyer against all active properties.
     */
    public function recomputeForBuyer(int $contactId): int
    {
        $prefs = DB::table('buyer_preferences')->where('contact_id', $contactId)->first();
        if (!$prefs) return 0;

        $contact = Contact::withoutGlobalScopes()->find($contactId);
        if (!$contact) return 0;

        $properties = Property::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereNotNull('published_at')
            ->get();

        $count = 0;
        foreach ($properties as $property) {
            $result = $this->calculateScore($prefs, $property);
            if ($result['score'] < 50) continue;

            DB::table('property_buyer_matches')->updateOrInsert(
                ['property_id' => $property->id, 'contact_id' => $contactId],
                [
                    'score' => $result['score'],
                    'tier' => $result['tier'],
                    'breakdown' => json_encode($result['breakdown']),
                    'missing_features' => json_encode($result['missing_features']),
                    'computed_at' => now(),
                ]
            );
            $count++;
        }

        return $count;
    }

    private function scorePrice(object $prefs, Property $property): array
    {
        if (!$prefs->budget_min && !$prefs->budget_max) return ['points' => 20, 'gap' => null];
        $price = $property->price ?? 0;
        if (!$price) return ['points' => 15, 'gap' => null];

        $min = $prefs->budget_min ?? 0;
        $max = $prefs->budget_max ?? PHP_FLOAT_MAX;

        if ($price >= $min && $price <= $max) return ['points' => 25, 'gap' => null];
        if ($price <= $max * 1.1 && $price >= $min * 0.9) return ['points' => 18, 'gap' => 'R ' . number_format($price) . ' vs budget R ' . number_format($max)];
        if ($price <= $max * 1.2) return ['points' => 8, 'gap' => 'Over budget by ' . round(($price - $max) / $max * 100) . '%'];
        return ['points' => 0, 'gap' => 'Significantly over budget'];
    }

    private function scoreArea(object $prefs, Property $property): array
    {
        $preferred = json_decode($prefs->preferred_areas ?? '[]', true);
        if (empty($preferred)) return ['points' => 15, 'gap' => null];
        if (!$property->suburb) return ['points' => 10, 'gap' => null];

        if (in_array($property->suburb, $preferred)) return ['points' => 20, 'gap' => null];
        // Basic neighbouring check (same first word or within broader area)
        foreach ($preferred as $area) {
            if (str_starts_with($property->suburb, explode(' ', $area)[0])) return ['points' => 12, 'gap' => "Nearby: {$property->suburb} vs preferred {$area}"];
        }
        return ['points' => 5, 'gap' => "Different area: {$property->suburb}"];
    }

    private function scorePropertyType(object $prefs, Property $property): array
    {
        $preferred = json_decode($prefs->preferred_property_types ?? '[]', true);
        if (empty($preferred)) return ['points' => 8, 'gap' => null];
        if (!$property->property_type) return ['points' => 5, 'gap' => null];
        if (in_array($property->property_type, $preferred)) return ['points' => 10, 'gap' => null];
        return ['points' => 3, 'gap' => null];
    }

    private function scoreMustHaves(object $prefs, Property $property): array
    {
        $mustHaves = json_decode($prefs->must_have_features ?? '[]', true);
        if (empty($mustHaves)) return ['points' => 12, 'missing' => []];
        // Without a features column on properties, give generous default
        return ['points' => 10, 'missing' => []];
    }

    private function scoreDealBreakers(object $prefs, Property $property): array
    {
        $breakers = json_decode($prefs->deal_breakers ?? '[]', true);
        if (empty($breakers)) return ['points' => 10];
        // Without a features column on properties, assume no breakers present
        return ['points' => 10];
    }

    /**
     * Score a prospecting capture against buyer preferences.
     * Adapts the capture's fields to match Property interface.
     */
    public function scoreProspectingCapture(object $prefs, object $capture): array
    {
        // Create a Property-compatible object from prospecting listing data
        $proxy = new \stdClass();
        $proxy->price = $capture->price ?? 0;
        $proxy->suburb = $capture->suburb ?? null;
        $proxy->property_type = $capture->property_type ?? null;
        $proxy->beds = $capture->bedrooms ?? null;
        $proxy->features_json = null;

        return $this->calculateScore($prefs, $this->wrapAsProperty($proxy));
    }

    /**
     * Recompute matches for a single prospecting listing against all agency buyers.
     */
    public function recomputeProspectingMatches(int $listingId): int
    {
        $listing = DB::table('prospecting_listings')->where('id', $listingId)->first();
        if (!$listing) return 0;

        $buyerPrefs = DB::table('buyer_preferences as bp')
            ->join('contacts as c', 'c.id', '=', 'bp.contact_id')
            ->where('c.agency_id', $listing->agency_id)
            ->where('c.is_buyer', 1)
            ->whereNull('c.deleted_at')
            ->get(['bp.*', 'c.id as contact_id_check']);

        $count = 0;
        $now = now();
        foreach ($buyerPrefs as $prefs) {
            $result = $this->scoreProspectingCapture($prefs, $listing);
            if ($result['score'] < 50) continue;

            DB::table('prospecting_buyer_matches')->updateOrInsert(
                ['prospecting_listing_id' => $listingId, 'contact_id' => $prefs->contact_id],
                [
                    'score' => $result['score'],
                    'tier' => $result['tier'],
                    'matched_features' => json_encode($result['breakdown']),
                    'missing_features' => json_encode($result['missing_features']),
                    'matched_at' => $now,
                    'last_recompute_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Recompute matches for a buyer against all active prospecting listings.
     */
    public function recomputeProspectingMatchesForBuyer(int $contactId): int
    {
        $prefs = DB::table('buyer_preferences')->where('contact_id', $contactId)->first();
        if (!$prefs) return 0;

        $contact = Contact::withoutGlobalScopes()->find($contactId);
        if (!$contact) return 0;

        $listings = DB::table('prospecting_listings')
            ->where('agency_id', $contact->agency_id)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->get();

        $count = 0;
        $now = now();
        foreach ($listings as $listing) {
            $result = $this->scoreProspectingCapture($prefs, $listing);
            if ($result['score'] < 50) continue;

            DB::table('prospecting_buyer_matches')->updateOrInsert(
                ['prospecting_listing_id' => $listing->id, 'contact_id' => $contactId],
                [
                    'score' => $result['score'],
                    'tier' => $result['tier'],
                    'matched_features' => json_encode($result['breakdown']),
                    'missing_features' => json_encode($result['missing_features']),
                    'matched_at' => $now,
                    'last_recompute_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Get buyer demand summary for a prospecting listing.
     */
    public function getProspectingDemand(int $listingId): array
    {
        $matches = DB::table('prospecting_buyer_matches')
            ->where('prospecting_listing_id', $listingId)
            ->whereNull('dismissed_at')
            ->orderByDesc('score')
            ->get();

        return [
            'total' => $matches->count(),
            'perfect' => $matches->where('tier', 'perfect')->count(),
            'strong' => $matches->where('tier', 'strong')->count(),
            'approximate' => $matches->where('tier', 'approximate')->count(),
            'top_matches' => $matches->take(5)->map(fn($m) => [
                'contact_id' => $m->contact_id,
                'score' => $m->score,
                'tier' => $m->tier,
                'matched_features' => json_decode($m->matched_features, true),
                'missing_features' => json_decode($m->missing_features, true),
            ])->values()->toArray(),
        ];
    }

    /**
     * Get buyer demand summary for an internal property (for presentations).
     */
    public function getBuyerDemandForProperty(int $propertyId, int $agencyId): array
    {
        // Count matching buyers from property_buyer_matches
        $matches = DB::table('property_buyer_matches')
            ->where('property_id', $propertyId)
            ->where('score', '>=', 50)
            ->orderByDesc('score')
            ->get();

        // Count pre-approved buyers in agency
        $property = Property::withoutGlobalScopes()->find($propertyId);
        $preapprovedCount = 0;
        if ($property && $property->price) {
            $preapprovedCount = DB::table('buyer_preferences as bp')
                ->join('contacts as c', 'c.id', '=', 'bp.contact_id')
                ->where('c.agency_id', $agencyId)
                ->where('c.is_buyer', 1)
                ->whereNull('c.deleted_at')
                ->whereNotNull('bp.preapproval_amount')
                ->where('bp.preapproval_amount', '>=', $property->price)
                ->count();
        }

        // Area buyers (contacts with preferred_areas matching this suburb)
        $areaBuyers = 0;
        if ($property && $property->suburb) {
            $areaBuyers = DB::table('buyer_preferences as bp')
                ->join('contacts as c', 'c.id', '=', 'bp.contact_id')
                ->where('c.agency_id', $agencyId)
                ->where('c.is_buyer', 1)
                ->whereNull('c.deleted_at')
                ->whereRaw("JSON_SEARCH(bp.preferred_areas, 'one', ?) IS NOT NULL", [$property->suburb])
                ->count();
        }

        return [
            'total_matches' => $matches->count(),
            'perfect' => $matches->where('tier', 'perfect')->count(),
            'strong' => $matches->where('tier', 'strong')->count(),
            'preapproved_count' => $preapprovedCount,
            'area_buyers' => $areaBuyers,
            'above_threshold' => $matches->count() >= 3, // Minimum credibility threshold
            'anonymised_buyers' => $matches->take(5)->map(fn($m, $i) => [
                'label' => 'Buyer ' . ($i + 1),
                'score' => $m->score,
                'tier' => $m->tier,
            ])->values()->toArray(),
        ];
    }

    private function wrapAsProperty(object $data): Property
    {
        $p = new Property();
        $p->price = $data->price ?? null;
        $p->suburb = $data->suburb ?? null;
        $p->property_type = $data->property_type ?? null;
        $p->beds = $data->beds ?? null;
        $p->features_json = $data->features_json ?? null;
        return $p;
    }

    private function determineTier(int $score): string
    {
        if ($score >= 90) return 'perfect';
        if ($score >= 70) return 'strong';
        if ($score >= 50) return 'approximate';
        return 'none';
    }
}
