<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Agency;
use App\Models\ContactMatch;
use App\Models\ListingStock;
use App\Models\Property;
use App\Services\PropertyMatchScoringService;
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
        if (!$subject->agency_id || !$subject->price || !$subject->suburb) {
            return collect();
        }

        $agency = Agency::find($subject->agency_id);
        $bedsTol   = (int) ($agency?->competitor_stock_default_beds_tolerance      ?? 1);
        $pricePct  = (int) ($agency?->competitor_stock_default_price_tolerance_pct ?? 20);
        $threshold = $overrideMinScore ?? (int) ($agency?->competitor_stock_min_score ?? 50);

        $synthMatch = $this->buildSyntheticMatch($subject, $bedsTol, $pricePct);
        $candidates = $this->loadCandidates($subject, $pricePct);
        if ($candidates->isEmpty()) {
            return collect();
        }

        $hfcStockMap = $this->loadHfcStockMap((int) $subject->agency_id);
        $scorer      = app(PropertyMatchScoringService::class);

        return $candidates->map(function (object $listing) use ($scorer, $synthMatch, $hfcStockMap) {
            $result = $scorer->scoreProspectingCapture($synthMatch, $listing);
            $stock  = $hfcStockMap[$this->stockKey($listing)] ?? null;

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
                'is_hfc_owned'     => $stock !== null,
                'days_on_market'   => $stock ? $this->intOrNull($stock->days_on_market) : null,
                'views'            => $stock ? $this->extractPayloadInt($stock, ['Views', 'views', 'Portal Views', 'portal views', 'PortalViews']) : null,
                'matches'          => $stock ? $this->extractPayloadInt($stock, ['Matches', 'matches', 'Buyer Matches', 'buyer matches', 'BuyerMatches']) : null,
            ];
        })
        ->filter(fn (array $row) => $row['score'] >= $threshold)
        ->sortByDesc('score')
        ->values();
    }

    /**
     * Build an unsaved ContactMatch from the subject's profile. The
     * Core Matches scorer reads attributes + p24SuburbIdList() +
     * propertyTypeList(); a freshly-instantiated ContactMatch with
     * the right attribute values exposes both methods unchanged
     * (they read $this->p24_suburb_ids and $this->property_types via
     * the array casts).
     *
     * No must-haves or deal-breakers — scoring is soft for the
     * competitor view (we're identifying "near competitors", not
     * filtering for a buyer's hard rules).
     */
    private function buildSyntheticMatch(Property $subject, int $bedsTol, int $pricePct): ContactMatch
    {
        $price = (int) $subject->price;
        $match = new ContactMatch();
        $match->agency_id = $subject->agency_id;
        $match->status    = ContactMatch::STATUS_ACTIVE;
        $match->price_min = (int) round($price * (1 - $pricePct / 100));
        $match->price_max = (int) round($price * (1 + $pricePct / 100));

        if ($subject->beds !== null) {
            $match->beds_min     = max(0, (int) $subject->beds - $bedsTol);
            $match->bedrooms_max = (int) $subject->beds + $bedsTol;
        }
        if ($subject->p24_suburb_id) {
            $match->p24_suburb_ids = [(int) $subject->p24_suburb_id];
        }
        if (!empty($subject->property_type)) {
            $match->property_types = [(string) $subject->property_type];
            $match->property_type  = (string) $subject->property_type;
        }
        // Soft scoring — leave must_have_features + deal_breakers empty.
        $match->must_have_features = [];
        $match->deal_breakers      = [];

        return $match;
    }

    /**
     * Pull prospecting_listings candidates within the price band and
     * loose suburb match. Beds tolerance + the full scoring run as
     * the PHP-side narrow; SQL is conservative-but-broad so the
     * engine sees every plausible row.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function loadCandidates(Property $subject, int $pricePct): \Illuminate\Support\Collection
    {
        $price    = (int) $subject->price;
        $priceMin = (int) round($price * (1 - $pricePct / 100));
        $priceMax = (int) round($price * (1 + $pricePct / 100));

        // Suburb pre-filter — broad LIKE on the SuburbMatcher core
        // token. Mirrors the comp-pool fix so "Uvongo Beach" subject
        // catches "Uvongo" prospecting rows. PHP-side scoreArea
        // narrows the final match.
        $subjectCore = SuburbMatcher::normaliseSuburbToken((string) $subject->suburb);
        $coreLike    = $subjectCore !== '' ? '%' . $subjectCore . '%' : '%';

        $rows = DB::table('prospecting_listings')
            ->where('agency_id', $subject->agency_id)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->whereBetween('price', [$priceMin, $priceMax])
            ->whereRaw('LOWER(suburb) LIKE ?', [$coreLike])
            ->select([
                'id', 'address', 'suburb', 'price', 'bedrooms', 'bathrooms', 'garages',
                'property_size_m2', 'erf_size_m2', 'property_type',
                'portal_url', 'portal_source', 'portal_ref',
                'agent_name', 'agency_name', 'thumbnail_path',
                'first_seen_at', 'last_seen_at',
            ])
            ->get();

        // Adapt each row to the loose shape scoreProspectingCapture
        // expects (price / suburb / property_type / beds; everything
        // else passes through for the card).
        return $rows->map(function ($row) {
            $obj = (object) [
                'id'               => (int) $row->id,
                'price'            => (int) $row->price,
                'suburb'           => $row->suburb,
                'property_type'    => $row->property_type,
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
            return $obj;
        });
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
