<?php

namespace App\Services\Prospecting;

use App\Models\Property;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\ProspectingListing;
use Illuminate\Support\Facades\Log;

class ProspectingStockMatchService
{
    /** ~25m GPS tolerance for the possible-match fallback — same figure the deeds matcher uses. */
    private const POSSIBLE_MATCH_GPS_TOLERANCE_DEGREES = 0.00025;
    private const POSSIBLE_MATCH_GPS_TOLERANCE_METRES = 25.0;
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
     * POSSIBLE-tier fallback (2026-08-22, Johan — the 43 Ridge investigation).
     * Runs ONLY when matchProspect() (Pass 1/2, above) found nothing — never
     * overrides a confident match, never itself confident on GPS alone
     * (Johan's rule #1: "a GPS hit on its own must never produce a confident
     * match"). Advisory only; writes to possible_property_id/
     * possible_match_verdict/possible_match_candidate_ids, a SEPARATE column
     * set from matched_property_id — never blocks anything, never suppresses
     * a listing from the prospectable pool the way a confident match does.
     *
     * Called ONLY from the async ComputePossibleStockMatchJob (queue
     * 'matching') — never from a request thread, never from the MIC list
     * page's read path. See that job's docblock for why.
     *
     * Sectional vs. freehold: Property.title_type (2026-08-22 investigation —
     * explicit, 100% populated on this environment, validated against real
     * cases; NOT inferred from complex_name/unit_number presence, which are
     * each populated on well under 100% of sectional rows on their own).
     *
     * Complex-coherence filter (Johan's rule #2, the one that matters): GPS
     * proximity alone is NOT reliable even at geo_confidence='exact' — real
     * measurement on this environment found one 'exact'-confidence
     * coordinate shared by 110 properties spanning dozens of UNRELATED
     * complexes (a reused/road-level geocode, not one building), and overall
     * 54% of multi-property exact/street coordinate clusters here are this
     * kind of noise, not a real building. Grouping candidates by
     * complex_name (falling back to street_number+street_name when blank)
     * and keeping ONLY the dominant coherent group is what separates Villa
     * Del Sol's real 8 units from the ~100 unrelated properties sharing its
     * coordinate — the scattered remainder is excluded entirely, never
     * offered to an agent as if it meant something.
     *
     * Unit-number confidence gate (Johan's rule #3): within a coherent
     * sectional cluster, a leading-zero-normalised match between the
     * listing's parsed unit number (ProspectingListing::parseUnitNumber())
     * and a candidate's unit_number IS confident — GPS narrows to "this
     * building", the unit number then narrows to "this exact unit", and the
     * two together identify one property exactly, same as an address match
     * would. Without a parseable/matching unit number: POSSIBLE, worded
     * honestly ("we hold something in this complex, can't tell which unit").
     *
     * Freehold path (Johan's rule #4): title_type=full_title, GPS carries
     * real weight — but per rule #1, GPS ALONE (even a single clean
     * candidate — 43 Ridge's own shape) never promotes past POSSIBLE; there
     * is no second signal the way unit-number is for sectional. Two rows at
     * the same freehold address (the 47 Howard / 43-Ridge-duplicate pattern)
     * stays POSSIBLE on ambiguity grounds regardless of GPS.
     *
     * @return array{verdict: string, property_id: ?int, candidate_ids: array<int,int>}|null
     *         verdict: 'confident_sectional_unit' | 'possible_sectional_no_unit'
     *                | 'possible_freehold_single' | 'possible_freehold_ambiguous'
     *         null: nothing found, or the only coordinate hit was noise (excluded).
     */
    public function findPossibleMatch(ProspectingListing $prospect): ?array
    {
        if ($prospect->matched_property_id !== null) {
            return null; // already confidently matched via Pass 1/2 — nothing to add
        }
        $agencyId = $prospect->agency_id;
        if (!$agencyId || !$prospect->latitude || !$prospect->longitude || (float) $prospect->latitude === 0.0) {
            return null;
        }

        // Same on-market-only rule as matchProspect() — one source of truth
        // (Property::OFF_MARKET_STATUSES via onMarket()), not a fork.
        $tol = self::POSSIBLE_MATCH_GPS_TOLERANCE_DEGREES;
        $nearby = Property::withoutGlobalScopes()
            ->onMarket()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->whereIn('geo_confidence', ['exact', 'street'])
            ->whereBetween('latitude', [(float) $prospect->latitude - $tol, (float) $prospect->latitude + $tol])
            ->whereBetween('longitude', [(float) $prospect->longitude - $tol, (float) $prospect->longitude + $tol])
            ->get(['id', 'title_type', 'complex_name', 'unit_number', 'street_number', 'street_name', 'suburb', 'latitude', 'longitude']);

        if ($nearby->isEmpty()) {
            return null;
        }

        $withinRadius = $nearby->filter(fn ($p) => TrackedPropertyAddress::haversineMetres(
            (float) $prospect->latitude, (float) $prospect->longitude, (float) $p->latitude, (float) $p->longitude
        ) <= self::POSSIBLE_MATCH_GPS_TOLERANCE_METRES)->values();

        if ($withinRadius->isEmpty()) {
            return null;
        }

        $coherent = $this->coherentCandidateGroup($withinRadius, $prospect);
        if ($coherent === null) {
            // Scattered across unrelated identities at one coordinate — a bad/
            // reused geocode, not a building. Excluded entirely (Johan's rule).
            return null;
        }

        $isSectional = $coherent->contains(fn ($p) => $p->title_type === 'sectional_title');

        if ($isSectional) {
            $parsedUnit = ProspectingListing::parseUnitNumber($prospect->address);
            if ($parsedUnit !== null) {
                $unitKey = TrackedPropertyAddress::normaliseNumericIdentifier($parsedUnit);
                $exact = $coherent->first(
                    fn ($p) => $p->unit_number !== null
                        && TrackedPropertyAddress::normaliseNumericIdentifier($p->unit_number) === $unitKey
                );
                if ($exact) {
                    return [
                        'verdict' => 'confident_sectional_unit',
                        'property_id' => (int) $exact->id,
                        'candidate_ids' => [(int) $exact->id],
                    ];
                }
            }

            return [
                'verdict' => 'possible_sectional_no_unit',
                'property_id' => null,
                'candidate_ids' => $coherent->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ];
        }

        // Freehold — GPS carries real weight but is never sufficient alone
        // (rule #1). A single clean candidate is still only POSSIBLE; two or
        // more at the same address is the 47 Howard / 43 Ridge pattern.
        return [
            'verdict' => $coherent->count() === 1 ? 'possible_freehold_single' : 'possible_freehold_ambiguous',
            'property_id' => null,
            'candidate_ids' => $coherent->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    /**
     * Complex-coherence filter (Johan's rule #2). Groups GPS-radius candidates
     * by normalised complex_name, falling back to street_number+street_name
     * when complex_name is blank on a given row (this matters: only 27% of
     * sectional_title properties on this environment have complex_name
     * populated — a group key that required it would miss most real
     * buildings).
     *
     * 2026-08-22 fix, found DURING this build: an earlier version of this
     * method required the largest group to cover >=70% of every candidate
     * at the coordinate — that FAILS Villa Del Sol itself, its own
     * acceptance test. Villa Del Sol's real 8 units sit inside a coordinate
     * shared by 110 properties across dozens of unrelated complexes; 8/110
     * can never reach 70% no matter how genuinely coherent those 8 are. A
     * "biggest group wins" vote is the wrong question — a busy shared
     * coordinate can legitimately hold many small, real, DIFFERENT
     * buildings, and picking the biggest is not the same as picking the
     * right one.
     *
     * The right question: which group is this SPECIFIC listing actually
     * about? Portal listings name their own building in the free text
     * (confirmed on real Staging data: "5 Ss Ketamina Flats, 987 Henry
     * Road" — the complex name IS the first segment) — so a group whose
     * complex_name (or street identity) is referenced in the listing's own
     * address text is the coherent one, regardless of how much unrelated
     * noise shares its coordinate. Only ONE group may match; if the
     * listing's own text doesn't clearly point at exactly one of them (no
     * match, or more than one), the coordinate is unidentifiable from what
     * we're told — excluded entirely, same as scattered noise. A coordinate
     * holding only ONE group in the first place needs no cross-reference —
     * there's nothing else it could be.
     *
     * @param  \Illuminate\Support\Collection<int, Property>  $candidates
     * @param  ProspectingListing  $prospect
     * @return \Illuminate\Support\Collection<int, Property>|null
     */
    private function coherentCandidateGroup($candidates, ProspectingListing $prospect)
    {
        // Real data-quality wrinkle found DURING this build: 7 real properties
        // at Villa Del Sol's own coordinate carry complex_name="Marine Drive" —
        // the street name, mis-captured into the complex field, with street_name
        // itself left blank. A genuine sectional-title scheme is a proper name
        // ("Villa Del Sol", "Whale Rock", "Santorini"); it does not bare-end in
        // a street-type suffix. Without this check, "141 Marine Drive" in the
        // listing's own address ALSO cross-references the "Marine Drive"
        // complex_name group, making the match genuinely ambiguous (two groups
        // hit) and defeating Villa Del Sol's own acceptance test. Treated as a
        // street-fallback identity instead — never eligible to win a cross-
        // reference vote on its own.
        $looksLikeStreetNotComplex = fn (string $name): bool => (bool) preg_match(
            '/\b(street|road|drive|avenue|lane|close|crescent|boulevard|way)$/i', trim($name)
        );

        $groupKey = function ($p) use ($looksLikeStreetNotComplex) {
            $complex = trim((string) ($p->complex_name ?? ''));
            if ($complex !== '' && !$looksLikeStreetNotComplex($complex)) {
                return 'complex:' . mb_strtolower($complex);
            }

            return 'street:' . trim((string) $p->street_number) . '|' . mb_strtolower(trim((string) $p->street_name));
        };

        $groups = $candidates->groupBy($groupKey);

        if ($groups->count() === 1) {
            return $groups->first()->values();
        }

        $addressHaystack = mb_strtolower((string) $prospect->address);
        $matchingGroups = $groups->filter(function ($group, $key) use ($addressHaystack) {
            $identity = str_starts_with($key, 'complex:') ? substr($key, 8) : null;
            if ($identity === null || $identity === '') {
                return false; // an unnamed street-fallback group is never cross-referenceable
            }
            // Word-boundary containment, not a bare substring — "5" must not
            // match inside "15", and a short/generic complex label must not
            // spuriously match unrelated text.
            return mb_strlen($identity) >= 3 && preg_match('/\b' . preg_quote($identity, '/') . '\b/u', $addressHaystack) === 1;
        });

        if ($matchingGroups->count() !== 1) {
            // Zero matches: can't tell what this listing refers to at a
            // shared coordinate — noise. More than one match: genuinely
            // ambiguous which building the listing means — also excluded,
            // never guessed.
            return null;
        }

        return $matchingGroups->first()->values();
    }

    /**
     * Persist a findPossibleMatch() result (or clear a stale one) — the ONLY
     * writer of the possible_* columns. Called from ComputePossibleStockMatchJob.
     * updateQuietly — this is a background computation, not a user action; it
     * must not re-fire observers/notifications the way a real edit would.
     */
    public function setPossibleMatch(ProspectingListing $prospect, ?array $result): void
    {
        $prospect->updateQuietly([
            'possible_property_id' => $result['property_id'] ?? null,
            'possible_match_verdict' => $result['verdict'] ?? null,
            'possible_match_candidate_ids' => $result ? ($result['candidate_ids'] ?? []) : null,
            'possible_matched_at' => $result ? now() : null,
        ]);
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
