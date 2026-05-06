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

    private function determineTier(int $score): string
    {
        if ($score >= 90) return 'perfect';
        if ($score >= 70) return 'strong';
        if ($score >= 50) return 'approximate';
        return 'none';
    }
}
