<?php

namespace App\Services\Prospecting;

use App\Models\Property;
use App\Models\ProspectingListing;
use Illuminate\Support\Facades\Log;

class ProspectingStockMatchService
{
    /**
     * Try to match a single prospect to an agency property.
     * Returns the matched Property or null.
     */
    public function matchProspect(ProspectingListing $prospect): ?Property
    {
        $agencyId = $prospect->agency_id;
        if (!$agencyId) {
            return null;
        }

        $prospectNorm = $prospect->normalized_address;
        if (!$prospectNorm) {
            $this->clearMatch($prospect);
            return null;
        }

        // Load agency stock (properties with addresses). ON-MARKET ONLY:
        // a prospecting listing may only be badged IN STOCK / suppressed from
        // the prospectable pool when we still hold it live on the market. Stock
        // that has gone sold/withdrawn/expired/cancelled/let-out/etc. is
        // off-market and the listing is a legitimate canvass target again, so it
        // must NOT match. scopeOnMarket() is the single source of truth for the
        // status list (Property::OFF_MARKET_STATUSES) — do not fork it here.
        // Because both directions (forward ingest match + reverse property-save
        // match via matchAllForProperty) funnel through this method, this one
        // filter governs every write of matched_property_id by the matcher.
        $properties = Property::withoutGlobalScopes()
            ->onMarket()
            ->where('agency_id', $agencyId)
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->whereNull('deleted_at')
            ->get(['id', 'address', 'suburb', 'street_name', 'street_number']);

        // Pass 1: exact normalized match
        foreach ($properties as $prop) {
            $propNorm = ProspectingListing::normalizeAddress($prop->address, $prop->suburb ?? '');
            if ($propNorm && $propNorm === $prospectNorm) {
                $this->setMatch($prospect, $prop);
                return $prop;
            }
        }

        // Pass 2: fuzzy — same suburb + street overlap
        $prospectSuburb = strtolower(trim($prospect->suburb ?? ''));
        if (!$prospectSuburb) {
            $this->clearMatch($prospect);
            return null;
        }

        foreach ($properties as $prop) {
            $propSuburb = strtolower(trim($prop->suburb ?? ''));
            if ($propSuburb !== $prospectSuburb) {
                continue;
            }

            // Extract street number from property address
            $propAddr = strtolower(trim($prop->address ?? ''));
            if (!$propAddr) {
                continue;
            }

            // Extract leading street number
            $propNumber = null;
            if (preg_match('/^(\d+)\b/', $propAddr, $numMatch)) {
                $propNumber = $numMatch[1];
            } elseif ($prop->street_number) {
                $propNumber = trim($prop->street_number);
            }

            // Must have a street number to fuzzy match — without it, too many false positives
            if (!$propNumber) {
                continue;
            }

            // Prospect must contain the same street number at a word boundary
            if (!preg_match('/\b' . preg_quote($propNumber, '/') . '\b/', $prospectNorm)) {
                continue;
            }

            // Also require a significant street name word match (3+ char words only)
            $propWords = array_filter(preg_split('/\s+/', preg_replace('/[^a-z\s]/', '', $propAddr)));
            $propWords = array_filter($propWords, fn($w) => strlen($w) > 3); // skip short/common words

            if (empty($propWords)) {
                continue;
            }

            $matchedWords = 0;
            foreach ($propWords as $word) {
                if (str_contains($prospectNorm, $word)) {
                    $matchedWords++;
                }
            }

            // Require street number match + at least 1 significant street name word
            if ($matchedWords >= 1) {
                $this->setMatch($prospect, $prop);
                return $prop;
            }
        }

        $this->clearMatch($prospect);
        return null;
    }

    /**
     * Reverse path — a property was created/updated. Two cases, both keeping the
     * "matched_property_id only ever points at on-market stock" invariant:
     *
     *  - OFF-MARKET property → it must hold NO IN STOCK badges. Clear every
     *    listing currently matched to it so they return to the prospectable
     *    pool (a withdrawn/sold/expired property is a legitimate canvass target
     *    again). Returns the number cleared.
     *  - ON-MARKET property → find unmatched prospects in the same suburb and
     *    match them (the original behaviour). Returns the number matched.
     *
     * Triggered from PropertyObserver on address OR status changes, so an
     * on→off-market transition self-heals stale badges without a manual
     * recompute. isOnMarket() shares Property::OFF_MARKET_STATUSES with the
     * forward filter — one source of truth, no fork.
     */
    public function matchAllForProperty(Property $property): int
    {
        if (! $property->isOnMarket()) {
            return $this->clearMatchesForProperty($property);
        }

        $suburb = strtolower(trim($property->suburb ?? ''));
        if (!$suburb || !$property->address) {
            return 0;
        }

        $propNorm = ProspectingListing::normalizeAddress($property->address, $property->suburb ?? '');
        if (!$propNorm) {
            return 0;
        }

        // Find unmatched prospects in the same suburb
        $prospects = ProspectingListing::where('agency_id', $property->agency_id)
            ->whereNull('matched_property_id')
            ->whereRaw('LOWER(TRIM(suburb)) = ?', [$suburb])
            ->whereNull('deleted_at')
            ->get();

        $matched = 0;
        foreach ($prospects as $prospect) {
            $result = $this->matchProspect($prospect);
            if ($result) {
                $matched++;
            }
        }

        if ($matched > 0) {
            Log::info('Prospecting stock matches from property', [
                'property_id' => $property->id,
                'matched'     => $matched,
            ]);
        }

        return $matched;
    }

    /**
     * Recompute all matches for an agency.
     */
    public function recomputeAllForAgency(int $agencyId): array
    {
        $prospects = ProspectingListing::where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->get();

        $matched = 0;
        $unmatched = 0;

        foreach ($prospects as $prospect) {
            $result = $this->matchProspect($prospect);
            if ($result) {
                $matched++;
            } else {
                $unmatched++;
            }
        }

        return ['matched' => $matched, 'unmatched' => $unmatched, 'total' => $prospects->count()];
    }

    /**
     * Clear every prospecting listing matched to a now-off-market property,
     * returning them to the prospectable pool. Idempotent — clearMatch() no-ops
     * on already-null rows. Uses updateQuietly (via clearMatch) so it does not
     * re-fire the ProspectingListing observer / syndication cascades.
     */
    public function clearMatchesForProperty(Property $property): int
    {
        $listings = ProspectingListing::where('matched_property_id', $property->id)
            ->whereNull('deleted_at')
            ->get();

        $cleared = 0;
        foreach ($listings as $listing) {
            $this->clearMatch($listing);
            $cleared++;
        }

        if ($cleared > 0) {
            Log::info('Cleared off-market stock matches', [
                'property_id' => $property->id,
                'status'      => $property->status,
                'cleared'     => $cleared,
            ]);
        }

        return $cleared;
    }

    private function setMatch(ProspectingListing $prospect, Property $property): void
    {
        if ($prospect->matched_property_id !== $property->id) {
            $prospect->updateQuietly([
                'matched_property_id' => $property->id,
                'matched_at'          => now(),
            ]);
        }
    }

    private function clearMatch(ProspectingListing $prospect): void
    {
        if ($prospect->matched_property_id !== null) {
            $prospect->updateQuietly([
                'matched_property_id' => null,
                'matched_at'          => null,
            ]);
        }
    }
}
