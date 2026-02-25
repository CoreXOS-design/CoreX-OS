<?php

namespace App\Services\Presentations\Analytics;

use App\Models\ListingStock;
use App\Models\P24Suburb;
use App\Models\Presentation;
use App\Support\SuburbMapper;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes listing performance benchmarks from PropCon agency data.
 *
 * Given a presentation, finds similar active listings from the PropCon
 * import and returns views, matches, days-on-market benchmarks plus
 * a market signal interpretation.
 *
 * All math happens here — Blade only renders the result array.
 */
class PropConInsightsService
{
    /**
     * Compute PropCon listing performance insights for a presentation.
     */
    public function compute(Presentation $presentation): array
    {
        $suburb       = $presentation->suburb;
        $propertyType = $presentation->property_type;
        $askingPrice  = $presentation->asking_price_inc;
        $bedrooms     = $presentation->bedrooms;

        if (empty($suburb) || empty($askingPrice)) {
            return $this->emptyResult('Missing suburb or asking price on presentation.');
        }

        // Total PropCon listings for context
        $totalPropcon = ListingStock::where('source', 'propcon')->count();
        if ($totalPropcon === 0) {
            return $this->emptyResult('No PropCon data imported yet.');
        }

        // Build target suburbs — town-level expansion first, P24 suburb fallback
        $targetSuburbs = $this->resolveTargetSuburbs($suburb);
        $townLabel = SuburbMapper::townLabel($suburb);

        // Map property types
        $targetTypes = $this->mapPropertyTypes($propertyType);

        // Price range: ±40% of asking price (broader area = more price variation)
        $priceLow  = (int) round($askingPrice * 0.60);
        $priceHigh = (int) round($askingPrice * 1.40);

        // Query active PropCon listings
        $query = ListingStock::where('source', 'propcon')
            ->whereIn('status', ['For Sale', 'for sale', 'Active', 'active']);

        // Price filter (price_cents is in cents)
        $query->whereBetween('price_cents', [$priceLow * 100, $priceHigh * 100]);

        // Type filter
        if (!empty($targetTypes)) {
            $query->where(function ($q) use ($targetTypes) {
                foreach ($targetTypes as $t) {
                    $q->orWhereRaw('LOWER(type) = ?', [strtolower($t)]);
                }
            });
        }

        // Suburb filter: match against property address or region
        if (!empty($targetSuburbs)) {
            $query->where(function ($q) use ($targetSuburbs) {
                foreach ($targetSuburbs as $s) {
                    $lower = strtolower($s);
                    $q->orWhereRaw('LOWER(property) LIKE ?', ['%' . $lower . '%']);
                    $q->orWhereRaw('LOWER(region) LIKE ?', ['%' . $lower . '%']);
                }
            });
        }

        $listings = $query->get();

        if ($listings->isEmpty()) {
            return $this->emptyResult('No similar PropCon listings found matching criteria.', $totalPropcon, $targetSuburbs, $targetTypes, $priceLow, $priceHigh);
        }

        // Extract enriched data from raw_payload
        $enriched = $listings->map(function (ListingStock $ls) {
            $raw = $ls->raw_payload ?? [];
            return [
                'id'              => $ls->id,
                'address'         => $this->cleanAddress($ls->property),
                'type'            => $ls->type,
                'price'           => $ls->price_cents ? (int) round($ls->price_cents / 100) : null,
                'beds'            => $this->extractPayloadInt($raw, ['Bed', 'Beds', 'Bedrooms', 'bed', 'beds', 'bedrooms']),
                'baths'           => $this->extractPayloadInt($raw, ['Bath', 'Baths', 'Bathrooms', 'bath', 'baths', 'bathrooms']),
                'views'           => $this->extractPayloadInt($raw, ['Views', 'views', 'Portal Views', 'portal views', 'PortalViews']),
                'matches'         => $this->extractPayloadInt($raw, ['Matches', 'matches', 'Buyer Matches', 'buyer matches', 'BuyerMatches']),
                'floor_size'      => $this->extractPayloadInt($raw, ['Floor Size', 'floor size', 'FloorSize', 'Floor_Size']),
                'erf_size'        => $this->extractPayloadInt($raw, ['Erf Size', 'erf size', 'ErfSize', 'Erf_Size', 'Stand Size']),
                'days_on_market'  => $ls->days_on_market,
                'listed_at'       => $ls->listed_at,
                'agent'           => $this->extractPayloadString($raw, ['Agents', 'Agent', 'agents', 'agent']),
                'status'          => $ls->status,
                'user_id'         => $ls->user_id,
            ];
        });

        // Filter by bedrooms if available (± 1)
        if ($bedrooms !== null && $bedrooms > 0) {
            $bedsMin = $bedrooms - 1;
            $bedsMax = $bedrooms + 1;
            $filtered = $enriched->filter(function ($item) use ($bedsMin, $bedsMax) {
                // Keep items where beds is null (can't filter) or within range
                if ($item['beds'] === null) return true;
                return $item['beds'] >= $bedsMin && $item['beds'] <= $bedsMax;
            });
            // Only use filtered if it has results; otherwise fall back to unfiltered
            if ($filtered->isNotEmpty()) {
                $enriched = $filtered;
            }
        }

        if ($enriched->isEmpty()) {
            return $this->emptyResult('No similar PropCon listings found after bedroom filtering.', $totalPropcon, $targetSuburbs, $targetTypes, $priceLow, $priceHigh);
        }

        // Compute views per day for each listing
        $enriched = $enriched->map(function ($item) {
            $dom = $item['days_on_market'];
            $views = $item['views'];
            $item['views_per_day'] = ($dom !== null && $dom > 0 && $views !== null)
                ? round($views / $dom, 1)
                : null;
            return $item;
        });

        // Try to identify the subject property in PropCon data
        $subjectMatch = $this->findSubjectProperty($enriched, $presentation);

        // Build benchmark statistics
        $viewsArr   = $enriched->pluck('views')->filter(fn($v) => $v !== null)->values();
        $matchesArr = $enriched->pluck('matches')->filter(fn($v) => $v !== null)->values();
        $domArr     = $enriched->pluck('days_on_market')->filter(fn($v) => $v !== null && $v >= 0)->values();
        $vpdArr     = $enriched->pluck('views_per_day')->filter(fn($v) => $v !== null)->values();

        $avgViews   = $viewsArr->count() > 0 ? (int) round($viewsArr->avg()) : null;
        $avgMatches = $matchesArr->count() > 0 ? (int) round($matchesArr->avg()) : null;
        $avgDom     = $domArr->count() > 0 ? (int) round($domArr->avg()) : null;
        $avgVpd     = $vpdArr->count() > 0 ? round($vpdArr->avg(), 1) : null;

        // Criteria description
        $bedsLabel = $bedrooms ? "{$bedrooms} Bed" : '';
        $typeLabel = ucfirst($propertyType ?? 'Properties');
        $priceLabel = 'R ' . number_format($priceLow) . '–R ' . number_format($priceHigh);
        $criteria = trim("{$bedsLabel} {$typeLabel} · {$priceLabel} · {$townLabel}");

        // Build listings array (top 10, sorted by views desc)
        $sorted = $enriched->sortByDesc('views')->values()->take(10);
        $listingsOut = $sorted->map(function ($item) use ($subjectMatch) {
            return [
                'address'        => $item['address'],
                'type'           => $item['type'],
                'price'          => $item['price'],
                'beds'           => $item['beds'],
                'views'          => $item['views'],
                'matches'        => $item['matches'],
                'days_on_market' => $item['days_on_market'],
                'views_per_day'  => $item['views_per_day'],
                'agent'          => $item['agent'],
                'is_subject'     => $subjectMatch !== null && $item['id'] === $subjectMatch['id'],
            ];
        })->values()->toArray();

        // Subject property stats
        $subjectStats = null;
        $subjectFound = false;
        if ($subjectMatch !== null) {
            $subjectFound = true;

            // Rank among similar
            $rankViews   = $this->computeRank($enriched, $subjectMatch['id'], 'views');
            $rankMatches = $this->computeRank($enriched, $subjectMatch['id'], 'matches');
            $total = $enriched->count();

            $subjectStats = [
                'views'          => $subjectMatch['views'],
                'matches'        => $subjectMatch['matches'],
                'days_on_market' => $subjectMatch['days_on_market'],
                'views_per_day'  => $subjectMatch['views_per_day'],
                'rank_views'     => $rankViews !== null ? $this->ordinal($rankViews) . " of {$total}" : null,
                'rank_matches'   => $rankMatches !== null ? $this->ordinal($rankMatches) . " of {$total}" : null,
            ];
        }

        // Generate insights
        $insights = $this->generateInsights($avgMatches, $avgViews, $avgDom, $avgVpd);

        // Market signal
        $subjectDom = $subjectMatch['days_on_market'] ?? null;
        [$marketSignal, $marketSignalText] = $this->determineMarketSignal(
            $avgMatches, $avgDom, $subjectDom, $avgViews
        );

        return [
            'has_data'        => true,
            'similar_count'   => $enriched->count(),
            'criteria'        => $criteria,

            'avg_views'       => $avgViews,
            'avg_matches'     => $avgMatches,
            'avg_days_on_market' => $avgDom,
            'avg_views_per_day'  => $avgVpd,

            'min_views'       => $viewsArr->count() > 0 ? (int) $viewsArr->min() : null,
            'max_views'       => $viewsArr->count() > 0 ? (int) $viewsArr->max() : null,
            'min_matches'     => $matchesArr->count() > 0 ? (int) $matchesArr->min() : null,
            'max_matches'     => $matchesArr->count() > 0 ? (int) $matchesArr->max() : null,
            'min_days'        => $domArr->count() > 0 ? (int) $domArr->min() : null,
            'max_days'        => $domArr->count() > 0 ? (int) $domArr->max() : null,

            'listings'        => $listingsOut,

            'subject_found'   => $subjectFound,
            'subject_stats'   => $subjectStats,

            'insights'        => $insights,

            'market_signal'      => $marketSignal,
            'market_signal_text' => $marketSignalText,

            'total_propcon_listings' => $totalPropcon,
            'target_suburbs'         => $targetSuburbs,
            'target_types'           => $targetTypes,
            'price_range'            => ['low' => $priceLow, 'high' => $priceHigh],
        ];
    }

    /**
     * Resolve target suburbs using town-level expansion (SuburbMapper first, P24Suburb fallback).
     *
     * SuburbMapper gives us all suburbs in the same town (e.g., Manaba Beach → all Margate-area suburbs).
     * P24Suburb provides surrounding suburbs from portal data as a secondary source.
     */
    private function resolveTargetSuburbs(string $suburb): array
    {
        // Primary: town-level expansion via SuburbMapper
        $townSuburbs = SuburbMapper::expandToTownArea($suburb);

        // Secondary: P24 surrounding suburbs (may include areas the mapper doesn't have)
        $record = P24Suburb::lookup($suburb);
        if ($record) {
            $surroundingIds = $record->surrounding_ids;
            if (!empty($surroundingIds) && is_array($surroundingIds)) {
                $p24Surrounding = P24Suburb::whereIn('p24_id', $surroundingIds)->pluck('name')->toArray();
                $townSuburbs = array_merge($townSuburbs, $p24Surrounding);
            }
        }

        return array_unique($townSuburbs);
    }

    /**
     * Map presentation property_type to PropCon type values.
     */
    private function mapPropertyTypes(?string $presentationType): array
    {
        if (empty($presentationType)) {
            return [];
        }

        return match (strtolower($presentationType)) {
            'house'       => ['House'],
            'townhouse'   => ['Townhouse'],
            'apartment'   => ['Apartment', 'Flat'],
            'flat'        => ['Apartment', 'Flat'],
            'duplex'      => ['Townhouse', 'Apartment', 'Duplex'],
            'vacant_land' => ['Vacant Land'],
            'farm'        => ['Farm'],
            default       => [],
        };
    }

    /**
     * Extract an integer value from raw_payload trying multiple key variations.
     */
    private function extractPayloadInt(array $raw, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($raw[$key]) && $raw[$key] !== '' && $raw[$key] !== null) {
                $val = $raw[$key];
                if (is_numeric($val)) {
                    return (int) $val;
                }
                // Strip non-numeric chars (e.g. "1 234")
                $cleaned = preg_replace('/[^0-9]/', '', (string) $val);
                if ($cleaned !== '' && is_numeric($cleaned)) {
                    return (int) $cleaned;
                }
            }
        }
        return null;
    }

    /**
     * Extract a string value from raw_payload trying multiple key variations.
     */
    private function extractPayloadString(array $raw, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($raw[$key]) && $raw[$key] !== '' && $raw[$key] !== null) {
                return trim((string) $raw[$key]);
            }
        }
        return null;
    }

    /**
     * Clean up PropCon address for display (trim, collapse whitespace).
     */
    private function cleanAddress(?string $address): ?string
    {
        if (!$address) return null;
        // Replace newlines/multiple spaces with comma-space
        $address = preg_replace('/[\r\n]+/', ', ', $address);
        $address = preg_replace('/\s{2,}/', ' ', $address);
        return trim($address);
    }

    /**
     * Try to find the subject property in the PropCon data.
     * Matches on suburb + beds + approximate price.
     */
    private function findSubjectProperty(Collection $enriched, Presentation $presentation): ?array
    {
        $askingPrice = $presentation->asking_price_inc;
        $bedrooms    = $presentation->bedrooms;
        $address     = strtolower(trim($presentation->property_address ?? ''));

        if (empty($address) && empty($askingPrice)) return null;

        // Try address substring match first
        if ($address !== '') {
            // Extract street number + name for matching
            foreach ($enriched as $item) {
                $itemAddr = strtolower($item['address'] ?? '');
                if ($itemAddr === '') continue;

                // If the presentation address is found within the PropCon address
                if (str_contains($itemAddr, $address) || str_contains($address, $itemAddr)) {
                    return $item;
                }
            }
        }

        // Fallback: match on beds + approximate price (within 5%)
        if ($askingPrice && $bedrooms) {
            $priceTolerance = $askingPrice * 0.05;
            foreach ($enriched as $item) {
                if ($item['beds'] === $bedrooms && $item['price'] !== null) {
                    if (abs($item['price'] - $askingPrice) <= $priceTolerance) {
                        return $item;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Compute rank (1 = highest) for a listing in a collection by a given field.
     */
    private function computeRank(Collection $enriched, int $id, string $field): ?int
    {
        $sorted = $enriched->filter(fn($i) => $i[$field] !== null)
            ->sortByDesc($field)
            ->values();

        foreach ($sorted as $idx => $item) {
            if ($item['id'] === $id) {
                return $idx + 1;
            }
        }

        return null;
    }

    /**
     * Convert number to ordinal string (1st, 2nd, 3rd...).
     */
    private function ordinal(int $n): string
    {
        $suffix = match ($n % 100) {
            11, 12, 13 => 'th',
            default => match ($n % 10) {
                1 => 'st',
                2 => 'nd',
                3 => 'rd',
                default => 'th',
            },
        };
        return $n . $suffix;
    }

    /**
     * Generate human-readable insight sentences.
     */
    private function generateInsights(?int $avgMatches, ?int $avgViews, ?int $avgDom, ?float $avgVpd): array
    {
        $insights = [];

        if ($avgMatches !== null && $avgViews !== null) {
            $insights[] = "Similar properties are averaging {$avgMatches} buyer matches and " . number_format($avgViews) . " views.";
        }

        if ($avgDom !== null) {
            $insights[] = "The average days on market for similar stock is {$avgDom} days.";
        }

        if ($avgVpd !== null) {
            $insights[] = "Properties in this bracket average {$avgVpd} views per day.";
        }

        return $insights;
    }

    /**
     * Determine market signal based on aggregate metrics.
     *
     * @return array [signal_key, signal_text]
     */
    private function determineMarketSignal(?int $avgMatches, ?int $avgDom, ?int $subjectDom, ?int $avgViews): array
    {
        // New listing — too early to read signals
        if ($subjectDom !== null && $subjectDom < 14) {
            return [
                'new_listing',
                'This listing is new — performance data will become meaningful after 2–3 weeks on the market.',
            ];
        }

        // Not enough data to determine signal
        if ($avgMatches === null && $avgViews === null) {
            return [
                'insufficient_data',
                'Not enough performance data to determine a market signal.',
            ];
        }

        // Price issue: lots of interest but not converting
        if ($avgMatches !== null && $avgMatches > 30 && $avgDom !== null && $avgDom > 60) {
            return [
                'price_issue',
                "Similar properties are attracting strong buyer interest ({$avgMatches} avg matches) but taking {$avgDom} days to sell — this suggests pricing is the main friction point in this market segment.",
            ];
        }

        // Visibility issue: not enough eyeballs
        if ($avgMatches !== null && $avgMatches < 10 && $avgViews !== null && $avgViews < 500) {
            return [
                'visibility_issue',
                'Similar properties are receiving low portal engagement — consider marketing strategy and portal visibility.',
            ];
        }

        // Healthy market: good interest, selling in reasonable time
        if ($avgMatches !== null && $avgMatches > 20 && $avgDom !== null && $avgDom < 45) {
            return [
                'healthy',
                'Similar properties are selling well with good interest levels — the market is active in this segment.',
            ];
        }

        // Default interpretation based on what data we have
        if ($avgDom !== null && $avgDom > 90) {
            return [
                'price_issue',
                "Similar properties are averaging {$avgDom} days on market — extended time on market typically indicates pricing misalignment.",
            ];
        }

        if ($avgMatches !== null && $avgMatches > 20) {
            return [
                'healthy',
                "Good buyer interest in this segment ({$avgMatches} avg matches). Competitive pricing will help convert interest to offers.",
            ];
        }

        return [
            'moderate',
            'Market activity for similar properties is moderate. Pricing competitively and maintaining portal presence will help attract buyers.',
        ];
    }

    /**
     * Return empty result when data is insufficient.
     */
    private function emptyResult(
        string $reason,
        ?int $totalPropcon = null,
        ?array $targetSuburbs = null,
        ?array $targetTypes = null,
        ?int $priceLow = null,
        ?int $priceHigh = null,
    ): array {
        return [
            'has_data'               => false,
            'reason'                 => $reason,
            'similar_count'          => 0,
            'total_propcon_listings' => $totalPropcon ?? ListingStock::where('source', 'propcon')->count(),
            'target_suburbs'         => $targetSuburbs ?? [],
            'target_types'           => $targetTypes ?? [],
            'price_range'            => $priceLow !== null ? ['low' => $priceLow, 'high' => $priceHigh] : null,
        ];
    }
}
