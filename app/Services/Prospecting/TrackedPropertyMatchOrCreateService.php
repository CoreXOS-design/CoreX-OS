<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Events\Prospecting\TrackedPropertyCreated;
use App\Events\Prospecting\TrackedPropertyEnriched;
use App\Events\Prospecting\TrackedPropertyPromotedToStock;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\Prospecting\TrackedPropertyExternalRef;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * THE central hub for Universal Match-or-Create.
 *
 * Every ingestion path in CoreX (CMA propagation, P24 alerts, PP feed,
 * Chrome capture, manual entry, mandate signing) calls matchOrCreate()
 * before deciding what to do with incoming property data.
 *
 * Resolution strategy (priority order, first match wins):
 *   0. Address-history match — incoming address found in tracked_property_addresses
 *      (Phase C2 silent-killer fix: agent edits an address once, every future
 *      wrong-address ingestion re-resolves to the same TP). Confidence-ordered.
 *   1. Source-ref match — exact match in tracked_property_external_refs
 *   2. GPS proximity match — within ~5m on cma_gps_lat/lng OR lat/lng
 *   3. Erf number + suburb match
 *   4. Normalised address match (street_number + street_name + suburb_normalised)
 *   5. Token-overlap address match (loose, last resort)
 *
 * Always returns the TrackedProperty. Always appends to source_chain.
 * Always creates/updates the corresponding tracked_property_external_refs row.
 * Always fires the appropriate domain event.
 *
 * Multi-tenancy: every read query is built via queryWithoutAgencyScope() and
 * filtered by the explicit $agencyId parameter — safe to call from queue workers
 * and console commands where there is no Auth context.
 *
 * Spec: CLAUDE.md Universal Match-or-Create Rule;
 *       .ai/specs/market-intelligence-discovery.md Section 13.2 (gap 5);
 *       VS Code build prompt Build D.1 (2026-05-14).
 */
final class TrackedPropertyMatchOrCreateService
{
    /** ~5m GPS tolerance — small enough to distinguish neighbours, large enough to absorb portal vs deeds rounding drift. */
    private const GPS_TOLERANCE_DEGREES = 0.00005;

    /**
     * Fields where a newer source's value wins over an older value.
     * Other fields keep their first non-null value (first source wins for stable identifiers).
     */
    private const NEWER_WINS_FIELDS = [
        'municipal_valuation',
        'municipal_valuation_year',
        'last_known_asking_price',
        'last_known_sold_price',
        'last_known_sold_date',
    ];

    /**
     * Source types for which EVERY field they actually captured wins over an
     * existing value — not just NEWER_WINS_FIELDS. Johan's decision,
     * 2026-08-19 (.ai/specs/deeds-capture.md §6, Part A): a value read
     * directly off the deeds panel is higher-confidence than an older
     * import, so it corrects stale data across the board, not field by
     * field. Still gated by the SAME absent-never-overwrites guard as every
     * other source below — a field the capture did not read is simply not
     * in $sanitised, so it never reaches this comparison at all.
     */
    private const SOURCE_ALWAYS_WINS = [
        'deeds_capture',
    ];

    /**
     * Placeholder strings a scraped source can send in place of a real
     * absent value. None of these may ever be written to a TrackedProperty
     * column — "absent" must mean absent, never a literal dash. Compared
     * case-insensitively after trimming.
     */
    private const ABSENT_PLACEHOLDERS = ['-', 'n/a', 'na', '—', '–'];

    /**
     * Match or create a TrackedProperty. Always returns a TrackedProperty.
     *
     * @param int $agencyId
     * @param array $facts  Canonical property facts. Recognised keys (all optional):
     *                      street_number, street_name, unit_number, complex_name,
     *                      suburb, town, province, postal_code,
     *                      latitude, longitude, cma_gps_lat, cma_gps_lng,
     *                      erf_number, title_deed_number, cadastral_extent,
     *                      municipal_valuation, municipal_valuation_year,
     *                      last_known_asking_price, last_known_sold_price, last_known_sold_date,
     *                      property_type, bedrooms, bathrooms, garages,
     *                      floor_size_m2, erf_size_m2,
     *                      address  (free-text fallback used only for token-overlap match)
     * @param array $source Source descriptor: ['type' => string, 'ref' => string, 'payload' => array|null]
     * @param ?int $actorUserId
     */
    public function matchOrCreate(
        int $agencyId,
        array $facts,
        array $source,
        ?int $actorUserId = null,
    ): TrackedProperty {
        return DB::transaction(function () use ($agencyId, $facts, $source, $actorUserId) {
            $matched = $this->resolveMatch($agencyId, $facts, $source);

            $tp = $matched
                ? $this->enrich($matched, $facts, $source, $actorUserId)
                : $this->create($agencyId, $facts, $source, $actorUserId);

            // Phase C2 — append (or bump) the ingested address in the TP's
            // address history. Failure-isolated: the underlying match-or-create
            // operation MUST succeed even if the history append blows up.
            $this->appendIngestedAddressToHistory($tp, $facts, $source);

            return $tp;
        });
    }

    /**
     * Phase A.2.5 — read-only equivalent of matchOrCreate(). Returns an
     * existing TrackedProperty when one of the 5 strategies finds a match,
     * or null without creating anything. Used by the prospect-collision
     * detector on Portal Stock cards: we need to know if HFC already has
     * this address without accidentally minting a TP from a hover.
     *
     * Wraps resolveMatch() so future strategy changes apply to both paths.
     *
     * @param array<string, mixed> $facts   Same shape as matchOrCreate.
     * @param array<string, mixed> $source  Same shape as matchOrCreate. Defaults
     *                                      to an empty source to bypass the
     *                                      source-ref strategy when the caller
     *                                      doesn't have one (matching purely on
     *                                      GPS / erf / address tokens).
     */
    public function findExistingMatch(int $agencyId, array $facts, array $source = []): ?TrackedProperty
    {
        return $this->resolveMatch($agencyId, $facts, $source);
    }

    /**
     * 5-strategy resolution. First match wins. Returns null on no match.
     *
     * All queries bypass the global AgencyScope and filter by the explicit
     * $agencyId so the service is safe to invoke from background workers.
     */
    private function resolveMatch(int $agencyId, array $facts, array $source): ?TrackedProperty
    {
        // Strategy 0: Address-history match (Phase C2 silent-killer fix).
        // Consult tracked_property_addresses BEFORE the portal-ref / GPS / erf
        // strategies — an agent who has corrected a wrong address once should
        // never see the same wrong-address ingestion create a duplicate TP again.
        $historyHit = $this->resolveByAddressHistory($agencyId, $facts);
        if ($historyHit) {
            Log::debug('TrackedPropertyMatchOrCreateService::resolveMatch matched via strategy=0_address_history', [
                'agency_id'           => $agencyId,
                'tracked_property_id' => $historyHit->id,
            ]);
            return $historyHit;
        }

        // Strategy 1: Source-ref exact match (the strongest signal — a portal told us this is the same listing).
        // numbersConflict() guard added 2026-08-13 (Frankenstein-record fix):
        // this used to be the ONLY strategy with no conflict veto at all, on
        // the theory that an exact ref match can't be wrong. In practice a
        // ref can get linked to the wrong TrackedProperty exactly once (a
        // mis-timed panel read sending a stale/duplicated erf, or two
        // captures colliding on the same generated ref) — and because
        // writeExternalRef() is unconditional, that single bad link then gets
        // silently re-confirmed and compounded by every future capture of
        // that ref forever, since nothing downstream ever re-checks it. If
        // the incoming facts now structurally conflict with the linked TP
        // (different erf/unit/section, both populated), treat the stale link
        // as suspect rather than gospel: fall through to strategies 2-5 (or
        // create-new) instead of returning it. writeExternalRef() then
        // re-points the ref at whatever correctly resolves this time,
        // self-healing the link for every capture after this one.
        if (!empty($source['type']) && !empty($source['ref'])) {
            $ref = TrackedPropertyExternalRef::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->where('source_type', (string) $source['type'])
                ->where('source_ref', (string) $source['ref'])
                ->whereNull('deleted_at')
                ->first();
            if ($ref) {
                $tp = TrackedProperty::queryWithoutAgencyScope()
                    ->where('agency_id', $agencyId)
                    ->whereNull('deleted_at')
                    ->find($ref->tracked_property_id);
                if ($tp && ! $this->numbersConflict($facts, $tp)) {
                    Log::debug('TrackedPropertyMatchOrCreateService::resolveMatch matched via strategy=1_source_ref', [
                        'agency_id' => $agencyId, 'tracked_property_id' => $tp->id,
                    ]);
                    return $tp;
                }
                if ($tp) {
                    Log::warning('TrackedPropertyMatchOrCreateService::resolveMatch strategy=1_source_ref REJECTED stale link — numbers conflict', [
                        'agency_id' => $agencyId, 'tracked_property_id' => $tp->id, 'source_ref' => (string) $source['ref'],
                    ]);
                }
            }
        }

        // Strategy 2: GPS proximity (cma_gps preferred, then lat/lng).
        $lat = $facts['cma_gps_lat'] ?? $facts['latitude'] ?? null;
        $lng = $facts['cma_gps_lng'] ?? $facts['longitude'] ?? null;
        if ($lat !== null && $lng !== null) {
            $tol = self::GPS_TOLERANCE_DEGREES;
            $byCmaGps = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->whereBetween('cma_gps_lat', [$lat - $tol, $lat + $tol])
                ->whereBetween('cma_gps_lng', [$lng - $tol, $lng + $tol])
                ->first();
            if ($byCmaGps && ! $this->numbersConflict($facts, $byCmaGps)) {
                Log::debug('TrackedPropertyMatchOrCreateService::resolveMatch matched via strategy=2_gps_cma', [
                    'agency_id' => $agencyId, 'tracked_property_id' => $byCmaGps->id,
                ]);
                return $byCmaGps;
            }

            $byGps = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->whereBetween('latitude', [$lat - $tol, $lat + $tol])
                ->whereBetween('longitude', [$lng - $tol, $lng + $tol])
                ->first();
            if ($byGps && ! $this->numbersConflict($facts, $byGps)) {
                Log::debug('TrackedPropertyMatchOrCreateService::resolveMatch matched via strategy=2_gps_latlng', [
                    'agency_id' => $agencyId, 'tracked_property_id' => $byGps->id,
                ]);
                return $byGps;
            }
        }

        // Strategy 3: Erf number + suburb (works even when address is unknown).
        // numbersConflict() guard added 2026-08-13 (sectional-title "SS" fix):
        // erf+suburb uniquely identifies a FREEHOLD stand, but every unit in a
        // sectional scheme sits on the SAME underlying erf — that's how SA
        // sectional title is structured, not a data-quality issue. This
        // strategy had no conflict check at all, so two different units in
        // the same scheme/erf (e.g. ASTOVE section 30 vs a different section)
        // collapsed into one TrackedProperty purely on erf+suburb equality,
        // even though numbersConflict() already knows how to veto on a
        // differing section_number when both sides carry one. Same shape as
        // the strategy=1 fix — a missing section number on either side still
        // never blocks a match, so plain freehold-vs-freehold matching on erf
        // is untouched.
        if (!empty($facts['erf_number']) && !empty($facts['suburb'])) {
            $erfMatch = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where('erf_number', trim((string) $facts['erf_number']))
                ->where('suburb_normalised', TrackedProperty::normaliseSuburb($facts['suburb']))
                ->first();
            if ($erfMatch && ! $this->numbersConflict($facts, $erfMatch)) {
                Log::debug('TrackedPropertyMatchOrCreateService::resolveMatch matched via strategy=3_erf_suburb', [
                    'agency_id' => $agencyId, 'tracked_property_id' => $erfMatch->id,
                ]);
                return $erfMatch;
            }
        }

        // Strategy 4: Normalised structured address.
        if (!empty($facts['street_number']) && !empty($facts['street_name']) && !empty($facts['suburb'])) {
            $addressMatch = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where('street_number', trim((string) $facts['street_number']))
                ->where('street_name', $this->normaliseStreetName($facts['street_name']))
                ->where('suburb_normalised', TrackedProperty::normaliseSuburb($facts['suburb']))
                ->first();
            // street_number already matches exactly here; the gate adds the UNIT
            // dimension so Unit 1 and Unit 2 at "1 The Oval" don't collapse.
            if ($addressMatch && ! $this->numbersConflict($facts, $addressMatch)) {
                Log::debug('TrackedPropertyMatchOrCreateService::resolveMatch matched via strategy=4_normalised_address', [
                    'agency_id' => $agencyId, 'tracked_property_id' => $addressMatch->id,
                ]);
                return $addressMatch;
            }
        }

        // Strategy 5: Token-overlap address match (loose, last resort).
        if (!empty($facts['suburb']) && (!empty($facts['street_name']) || !empty($facts['address']))) {
            $candidates = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where('suburb_normalised', TrackedProperty::normaliseSuburb($facts['suburb']))
                ->limit(50)
                ->get();

            $factTokens = $this->extractAddressTokens(
                ($facts['street_number'] ?? '') . ' ' . ($facts['street_name'] ?? $facts['address'] ?? '')
            );

            if (!empty($factTokens)) {
                foreach ($candidates as $cand) {
                    // Street/unit number is a hard discriminator: the tokeniser
                    // drops <3-char tokens (so "1"/"2" never reach $factTokens),
                    // which is exactly how "1 The Oval" used to match "2 The Oval".
                    // Veto a differing number before any token comparison.
                    if ($this->numbersConflict($facts, $cand)) {
                        continue;
                    }
                    $candTokens = $this->extractAddressTokens(
                        ($cand->street_number ?? '') . ' ' . ($cand->street_name ?? '')
                    );
                    $overlap = array_intersect($factTokens, $candTokens);
                    if (count($overlap) >= 2) {
                        Log::debug('TrackedPropertyMatchOrCreateService::resolveMatch matched via strategy=5_token_overlap', [
                            'agency_id' => $agencyId, 'tracked_property_id' => $cand->id,
                        ]);
                        return $cand;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Street/unit number as a HARD discriminator (SA address reality).
     *
     * "1 The Oval" and "2 The Oval" on the same street are DIFFERENT properties;
     * so are Unit 1 and Unit 2 at one sectional-title address. Returns true only
     * when BOTH sides carry the number and they differ — a MISSING number on
     * either side never blocks a match, so estate-only / messy captures still
     * resolve through the token and GPS strategies (do not overtighten into
     * exact-string matching). This never CREATES a match; it only vetoes the
     * address-SIMILARITY strategies (token overlap, GPS proximity) and adds a
     * unit dimension to the exact-street strategies, so none of them can collapse
     * two distinct numbers. NOT applied to source-ref (strategy 1) or erf
     * (strategy 3) — those are exact external / legal identities.
     */
    private function numbersConflict(array $facts, TrackedProperty $candidate): bool
    {
        // (1) Structured street number — the primary discriminator.
        $factStreet = $this->numberKey($facts['street_number'] ?? null);
        $candStreet = $this->numberKey($candidate->street_number);
        if ($factStreet !== null && $candStreet !== null && $factStreet !== $candStreet) {
            return true;
        }

        // (2) Structured unit number — sectional-title discriminator.
        $factUnit = $this->numberKey($facts['unit_number'] ?? null);
        $candUnit = $this->numberKey($candidate->unit_number);
        if ($factUnit !== null && $candUnit !== null && $factUnit !== $candUnit) {
            return true;
        }

        // (3) Section number — the OTHER sectional-title discriminator
        // (2026-08-13). Two units in the same scheme/building share a street
        // address and — since one GPS pin usually represents the whole
        // building, not each unit — often the same GPS coordinates too.
        // "unit_number" above is populated from CMA's "Flat/Unit no" field,
        // which is routinely blank; "Section number" is the field that
        // actually distinguishes units in a scheme (per the PILLAR: a
        // sectional-title property's identity is scheme + address + SECTION
        // NUMBER, not address alone). Without this, two genuinely different
        // sectional units collapsed into one TrackedProperty via GPS
        // proximity / normalised-address / token-overlap, none of which knew
        // section number existed. Same "both sides populated + differ" veto
        // shape as street/unit number above — a missing section number on
        // either side (freehold, or a capture that hasn't loaded it yet)
        // never blocks a match, so freehold (erf-based) matching is
        // untouched.
        $factSection = $this->numberKey($facts['section_number'] ?? null);
        $candSection = $this->numberKey($candidate->section_number);
        if ($factSection !== null && $candSection !== null && $factSection !== $candSection) {
            return true;
        }

        // (4) Numbers embedded in the NAME strings ("Aqua Breeze 3" vs
        // "Aqua Breeze 5", "Forest Walk 4") — the structured fields are empty for
        // these, and the tokeniser would otherwise match them on the shared word
        // tokens alone. When BOTH sides carry name-embedded numbers and they are
        // wholly disjoint, they are different units/blocks → veto. One side
        // without any name number never vetoes (enrichment stays possible).
        $factNameNums = $this->nameNumbers($facts['street_name'] ?? null, $facts['complex_name'] ?? null);
        $candNameNums = $this->nameNumbers($candidate->street_name, $candidate->complex_name);
        if ($factNameNums !== [] && $candNameNums !== [] && array_intersect($factNameNums, $candNameNums) === []) {
            return true;
        }

        // (5) Erf number — the cadastral identity of the property itself
        // (2026-08-13, Frankenstein-record fix). Structurally stable (an erf
        // only changes on subdivision/consolidation, which genuinely is a
        // different property going forward) — unlike title_deed_number, which
        // legitimately changes on every resale, so deed number is NOT used as
        // a veto here (that would wrongly split records for the same property
        // across a resale). Two captures with different, both-populated erf
        // numbers are legally different properties — no address/GPS/token
        // similarity overrides that. This closes the gap where a scheme's
        // shared street address (or a stale/mis-extracted erf from a
        // mis-timed panel read) let unrelated properties collapse into one
        // TrackedProperty via the looser strategies, and — critically — via
        // strategy=1 (source-ref exact), which previously had NO conflict
        // check at all. Same "both sides populated + differ" veto shape as
        // above; a missing erf on either side never blocks a match.
        $factErf = $this->numberKey($facts['erf_number'] ?? null);
        $candErf = $this->numberKey($candidate->erf_number);
        if ($factErf !== null && $candErf !== null && $factErf !== $candErf) {
            return true;
        }

        return false;
    }

    /**
     * Normalise a street/unit number for equality (lowercased + trimmed). A
     * trailing letter is PRESERVED ("1a" != "1" — a subdivided stand is a
     * different property). Blank ⇒ null ("no number captured", never a veto).
     */
    private function numberKey($value): ?string
    {
        $v = strtolower(trim((string) ($value ?? '')));

        return $v === '' ? null : $v;
    }

    /**
     * Distinct numeric tokens embedded in free-text name fields (e.g. the "3" in
     * a "Aqua Breeze 3" complex name). Used only as a disjoint-set discriminator
     * so two differently-numbered units in the same-named complex don't collapse.
     *
     * @return array<int,string>
     */
    private function nameNumbers(?string ...$parts): array
    {
        $nums = [];
        foreach ($parts as $part) {
            if (! filled($part)) {
                continue;
            }
            if (preg_match_all('/\d+[a-z]?/i', mb_strtolower($part), $m)) {
                foreach ($m[0] as $token) {
                    $nums[$token] = true;
                }
            }
        }

        return array_keys($nums);
    }

    /**
     * Strategy 0 — consult tracked_property_addresses history before the
     * deterministic fact-based strategies. Two sub-matches:
     *   A. Exact street_number + normalised street_name + normalised suburb
     *   B. GPS proximity (~5m) on the address row's lat/lng
     *
     * Returns the highest-confidence hit (verified > high > medium > low,
     * is_primary as tie-breaker). Ignores soft-deleted address rows.
     *
     * Suburb-only ingestions (no street_name AND no GPS) are NOT matched —
     * too risky; we'd collapse independent properties in the same suburb.
     */
    private function resolveByAddressHistory(int $agencyId, array $facts): ?TrackedProperty
    {
        $streetName = TrackedPropertyAddress::normaliseStreet($facts['street_name'] ?? null);
        $streetNumber = isset($facts['street_number']) ? trim((string) $facts['street_number']) : '';
        $suburbNormalised = TrackedPropertyAddress::normaliseSuburb($facts['suburb'] ?? null);
        $lat = $facts['cma_gps_lat'] ?? $facts['latitude'] ?? null;
        $lng = $facts['cma_gps_lng'] ?? $facts['longitude'] ?? null;

        $hasStreet = $streetNumber !== '' && !empty($streetName) && !empty($suburbNormalised);
        $hasGps    = $lat !== null && $lng !== null;
        if (!$hasStreet && !$hasGps) {
            return null;
        }

        // Match A — exact structured address.
        if ($hasStreet) {
            $hit = DB::table('tracked_property_addresses')
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where('street_number', $streetNumber)
                ->where('street_name', $streetName)
                ->where('suburb_normalised', $suburbNormalised)
                ->orderByRaw("FIELD(confidence, 'verified', 'high', 'medium', 'low')")
                ->orderByDesc('is_primary')
                ->first(['tracked_property_id']);
            if ($hit) {
                $tp = TrackedProperty::queryWithoutAgencyScope()
                    ->where('agency_id', $agencyId)
                    ->whereNull('deleted_at')
                    ->find((int) $hit->tracked_property_id);
                if ($tp && ! $this->numbersConflict($facts, $tp)) return $tp;
            }
        }

        // Match B — GPS proximity on the address row itself.
        if ($hasGps) {
            $tol = self::GPS_TOLERANCE_DEGREES;
            $hit = DB::table('tracked_property_addresses')
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->whereBetween('latitude', [$lat - $tol, $lat + $tol])
                ->whereBetween('longitude', [$lng - $tol, $lng + $tol])
                ->orderByRaw("FIELD(confidence, 'verified', 'high', 'medium', 'low')")
                ->orderByDesc('is_primary')
                ->first(['tracked_property_id']);
            if ($hit) {
                $tp = TrackedProperty::queryWithoutAgencyScope()
                    ->where('agency_id', $agencyId)
                    ->whereNull('deleted_at')
                    ->find((int) $hit->tracked_property_id);
                if ($tp && ! $this->numbersConflict($facts, $tp)) return $tp;
            }
        }

        return null;
    }

    /**
     * Phase C2 — append (or bump) the incoming address in the TP's address
     * history. Deduplicates on (street_number + street_name + suburb_normalised)
     * when a street is present, else on GPS proximity. Bumps last_seen_at on
     * the existing row when found; inserts a non-primary row otherwise.
     *
     * NEVER throws — wrapped in try/catch + Log::warning so the underlying
     * matchOrCreate operation cannot be broken by a history-append hiccup.
     */
    private function appendIngestedAddressToHistory(TrackedProperty $tp, array $facts, array $source): void
    {
        try {
            $streetName = TrackedPropertyAddress::normaliseStreet($facts['street_name'] ?? null);
            $streetNumber = isset($facts['street_number']) ? trim((string) $facts['street_number']) : '';
            $suburbNormalised = TrackedPropertyAddress::normaliseSuburb($facts['suburb'] ?? null);
            $lat = $facts['cma_gps_lat'] ?? $facts['latitude'] ?? null;
            $lng = $facts['cma_gps_lng'] ?? $facts['longitude'] ?? null;

            $hasStreet = $streetNumber !== '' && !empty($streetName) && !empty($suburbNormalised);
            $hasGps    = $lat !== null && $lng !== null;
            if (!$hasStreet && !$hasGps) {
                // Nothing meaningful to record — suburb-only / no-address ingest.
                return;
            }

            // Dedup probe: prefer structured-address match, fall back to GPS.
            $existingId = null;
            if ($hasStreet) {
                $existingId = DB::table('tracked_property_addresses')
                    ->where('agency_id', $tp->agency_id)
                    ->where('tracked_property_id', $tp->id)
                    ->whereNull('deleted_at')
                    ->where('street_number', $streetNumber)
                    ->where('street_name', $streetName)
                    ->where('suburb_normalised', $suburbNormalised)
                    ->value('id');
            }
            if ($existingId === null && $hasGps) {
                $tol = self::GPS_TOLERANCE_DEGREES;
                $existingId = DB::table('tracked_property_addresses')
                    ->where('agency_id', $tp->agency_id)
                    ->where('tracked_property_id', $tp->id)
                    ->whereNull('deleted_at')
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->whereBetween('latitude', [$lat - $tol, $lat + $tol])
                    ->whereBetween('longitude', [$lng - $tol, $lng + $tol])
                    ->value('id');
            }

            if ($existingId !== null) {
                // Bump last_seen_at via raw update to skip the observer +
                // booted hooks — the row already has its canonical fields.
                DB::table('tracked_property_addresses')
                    ->where('id', $existingId)
                    ->update(['last_seen_at' => now(), 'updated_at' => now()]);
                return;
            }

            // No matching row — insert via Eloquent so the booted hooks run
            // (suburb_normalised auto-set, street_name normalised, first/last
            // _seen_at defaulted). is_primary stays false — only manual edits
            // via Phase C3 can promote.
            TrackedPropertyAddress::create([
                'agency_id'           => $tp->agency_id,
                'tracked_property_id' => $tp->id,
                'street_number'       => $streetNumber !== '' ? $streetNumber : null,
                'street_name'         => $streetName,
                'unit_number'         => $facts['unit_number'] ?? null,
                'complex_name'        => $facts['complex_name'] ?? null,
                'suburb'              => $facts['suburb'] ?? null,
                'suburb_normalised'   => $suburbNormalised,
                'town'                => $facts['town'] ?? null,
                'province'            => $facts['province'] ?? null,
                'postal_code'         => $facts['postal_code'] ?? null,
                'latitude'            => $lat,
                'longitude'           => $lng,
                'source_type'         => (string) ($source['type'] ?? 'unknown'),
                'source_ref'          => isset($source['ref']) ? (string) $source['ref'] : null,
                'confidence'          => TrackedPropertyAddress::confidenceForSource(
                    (string) ($source['type'] ?? 'unknown'),
                    $streetName,
                ),
                'is_primary'          => false,
                'first_seen_at'       => now(),
                'last_seen_at'        => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('TrackedPropertyMatchOrCreateService::appendIngestedAddressToHistory failed', [
                'agency_id'           => $tp->agency_id ?? null,
                'tracked_property_id' => $tp->id ?? null,
                'source_type'         => $source['type'] ?? null,
                'source_ref'          => $source['ref'] ?? null,
                'error'               => $e->getMessage(),
            ]);
        }
    }

    private function create(int $agencyId, array $facts, array $source, ?int $actorUserId): TrackedProperty
    {
        $tp = TrackedProperty::create(array_merge(
            $this->canonicalFactsForWrite($facts),
            [
                'agency_id'              => $agencyId,
                'source_chain'           => [$this->buildSourceChainEntry($source, $facts)],
                'first_seen_at'          => now(),
                'last_enriched_at'       => now(),
                'last_enrichment_source' => $source['type'] ?? 'unknown',
                'status'                 => TrackedProperty::STATUS_ACTIVE,
            ]
        ));

        $this->writeExternalRef($agencyId, (int) $tp->id, $source);

        event(new TrackedPropertyCreated(
            trackedPropertyId: (int) $tp->id,
            agencyId: $agencyId,
            sourceType: (string) ($source['type'] ?? 'unknown'),
            actorUserId: $actorUserId,
        ));

        return $tp;
    }

    private function enrich(
        TrackedProperty $tp,
        array $newFacts,
        array $source,
        ?int $actorUserId,
    ): TrackedProperty {
        $sanitised  = $this->canonicalFactsForWrite($newFacts);
        $sourceType = (string) ($source['type'] ?? 'unknown');
        $alwaysWins = in_array($sourceType, self::SOURCE_ALWAYS_WINS, true);

        // Walk the sanitised facts only (all scalars). Build a diff against the
        // current TP values using fillable-column comparisons. Skips array-cast
        // columns by construction — canonicalFactsForWrite is scalar-only.
        //
        // fieldChanges records EVERY write this enrichment makes — field,
        // previous value, new value, and whether it was a gain (existing was
        // empty) or a correction (existing had a value and got replaced).
        // Deliberately built from the SAME loop that decides the write, not
        // reconstructed afterwards, so the audit trail can never drift from
        // what was actually persisted. .ai/specs/deeds-capture.md §6 Part A/B.
        $diff = [];
        $fieldChanges = [];
        foreach ($sanitised as $key => $newVal) {
            $rawExisting = $tp->{$key} ?? null;
            // Normalise the STORED value the same way an incoming capture is
            // normalised, before deciding filled-vs-replaced. A prior bug (fixed
            // 2026-08-19 alongside this precedence rule) let literal placeholder
            // strings ('-' etc.) get written as if they were real data — a row
            // carrying that garbage today must not read as "already has a real
            // value" and must not show a correction as "replaced 'ABSA Bank' for
            // '-'" on the Deeds Capture screen. Treating it as absent both lets
            // ANY source clean it up (not just deeds_capture) and reports it
            // honestly as a gain.
            $existing = is_string($rawExisting) ? $this->normaliseCapturedScalar($rawExisting) : $rawExisting;
            // Empty existing → adopt the new value (covers most enrichments).
            // This guard runs BEFORE any source-precedence check and is
            // unconditional: absent is not a value, so there is nothing to
            // "replace" here regardless of source.
            if ($existing === null || $existing === '') {
                $diff[$key] = $newVal;
                $fieldChanges[] = ['field' => $key, 'previous' => null, 'new' => $newVal, 'change_type' => 'filled'];
                continue;
            }
            // A populated existing value: only overwrite it when this source is
            // allowed to win — either a source-agnostic NEWER_WINS field, or
            // (Johan, 2026-08-19) a SOURCE_ALWAYS_WINS source like deeds_capture,
            // which wins on every field it actually captured, not just this list.
            $sourceWinsHere = $alwaysWins || in_array($key, self::NEWER_WINS_FIELDS, true);
            if ($sourceWinsHere && !$this->sameCapturedValue($existing, $newVal)) {
                $diff[$key] = $newVal;
                $fieldChanges[] = ['field' => $key, 'previous' => $existing, 'new' => $newVal, 'change_type' => 'replaced'];
                continue;
            }
            // Otherwise the existing value stands (first source wins for stable identifiers).
        }

        // Bookkeeping: always set on enrich.
        $diff['last_enriched_at']       = now();
        $diff['last_enrichment_source'] = $sourceType;

        // Append-only source_chain. field_changes rides on THIS entry only
        // (omitted when empty, so old entries and no-op re-captures don't
        // grow the column for nothing) — it's what the capture visible on
        // /corex/deeds-capture actually did, not a running total.
        $entry = $this->buildSourceChainEntry($source, $newFacts);
        if ($fieldChanges !== []) {
            $entry['field_changes'] = $fieldChanges;
        }
        $chain   = $tp->source_chain ?? [];
        $chain[] = $entry;
        $diff['source_chain'] = $chain;

        $tp->update($diff);
        $this->writeExternalRef((int) $tp->agency_id, (int) $tp->id, $source);

        // Field-additions excludes the bookkeeping columns so consumers see only
        // the meaningful business-data delta.
        $bookkeeping = ['last_enriched_at', 'last_enrichment_source', 'source_chain'];
        $fieldsAdded = array_values(array_diff(array_keys($diff), $bookkeeping));

        event(new TrackedPropertyEnriched(
            trackedPropertyId: (int) $tp->id,
            agencyId: (int) $tp->agency_id,
            sourceType: $sourceType,
            fieldsAdded: $fieldsAdded,
            actorUserId: $actorUserId,
        ));

        return $tp->fresh();
    }

    private function writeExternalRef(int $agencyId, int $trackedPropertyId, array $source): void
    {
        if (empty($source['type']) || empty($source['ref'])) return;

        $existing = TrackedPropertyExternalRef::queryWithoutAgencyScope()
            ->where('agency_id', $agencyId)
            ->where('source_type', (string) $source['type'])
            ->where('source_ref', (string) $source['ref'])
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            $existing->update([
                'tracked_property_id' => $trackedPropertyId,
                'source_payload'      => $source['payload'] ?? $existing->source_payload,
                'last_seen_at'        => now(),
            ]);
            return;
        }

        TrackedPropertyExternalRef::create([
            'agency_id'           => $agencyId,
            'tracked_property_id' => $trackedPropertyId,
            'source_type'         => (string) $source['type'],
            'source_ref'          => (string) $source['ref'],
            'source_payload'      => $source['payload'] ?? null,
            'first_seen_at'       => now(),
            'last_seen_at'        => now(),
        ]);
    }

    private function buildSourceChainEntry(array $source, array $facts): array
    {
        return [
            'type'               => (string) ($source['type'] ?? 'unknown'),
            'ref'                => isset($source['ref']) ? (string) $source['ref'] : null,
            'date'               => Carbon::now()->toIso8601String(),
            'fields_contributed' => array_keys(array_filter($facts, fn ($v) => $v !== null && $v !== '')),
        ];
    }

    /**
     * Whitelist + sanitise the fact array to TrackedProperty's writable columns.
     */
    private function canonicalFactsForWrite(array $facts): array
    {
        static $writable = [
            'street_number', 'street_name', 'unit_number', 'complex_name',
            'suburb', 'town', 'province', 'postal_code',
            'latitude', 'longitude', 'cma_gps_lat', 'cma_gps_lng',
            'erf_number', 'title_deed_number', 'cadastral_extent',
            'municipal_valuation', 'municipal_valuation_year',
            'last_known_asking_price', 'last_known_sold_price', 'last_known_sold_date',
            'property_type', 'bedrooms', 'bathrooms', 'garages',
            'floor_size_m2', 'erf_size_m2',
            // Deeds-specific columns (.ai/specs/deeds-capture.md) — added here
            // so they flow through the SAME enrich() diff + precedence + audit
            // mechanism as every other fact, instead of a second, separate
            // unconditional-overwrite write in DeedsCaptureController. Harmless
            // to every other source: none of them ever populate these keys.
            'deeds_office', 'scheme_name', 'scheme_number', 'section_number',
            'bond_holder', 'bond_amount', 'sale_type', 'deeds_registered_date',
        ];

        $out = [];
        foreach ($writable as $col) {
            if (!array_key_exists($col, $facts)) {
                continue;
            }
            $val = $this->normaliseCapturedScalar($facts[$col]);
            if ($val !== null) {
                $out[$col] = $val;
            }
        }

        // Normalise street name on write so identical addresses written under different
        // spellings land in the same row when matched later.
        if (!empty($out['street_name'])) {
            $out['street_name'] = $this->normaliseStreetName($out['street_name']);
        }

        return $out;
    }

    /**
     * A captured value is "absent" (return null, drop from the write) when it
     * is null, empty/whitespace-only, or one of ABSENT_PLACEHOLDERS ('-',
     * 'N/A', em/en dash, …) — the placeholder a scraped source sends in place
     * of a real value. The extension is fixing this at the source too, but
     * the server must not trust that: a dash arriving here by any other path
     * (a future source, a payload built another way) must never be accepted
     * as data. .ai/specs/deeds-capture.md §6 Part A.
     */
    private function normaliseCapturedScalar($val)
    {
        if ($val === null) {
            return null;
        }
        if (!is_string($val)) {
            return $val; // numeric/bool — nothing to placeholder-check
        }
        $trimmed = trim($val);
        if ($trimmed === '') {
            return null;
        }
        if (in_array(mb_strtolower($trimmed), self::ABSENT_PLACEHOLDERS, true)) {
            return null;
        }
        return $trimmed;
    }

    /**
     * True when an existing value and a freshly captured value are the same
     * value, not just the same string. A decimal(…,7) GPS column round-trips
     * -30.830085 as "-30.8300850" — a plain string compare (the previous
     * behaviour) sees that as a change on EVERY re-capture of the identical
     * coordinate, which would make the Deeds Capture screen report a
     * "correction" on a capture that corrected nothing. Numeric-looking
     * values on both sides compare numerically; anything else falls back to
     * a string compare (unchanged behaviour for text fields).
     */
    private function sameCapturedValue($existing, $newVal): bool
    {
        if (is_numeric($existing) && is_numeric($newVal)) {
            return abs((float) $existing - (float) $newVal) < 0.0000001;
        }
        return (string) $existing === (string) $newVal;
    }

    /**
     * Canonicalise street name so "Mitchell St" and "MITCHELL STREET" land in the same bucket.
     */
    private function normaliseStreetName(?string $name): ?string
    {
        if ($name === null || $name === '') return null;
        $name = trim($name);
        $name = preg_replace('/\bst\.?\b/i', 'Street', $name);
        $name = preg_replace('/\brd\.?\b/i', 'Road', $name);
        $name = preg_replace('/\bave\.?\b/i', 'Avenue', $name);
        $name = preg_replace('/\bdr\.?\b/i', 'Drive', $name);
        $name = preg_replace('/\blane\.?\b/i', 'Lane', $name);
        $name = preg_replace('/\bcl(?:o)?se?\.?\b/i', 'Close', $name);
        return ucwords(mb_strtolower((string) $name));
    }

    private function extractAddressTokens(string $s): array
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\w\s]/u', ' ', $s);
        $tokens = preg_split('/\s+/', trim((string) $s)) ?: [];
        return array_values(array_filter($tokens, fn ($t) => strlen($t) >= 3));
    }

    /**
     * Property-pillar refresh fields (2026-08-14) — the ONLY columns
     * promoteToStock() will ever write onto an EXISTING (matched) Property.
     * Everything else — agent_id, branch_id, status, status_label,
     * mandate_type, price, title, listing_type, published_at, and any
     * deal/outreach-linked data — is the owning agent's relationship state
     * and is NEVER touched on refresh, regardless of whether it came from
     * the TrackedProperty's own facts or a caller's $propertyFields. Only
     * physical/factual columns: the property doesn't care who owns the
     * relationship with it.
     */
    private const REFRESHABLE_PROPERTY_FIELDS = [
        'street_number', 'street_name', 'suburb', 'town', 'province',
        'latitude', 'longitude', 'cma_gps_lat', 'cma_gps_lng',
        'erf_number', 'title_deed_number',
        'municipal_valuation', 'municipal_valuation_year',
        'property_type', 'beds', 'baths', 'garages',
        'complex_name', 'unit_number', 'erf_size_m2',
    ];

    /**
     * Property-pillar match (2026-08-14) — the SAME physical-identity
     * philosophy as resolveMatch() above, applied to `properties` instead of
     * `tracked_properties`, so promoteToStock() links to ONE canonical
     * Property per physical unit instead of creating a duplicate every time
     * a distinct TrackedProperty (a fresh re-capture, a differently-matched
     * suspense record, an earlier-session TP that pre-dates a matcher fix,
     * etc.) gets promoted for the same real-world property.
     *
     * Sectional and freehold use DIFFERENT primary keys — same reasoning as
     * numbersConflict()'s erf veto: every unit in a scheme shares one erf,
     * so erf+suburb is only a safe key for FREEHOLD. Sectional keys on
     * complex_name + unit_number: `properties.unit_number` is ALREADY the
     * established convention for "CMA section number" on a deeds-sourced
     * property (see DeedsCaptureController::promote()'s
     * `'unit_number' => $trackedProperty->section_number`) — reused here
     * rather than adding a new column, so there is ONE identity column for
     * this, not two competing ones.
     *
     * Normalised address is the fallback for either title type, gated by
     * propertyIdentityConflicts() so two different sectional units that both
     * lack scheme/section data don't silently collapse onto the same
     * Property via address alone (the exact bug class fixed in
     * numbersConflict() for tracked_properties).
     */
    private function resolvePropertyMatch(TrackedProperty $tp): ?Property
    {
        $isSectional = filled($tp->scheme_number)
            || (filled($tp->section_number) && preg_match('/\d/', (string) $tp->section_number));

        if ($isSectional) {
            $complexName = trim((string) ($tp->complex_name ?: $tp->scheme_name));
            $section = trim((string) $tp->section_number);
            if ($complexName !== '' && $section !== '') {
                $match = Property::queryWithoutAgencyScope()
                    ->where('agency_id', $tp->agency_id)
                    ->whereNull('deleted_at')
                    ->whereRaw('LOWER(complex_name) = ?', [mb_strtolower($complexName)])
                    ->where('unit_number', $section)
                    ->first();
                if ($match) {
                    return $match;
                }
            }
        } elseif (filled($tp->erf_number) && filled($tp->suburb)) {
            $match = Property::queryWithoutAgencyScope()
                ->where('agency_id', $tp->agency_id)
                ->whereNull('deleted_at')
                ->where('erf_number', trim((string) $tp->erf_number))
                ->where('suburb_normalised', TrackedPropertyAddress::normaliseSuburb($tp->suburb))
                ->first();
            if ($match) {
                return $match;
            }
        }

        // Fallback: normalised address + suburb, for either title type.
        if (filled($tp->street_number) && filled($tp->street_name) && filled($tp->suburb)) {
            $candidate = Property::queryWithoutAgencyScope()
                ->where('agency_id', $tp->agency_id)
                ->whereNull('deleted_at')
                ->where('street_number', trim((string) $tp->street_number))
                ->where('street_name_normalised', TrackedPropertyAddress::normaliseStreet($tp->street_name))
                ->where('suburb_normalised', TrackedPropertyAddress::normaliseSuburb($tp->suburb))
                ->first();
            if ($candidate && ! $this->propertyIdentityConflicts($tp, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Lightweight sibling of numbersConflict() for resolvePropertyMatch()'s
     * address-fallback branch. Same veto shape: only fires when BOTH sides
     * carry a value AND they differ — a missing unit number on either side
     * never blocks the match.
     */
    private function propertyIdentityConflicts(TrackedProperty $tp, Property $candidate): bool
    {
        $tpUnit = $this->numberKey($tp->section_number ?: $tp->unit_number);
        $candUnit = $this->numberKey($candidate->unit_number);

        return $tpUnit !== null && $candUnit !== null && $tpUnit !== $candUnit;
    }

    /**
     * Promote a Tracked Property to Agency Stock.
     *
     * Resolves to ONE canonical Property via resolvePropertyMatch() — refreshes
     * the physical facts (REFRESHABLE_PROPERTY_FIELDS only) if a matching
     * Property already exists, creates one if not. Links the TP to it via
     * promoted_to_property_id and preserves the entire source_chain for audit
     * either way.
     *
     * Defence-of-NOT-NULL: properties.agent_id and properties.branch_id are NOT NULL
     * on the schema with no defaults. The promoting user supplies both — agent_id
     * defaults to the promoting user, branch_id to their branch. Caller may override
     * either via $propertyFields (CREATE path only — see REFRESHABLE_PROPERTY_FIELDS
     * doc above for why $propertyFields is filtered down on the refresh path).
     */
    public function promoteToStock(
        int $trackedPropertyId,
        int $promotingUserId,
        array $propertyFields = [],
    ): Property {
        return DB::transaction(function () use ($trackedPropertyId, $promotingUserId, $propertyFields) {
            $tp = TrackedProperty::queryWithoutAgencyScope()->findOrFail($trackedPropertyId);

            if ($tp->isPromoted()) {
                return $tp->promotedProperty;
            }

            $user = User::find($promotingUserId);
            $defaultBranchId = $user?->branch_id ?? null;
            if ($defaultBranchId === null && !array_key_exists('branch_id', $propertyFields)) {
                throw new \DomainException(
                    "Cannot promote TrackedProperty #{$trackedPropertyId}: user #{$promotingUserId} has no branch_id and none was supplied in propertyFields."
                );
            }

            $existingProperty = $this->resolvePropertyMatch($tp);

            if ($existingProperty) {
                // REFRESH — never blank an existing value with null/empty,
                // and never write outside REFRESHABLE_PROPERTY_FIELDS. TP
                // facts take precedence over $propertyFields (TP is the
                // freshest capture); either source is filtered to the same
                // whitelist before being applied.
                $tpFacts = array_filter([
                    'street_number'            => $tp->street_number,
                    'street_name'              => $tp->street_name,
                    'suburb'                   => $tp->suburb,
                    'town'                     => $tp->town,
                    'province'                 => $tp->province,
                    'latitude'                 => $tp->latitude,
                    'longitude'                => $tp->longitude,
                    'cma_gps_lat'              => $tp->cma_gps_lat,
                    'cma_gps_lng'              => $tp->cma_gps_lng,
                    'erf_number'               => $tp->erf_number,
                    'title_deed_number'        => $tp->title_deed_number,
                    'municipal_valuation'      => $tp->municipal_valuation,
                    'municipal_valuation_year' => $tp->municipal_valuation_year,
                    'property_type'            => $tp->property_type,
                    'beds'                     => $tp->bedrooms,
                    'baths'                    => $tp->bathrooms,
                    'garages'                  => $tp->garages,
                    'complex_name'             => $tp->complex_name ?: $tp->scheme_name,
                    // section_number takes priority (the deeds/sectional
                    // convention this whole match key relies on) but falls
                    // back to unit_number so a plain unit/flat number from a
                    // non-deeds capture isn't silently dropped — that value
                    // is exactly what propertyIdentityConflicts() needs
                    // populated on the CANDIDATE side to veto a false match.
                    'unit_number'              => $tp->section_number ?: $tp->unit_number,
                    'erf_size_m2'              => $tp->cadastral_extent,
                ], static fn ($v) => $v !== null && $v !== '');

                $callerFacts = array_filter(
                    array_intersect_key($propertyFields, array_flip(self::REFRESHABLE_PROPERTY_FIELDS)),
                    static fn ($v) => $v !== null && $v !== ''
                );

                $refreshable = array_intersect_key(
                    array_merge($tpFacts, $callerFacts),
                    array_flip(self::REFRESHABLE_PROPERTY_FIELDS)
                );

                if ($refreshable !== []) {
                    $existingProperty->update($refreshable);
                }

                $property = $existingProperty;
            } else {
                $property = Property::create(array_merge(
                    [
                        'agency_id'                => $tp->agency_id,
                        'agent_id'                 => $promotingUserId,
                        'branch_id'                => $defaultBranchId,
                        'address'                  => $tp->displayAddress(),
                        'street_number'            => $tp->street_number,
                        'street_name'              => $tp->street_name,
                        'suburb'                   => $tp->suburb,
                        'town'                     => $tp->town,
                        'province'                 => $tp->province,
                        'latitude'                 => $tp->latitude,
                        'longitude'                => $tp->longitude,
                        'cma_gps_lat'              => $tp->cma_gps_lat,
                        'cma_gps_lng'              => $tp->cma_gps_lng,
                        'erf_number'               => $tp->erf_number,
                        'title_deed_number'        => $tp->title_deed_number,
                        'municipal_valuation'      => $tp->municipal_valuation,
                        'municipal_valuation_year' => $tp->municipal_valuation_year,
                        'property_type'            => $tp->property_type ?? 'house',
                        'beds'                     => $tp->bedrooms ?? 0,
                        'baths'                    => $tp->bathrooms ?? 0,
                        'garages'                  => $tp->garages ?? 0,
                        'price'                    => $tp->last_known_asking_price ?? 0,
                        'title'                    => $tp->displayAddress(),
                        'status'                   => 'draft',
                        'listing_type'             => 'sale',
                        // complex_name/unit_number (2026-08-14) — carry the
                        // scheme/section identity onto the new Property so a
                        // LATER promote() of a different unit in the same
                        // scheme can resolvePropertyMatch() against it,
                        // instead of every unit only ever creating fresh.
                        // section_number takes priority but falls back to
                        // unit_number — same reasoning as the refresh path
                        // above (propertyIdentityConflicts() needs whichever
                        // one the capture actually carries).
                        'complex_name'             => $tp->complex_name ?: $tp->scheme_name,
                        'unit_number'              => $tp->section_number ?: $tp->unit_number,
                        // external_id auto-generated by Property's creating hook (char(36) UUID).
                        // The TP↔Property linkage is preserved by tracked_properties.promoted_to_property_id.
                    ],
                    $propertyFields
                ));
            }

            $tp->update([
                'promoted_to_property_id' => $property->id,
                'promoted_at'             => now(),
                'promoted_by_user_id'     => $promotingUserId,
                'status'                  => TrackedProperty::STATUS_PROMOTED,
            ]);

            event(new TrackedPropertyPromotedToStock(
                trackedPropertyId: (int) $tp->id,
                propertyId: (int) $property->id,
                agencyId: (int) $tp->agency_id,
                actorUserId: $promotingUserId,
            ));

            // Mandate pillar: promotion from tracked → stock is the architectural
            // conversion moment. Spec .ai/specs/corex-domain-events-spec.md.
            event(new \App\Events\Mandate\MandateConverted(
                mandate: $tp,
                deal: null,
                agencyIdHint: (int) $tp->agency_id,
                actorUserId: $promotingUserId,
            ));

            return $property;
        });
    }
}
