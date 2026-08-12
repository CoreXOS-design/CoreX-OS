<?php

namespace App\Services\Prospecting;

use App\Models\Property;
use App\Models\ProspectingListing;
use Illuminate\Support\Facades\Log;

class ProspectingStockMatchService
{
    /**
     * 2026-08-12 (Johan's ruling) — generic/non-distinguishing address tokens
     * that must NEVER count as a "significant" street-name word in Pass 2.
     * Unit/complex descriptors and street-TYPE suffixes appear across
     * countless UNRELATED addresses; before this list existed they let two
     * totally different buildings "match" purely on a shared filler word —
     * confirmed root causes of two live false positives: property #4243
     * ("...Flat 3") matched a PP listing ("...Holiday Flats") on "flat" as a
     * SUBSTRING of "flats" (not even the same word), and separately several
     * properties matched unrelated listings purely because both addresses
     * happened to end in "Street". Suburb names are excluded per-comparison
     * (see matchProspect()) since normalizeAddress() appends the suburb to
     * BOTH sides, so it would otherwise always "match" trivially.
     */
    private const GENERIC_ADDRESS_WORDS = [
        'unit', 'flat', 'flats', 'section', 'block', 'holiday', 'erf', 'door', 'the',
        'street', 'road', 'drive', 'avenue', 'place', 'close', 'lane', 'way',
        'boulevard', 'crescent', 'grove', 'view', 'park', 'gardens', 'complex',
    ];

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

        // Pass 2: fuzzy — same suburb + STRUCTURED street number + a real
        // street-name word, both compared precisely (2026-08-12, Johan's
        // ruling: "a wishlist/match means exactly what it says — no
        // cleverness, must be defendable"). Rebuilt from the ground up after
        // two confirmed live false positives (property #4243 vs a PP listing
        // for a different building 590m away; property #2654 "46 Marine
        // Drive" vs "46 Taylor Road") — both fired on a bare coincidental
        // number plus a generic/substring word match, never on real address
        // content. See the class-level GENERIC_ADDRESS_WORDS note.
        $prospectSuburb = strtolower(trim($prospect->suburb ?? ''));
        if (!$prospectSuburb) {
            $this->clearMatch($prospect);
            return null;
        }

        // The prospect's OWN structured street number. Prospecting listings
        // carry no dedicated column — only free text. Shared parser (see
        // ProspectingListing::parseStreetNumber) so this and the Pitch Now
        // collision check (EntryPointController -> MapProspectStatusService)
        // can never drift on what counts as "the number".
        $prospectNumber = ProspectingListing::parseStreetNumber($prospect->address);

        // Prospect has no readable street number at all — per Johan's ruling,
        // Pass 2 must not fire on number alone (there IS no number to gate
        // on), so nothing in this suburb can fuzzy-match. Belt-and-braces:
        // Pass 1 (exact normalized match) already had first refusal above.
        if (!$prospectNumber) {
            $this->clearMatch($prospect);
            return null;
        }

        foreach ($properties as $prop) {
            $propSuburb = strtolower(trim($prop->suburb ?? ''));
            if ($propSuburb !== $prospectSuburb) {
                continue;
            }

            // The property's STRUCTURED street number — prefer the dedicated
            // column; most rows in this dataset have it NULL with the number
            // written inline at the front of street_name instead ("30 Queen
            // Street"), so fall back to that leading token only. Do NOT
            // search for the number anywhere in property.address free text —
            // that loose search is exactly what let a coincidental complex/
            // unit number stand in for the real street number before.
            $propNumber = $prop->street_number ? trim((string) $prop->street_number) : null;
            if (!$propNumber && $prop->street_name) {
                if (preg_match('/^(\d+)\b/', strtolower(trim($prop->street_name)), $numMatch2)) {
                    $propNumber = $numMatch2[1];
                }
            }

            // Neither a dedicated street_number nor a readable leading number
            // in street_name — no real structured number to compare against.
            // Per Johan's ruling, skip rather than guess.
            if (!$propNumber) {
                continue;
            }

            // FIELD-TO-FIELD equality, not "does this number appear somewhere
            // in the other address's free text" — the exact distinction that
            // let property #4243's street_number "14" match the unrelated
            // "14 Dumela Holiday Flats" complex name.
            if ($propNumber !== $prospectNumber) {
                continue;
            }

            // Real street-name word match: word-boundary (not str_contains —
            // "flat" must never match inside "flats"), excluding generic
            // descriptor / street-type words AND the shared suburb name (which
            // normalizeAddress() appends to both sides, so it would otherwise
            // always "match" regardless of the actual street).
            $propNameSource = $prop->street_name ?: ($prop->address ?? '');
            $propWords = preg_split('/\s+/', preg_replace('/[^a-z\s]/', '', strtolower($propNameSource)));
            $propWords = array_filter($propWords, fn ($w) => strlen($w) > 3
                && !in_array($w, self::GENERIC_ADDRESS_WORDS, true)
                && $w !== $prospectSuburb);

            if (empty($propWords)) {
                // No real distinguishing street-name word survives filtering —
                // a bare number match alone is not enough (the exact 46
                // Taylor / #4243 failure mode). Skip.
                continue;
            }

            $matched = false;
            foreach ($propWords as $word) {
                if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $prospectNorm)) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
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
