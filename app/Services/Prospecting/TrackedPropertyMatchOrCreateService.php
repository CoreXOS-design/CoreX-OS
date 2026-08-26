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
 *   3b. Scheme identity + section/unit number match (sectional title's own
 *       legal identity — erf does not identify a unit, every unit in a
 *       scheme shares one)
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
     * CX-102 part 2 (2026-08-19, Johan: "the system must show its working").
     * Set by resolveMatch() right before it returns a candidate, read by
     * matchOrCreate() immediately after — the label of whichever strategy
     * actually produced the match, and (Strategy 5 ties only) the other
     * candidates it considered. Instance state, not a return-type change,
     * because resolveMatch()/findExistingMatch() are called synchronously
     * and read back immediately within the same request — nothing else
     * runs between a strategy setting this and matchOrCreate() reading it.
     */
    private ?string $lastMatchStrategy = null;

    /** @var array<int, array{type: string, id: int, label: string}>|null */
    private ?array $lastMatchCandidates = null;

    /**
     * Recorded, agent-facing match decisions (CX-102 part 2) exist ONLY for
     * source types with a screen to show them and a control to reject them.
     * Deeds Capture is that screen today. Gating here — not by removing the
     * veto/record calls from every strategy — means turning this on for
     * another source (P24, PP, a future CX-101 surface) is a one-line change
     * to this array, not a re-wire of the matcher.
     */
    private const DECISION_TRACKED_SOURCE_TYPES = ['deeds_capture'];

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
            $strategy = $this->lastMatchStrategy;
            $candidates = $this->lastMatchCandidates;

            $tp = $matched
                ? $this->enrich($matched, $facts, $source, $actorUserId)
                : $this->create($agencyId, $facts, $source, $actorUserId);

            // CX-102 part 2 (2026-08-19, Johan) — record WHY, at the moment of
            // the match, for whichever screen shows this decision to an agent.
            // Gated to decision-tracked source types (see
            // isDecisionTrackedSource) — zero cost on every other ingestion
            // path, which has no screen to show this on.
            if ($matched && $strategy !== null && $this->isDecisionTrackedSource($source)) {
                app(PropertyMatchDecisionService::class)->record(
                    agencyId: $agencyId,
                    subjectType: 'deeds_capture',
                    subjectKey: $this->subjectKeyFor($source),
                    matchedType: 'tracked_property',
                    matchedId: $tp->id,
                    strategy: $strategy,
                    reason: $this->reasonFor($strategy, $facts, $tp),
                    candidates: $candidates,
                    incomingFacts: $facts,
                );
            }

            // Phase C2 — append (or bump) the ingested address in the TP's
            // address history. Failure-isolated: the underlying match-or-create
            // operation MUST succeed even if the history append blows up.
            $this->appendIngestedAddressToHistory($tp, $facts, $source);

            return $tp;
        });
    }

    /**
     * "Not the same property" (CX-102 part 2, Johan 2026-08-19). An agent has
     * looked at a deeds-capture match and said it's wrong. This:
     *
     *   1. Records the rejection (permanent — the veto every future capture
     *      of this same reference is checked against).
     *   2. Flags the source_chain entry THIS capture wrote onto the rejected
     *      property as disputed — so anyone opening that record sees its
     *      erf/deed/price data may not actually be about it. Does NOT alter
     *      the field values themselves (no data repair — Johan's rule).
     *   3. Either enriches the agent's chosen replacement (when they picked
     *      one from the alternatives shown) or creates a fresh
     *      TrackedProperty from exactly what this capture originally said
     *      (the incoming_facts snapshot taken at match time) — then
     *      re-points this capture's external reference at whichever it is,
     *      so the SAME capture re-imported tomorrow resolves correctly via
     *      Strategy 1 without ever revisiting the ambiguous strategies.
     *
     * The agent's own work continues immediately either way — nothing here
     * blocks or queues.
     */
    public function rejectMatch(
        int $agencyId,
        string $sourceType,
        string $sourceRef,
        int $rejectedTrackedPropertyId,
        int $byUserId,
        ?string $reason = null,
        ?int $replacementTrackedPropertyId = null,
    ): TrackedProperty {
        return DB::transaction(function () use (
            $agencyId, $sourceType, $sourceRef, $rejectedTrackedPropertyId, $byUserId, $reason, $replacementTrackedPropertyId
        ) {
            $decisionService = app(PropertyMatchDecisionService::class);
            $subjectKey = $sourceType . ':' . $sourceRef;

            $rejectedTp = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->findOrFail($rejectedTrackedPropertyId);

            $decision = $decisionService->current($agencyId, 'deeds_capture', $subjectKey);

            // 2026-08-19 fix — a capture matched before this feature existed has no
            // recorded decision at all (Johan's own flagship example, tracked
            // property #468, is exactly this: matched in an earlier session,
            // nothing to read back). Without this, "Not the same property" would
            // be a dead button on precisely the row that motivated building it.
            // Backfill a minimal decision from the TP's own source_chain (proof
            // this capture genuinely landed here, not a forged pairing) rather
            // than refuse to let the agent reject something real just because
            // the reason was never captured.
            if ($decision === null) {
                $matchingEntry = collect($rejectedTp->source_chain ?? [])
                    ->last(fn ($entry) => ($entry['type'] ?? null) === $sourceType && ($entry['ref'] ?? null) === $sourceRef);
                if ($matchingEntry === null) {
                    throw new \DomainException(
                        "This capture ({$subjectKey}) has no record of ever matching tracked property #{$rejectedTrackedPropertyId} — nothing to reject."
                    );
                }
                // Reconstruct what this capture originally contributed from the TP's
                // OWN current field values, keyed by exactly the field names that
                // entry's own fields_contributed lists — the closest honest
                // approximation available when no incoming_facts snapshot exists
                // (this capture ran before that snapshot was ever taken).
                $reconstructedFacts = [];
                foreach (($matchingEntry['fields_contributed'] ?? []) as $field) {
                    if (isset($rejectedTp->{$field})) {
                        $reconstructedFacts[$field] = $rejectedTp->{$field};
                    }
                }
                $decision = $decisionService->record(
                    $agencyId, 'deeds_capture', $subjectKey, 'tracked_property', $rejectedTrackedPropertyId,
                    'unrecorded', 'Matched automatically, before this screen recorded why.',
                    incomingFacts: $reconstructedFacts ?: null,
                );
            } elseif ((int) $decision->matched_id !== $rejectedTrackedPropertyId || $decision->isRejected()) {
                throw new \DomainException(
                    "No live match decision found for deeds_capture {$subjectKey} against tracked property #{$rejectedTrackedPropertyId} — nothing to reject (already rejected, or the match has since changed)."
                );
            }

            $source = ['type' => $sourceType, 'ref' => $sourceRef];

            $this->markSourceChainEntryDisputed($rejectedTp, $source, $byUserId);

            // 2026-08-20 (Johan, LIVE, urgent — staff arriving in 2 hours).
            // Verbatim: "it should just unlink the current record from
            // whatever corex thought it matched. done. No 2nd property
            // needed. and let the user carry on to work with that
            // property." REPLACES the previous behaviour (create a fresh
            // TrackedProperty from incoming_facts when no replacement is
            // picked) — that created a genuinely duplicate, ownerless
            // record (live: TrackedProperty #748 rejected -> #37905,
            // owner_contact_id NULL) because the facts/owner snapshot this
            // capture contributed had no clean home to land in without
            // spinning up a whole second record for it.
            //
            // A rejection now does exactly two things and nothing else:
            //   1. Mark the disputed source_chain entry (above) — the
            //      record shows its working, unchanged from before.
            //   2. Record the rejection on property_match_decisions (below)
            //      — the part Johan explicitly wants kept: it's what stops
            //      the SAME wrong pairing being re-suggested on a rescrape
            //      (TrackedPropertyMatchOrCreateService::acceptCandidate()
            //      -> isVetoedCandidate() checks this on every strategy,
            //      including Strategy 1's source-ref lookup, so a stale
            //      external ref can never bypass the veto).
            // $rejectedTp itself is returned UNCHANGED — no enrich(), no
            // create(), no owner data moved. The agent keeps working the
            // same real property they were already on; the capture that
            // didn't belong there is simply no longer treated as if it did.
            //
            // The "pick a specific replacement" path is unaffected: an
            // agent explicitly naming a DIFFERENT existing property as the
            // real match is a deliberate, intentional action — not the bug
            // (that only ever fired on the no-replacement branch) — so
            // enrich() still applies the facts there.
            if ($replacementTrackedPropertyId !== null) {
                $replacement = TrackedProperty::queryWithoutAgencyScope()
                    ->where('agency_id', $agencyId)
                    ->findOrFail($replacementTrackedPropertyId);
                $resultTp = $this->enrich($replacement, $decision->incoming_facts ?? [], $source, $byUserId);
                // The agent named a SPECIFIC different property — the owner
                // this capture had already resolved onto $rejectedTp belongs
                // with it, not left behind. Never guesses which of
                // $rejectedTp's owners are this capture's: only rows
                // reconcileOwners() could have written at the moment this
                // capture ran qualify (correlated against decided_at).
                $this->carryContributedOwnersToRejectionResult($rejectedTp, $resultTp, $decision->decided_at);
            } else {
                $resultTp = $rejectedTp;
            }

            $decisionService->reject(
                $decision,
                $byUserId,
                $reason,
                resolvedMatchedType: 'tracked_property',
                resolvedMatchedId: $resultTp->id,
            );

            return $resultTp;
        });
    }

    /**
     * Marks the source_chain entry a specific capture wrote onto a
     * TrackedProperty as disputed — never removes or alters the entry's own
     * field_changes/fields_contributed, only adds a marker a display screen
     * can check for. Matches on (type, ref) and takes the MOST RECENT
     * matching entry (a capture can in principle recur; the latest is the
     * one the rejection is actually about).
     */
    private function markSourceChainEntryDisputed(TrackedProperty $tp, array $source, int $byUserId): void
    {
        $chain = $tp->source_chain ?? [];
        $matchIndex = null;
        foreach ($chain as $i => $entry) {
            if (($entry['type'] ?? null) === ($source['type'] ?? null) && ($entry['ref'] ?? null) === ($source['ref'] ?? null)) {
                $matchIndex = $i; // keep scanning — last match wins
            }
        }
        if ($matchIndex === null) {
            return;
        }

        $chain[$matchIndex]['disputed'] = true;
        $chain[$matchIndex]['disputed_at'] = now()->toIso8601String();
        $chain[$matchIndex]['disputed_by_user_id'] = $byUserId;

        $tp->update(['source_chain' => $chain]);
    }

    /**
     * 2026-08-20 (Johan, LIVE, urgent) — moves the owner(s) that THIS
     * specific capture contributed to $rejectedTp across to $resultTp when
     * a match is rejected. Only rows Api\DeedsCaptureController::
     * reconcileOwners() could have written at the moment this capture ran
     * qualify — a tight window from $decidedAt, matching the precision
     * markSourceChainEntryDisputed() already relies on. Never touches an
     * owner $rejectedTp had from an earlier, unrelated capture: the
     * rejection says THIS capture doesn't belong to $rejectedTp, not that
     * $rejectedTp's own history is wrong.
     *
     * Rows are RELOCATED (updated tracked_property_id), never duplicated —
     * $rejectedTp keeps everything it had before this capture touched it;
     * $resultTp gains exactly what this capture had linked. If $resultTp
     * already has an owner (the enrich()/replacement-picked path), never
     * overwrite it — same never-blind-overwrite rule as reconcileOwners();
     * the relocated row still lands on $resultTp for the agent to see and
     * resolve, just not auto-promoted to owner_contact_id.
     */
    private function carryContributedOwnersToRejectionResult(
        TrackedProperty $rejectedTp,
        TrackedProperty $resultTp,
        ?Carbon $decidedAt
    ): void {
        if ($decidedAt === null || $resultTp->is($rejectedTp)) {
            return;
        }

        $contributed = \App\Models\Prospecting\TrackedPropertyOwner::where('tracked_property_id', $rejectedTp->id)
            ->where('created_at', '>=', $decidedAt)
            ->where('created_at', '<=', $decidedAt->copy()->addSeconds(10))
            ->get();

        if ($contributed->isEmpty()) {
            return;
        }

        $resultAlreadyHasOwner = $resultTp->owner_contact_id !== null
            || \App\Models\Prospecting\TrackedPropertyOwner::where('tracked_property_id', $resultTp->id)->exists();

        foreach ($contributed as $owner) {
            $owner->tracked_property_id = $resultTp->id;
            $owner->save();
        }

        if (!$resultAlreadyHasOwner) {
            $primary = $contributed->first(fn ($o) => $o->conflict_flagged_at === null) ?? $contributed->first();
            if ($primary && $primary->conflict_flagged_at === null) {
                $resultTp->owner_contact_id = $primary->contact_id;
                $resultTp->save();
            }
        }
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
     * CX-102 part 2 (2026-08-19, Johan) — read-only preview of what
     * promoteToStock() would resolve THIS TrackedProperty to right now: an
     * EXISTING Property it would merge into (same erf+suburb / scheme+
     * section / normalised-address rules resolvePropertyMatch() uses at
     * promote time), or null if promoting would create a brand-new one.
     * Side-effect-free (SELECT only, no writes, no events) — safe to call
     * on every row of a review screen, not just at the moment of promotion.
     *
     * This is the deeds-capture equivalent of CX-101's "is this really
     * already our stock" question for MIC — before an agent presses
     * Promote, they can see whether it lands on existing (live or stale)
     * stock or creates something new.
     */
    public function previewPropertyMatch(TrackedProperty $tp): ?Property
    {
        return $this->resolvePropertyMatch($tp);
    }

    /**
     * 5-strategy resolution. First match wins. Returns null on no match.
     *
     * All queries bypass the global AgencyScope and filter by the explicit
     * $agencyId so the service is safe to invoke from background workers.
     */
    private function resolveMatch(int $agencyId, array $facts, array $source): ?TrackedProperty
    {
        // CX-102 part 2 — clear any strategy/candidates left over from a
        // previous call before this one runs, so a caller that reads them
        // straight after can never see stale state from an earlier match.
        $this->lastMatchStrategy = null;
        $this->lastMatchCandidates = null;

        // Strategy 0: Address-history match (Phase C2 silent-killer fix).
        // Consult tracked_property_addresses BEFORE the portal-ref / GPS / erf
        // strategies — an agent who has corrected a wrong address once should
        // never see the same wrong-address ingestion create a duplicate TP again.
        $historyHit = $this->resolveByAddressHistory($agencyId, $facts, $source);
        if ($historyHit) {
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
                if ($tp && ! $this->numbersConflict($facts, $tp) && $this->acceptCandidate($agencyId, $source, $tp, '1_source_ref')) {
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
            if ($byCmaGps && ! $this->numbersConflict($facts, $byCmaGps) && $this->acceptCandidate($agencyId, $source, $byCmaGps, '2_gps_cma')) {
                return $byCmaGps;
            }

            $byGps = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->whereBetween('latitude', [$lat - $tol, $lat + $tol])
                ->whereBetween('longitude', [$lng - $tol, $lng + $tol])
                ->first();
            if ($byGps && ! $this->numbersConflict($facts, $byGps) && $this->acceptCandidate($agencyId, $source, $byGps, '2_gps_latlng')) {
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
            if ($erfMatch && ! $this->numbersConflict($facts, $erfMatch) && $this->acceptCandidate($agencyId, $source, $erfMatch, '3_erf_suburb')) {
                return $erfMatch;
            }
        }

        // Strategy 3b: Scheme identity + section/unit number (2026-08-27,
        // Johan/cc3's finding — a sectional-title re-capture with no erf
        // number created a duplicate instead of finding the existing unit).
        // Erf number does NOT identify a sectional-title unit (strategy 3's
        // own comment above: every unit in a scheme shares one erf) — a
        // capture that has no street address yet either (address geocoding
        // not run, or the deed itself carries no physical address, both
        // real and common) then had no strategy left that could ever find
        // it: strategy 4 needs street_number/street_name, strategy 5 needs
        // street_name/address, strategy 2 needs GPS. Every one of them
        // silently required an identifier a bare sectional-title deed does
        // not carry. The one identifier it DOES always carry is its own
        // legal identity: scheme (name or the deeds-office scheme_number)
        // plus section number — the exact SA sectional-title analogue of
        // erf+suburb for freehold, same "structural identity, not address"
        // tier, so it sits right after strategy 3. scheme_number, being the
        // registered legal number, is preferred over the free-text scheme/
        // complex name when both are available. section_number is CMA/deed-
        // reliable (numbersConflict()'s own comment); unit_number is the
        // fallback when section_number wasn't captured on this pass.
        // numbersConflict() still runs — a genuinely different unit that
        // happens to share a scheme is vetoed exactly as strategy 3 is.
        $schemeNumber = trim((string) ($facts['scheme_number'] ?? ''));
        $schemeName   = trim((string) ($facts['complex_name'] ?? $facts['scheme_name'] ?? ''));
        $unitKey      = trim((string) ($facts['section_number'] ?? $facts['unit_number'] ?? ''));
        if ($unitKey !== '' && ($schemeNumber !== '' || $schemeName !== '')) {
            $schemeQuery = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where(function ($w) use ($unitKey) {
                    $w->where('section_number', $unitKey)
                        ->orWhere('unit_number', $unitKey);
                });
            if ($schemeNumber !== '') {
                $schemeQuery->whereRaw('LOWER(TRIM(scheme_number)) = ?', [mb_strtolower($schemeNumber)]);
            } else {
                $schemeQuery->where(function ($w) use ($schemeName) {
                    $w->whereRaw('LOWER(TRIM(complex_name)) = ?', [mb_strtolower($schemeName)])
                        ->orWhereRaw('LOWER(TRIM(scheme_name)) = ?', [mb_strtolower($schemeName)]);
                });
            }
            $schemeMatch = $schemeQuery->first();
            if ($schemeMatch && ! $this->numbersConflict($facts, $schemeMatch) && $this->acceptCandidate($agencyId, $source, $schemeMatch, '3b_scheme_section')) {
                return $schemeMatch;
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
            if ($addressMatch && ! $this->numbersConflict($facts, $addressMatch) && $this->acceptCandidate($agencyId, $source, $addressMatch, '4_normalised_address')) {
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
                // Collect EVERY plausible candidate, not just the first — 2026-08-19
                // (Johan, CX-102). Taking the first candidate that overlapped used to
                // mean "whichever record happens to have the lowest id wins," with no
                // regard for which one the capture actually describes. That's exactly
                // how 417 and 381 Von Baumbach Avenue (two different tracked
                // properties on the same street) got confused: both tied on
                // word-overlap, and the older record won by accident of creation
                // order.
                $tied = [];
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
                        $tied[] = $cand;
                    }
                }

                // CX-102 part 2 — drop any candidate an agent has already rejected
                // for THIS capture before deciding what to do with what's left, so
                // a rejected pairing can never resurface here even as a runner-up.
                if ($this->isDecisionTrackedSource($source) && !empty($tied)) {
                    $tied = array_values(array_filter(
                        $tied,
                        fn ($cand) => !$this->isVetoedCandidate($agencyId, $source, $cand)
                    ));
                }

                if (count($tied) === 1) {
                    $this->lastMatchStrategy = '5_token_overlap';
                    return $tied[0];
                }

                if (count($tied) > 1) {
                    // GPS tie-break (2026-08-19, Johan, CX-102) — more than one
                    // candidate matched on words alone. Prefer whichever is
                    // physically closest to the incoming capture's own coordinates
                    // instead of silently taking the oldest. This is exactly what
                    // would have resolved Von Baumbach Avenue correctly: the deed's
                    // own GPS sat ~10m from the correct record and ~1km from the
                    // one the old rule picked.
                    $this->lastMatchCandidates = array_map(
                        fn ($c) => ['type' => 'tracked_property', 'id' => $c->id, 'label' => $this->candidateLabel($c)],
                        $tied,
                    );

                    $winner = $this->nearestByGps($facts, $tied);
                    if ($winner !== null) {
                        $this->lastMatchStrategy = '5_token_overlap_gps_tiebreak';
                        return $winner;
                    }

                    // Neither side carries usable GPS to break the tie — fall back
                    // to the original rule (oldest candidate) rather than refuse to
                    // match. Johan (2026-08-19): staff keep working; queuing an
                    // unresolved tie for review is deliberately NOT built here —
                    // that's what the agent-facing "Not the same property" control
                    // is for (CX-102 part 2), not a silent hold.
                    $this->lastMatchStrategy = '5_token_overlap_untiebroken';
                    return $tied[0];
                }
            }
        }

        return null;
    }

    /**
     * GPS tie-break for Strategy 5 (2026-08-19, Johan, CX-102). When the loose
     * word-overlap match ties between several candidates, prefer whichever is
     * physically closest to the incoming capture's own coordinates. Prefers
     * cma_gps over plain lat/lng on both sides, matching Strategy 2's own
     * preference order.
     *
     * Returns null (no opinion) when the incoming facts carry no GPS at all, or
     * when NONE of the tied candidates have GPS on file — the caller falls back
     * to the original oldest-wins rule in that case, rather than refusing to
     * match (Johan: staff keep working, this never blocks on a tie it can't
     * break).
     *
     * @param  array<int, TrackedProperty>  $candidates
     */
    private function nearestByGps(array $facts, array $candidates): ?TrackedProperty
    {
        $lat = $facts['cma_gps_lat'] ?? $facts['latitude'] ?? null;
        $lng = $facts['cma_gps_lng'] ?? $facts['longitude'] ?? null;
        if ($lat === null || $lng === null) {
            return null;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;

        $nearest = null;
        $nearestDistanceSq = null;
        foreach ($candidates as $cand) {
            $candLat = $cand->cma_gps_lat ?? $cand->latitude ?? null;
            $candLng = $cand->cma_gps_lng ?? $cand->longitude ?? null;
            if ($candLat === null || $candLng === null) {
                continue;
            }
            // Flat-plane squared distance, not haversine — every candidate here
            // already shares the same suburb (Strategy 5's own gate), so the
            // span is at most a few km; the same degree-based comparison the
            // rest of this service already uses for GPS (whereBetween on raw
            // degrees) is precise enough to rank candidates at this scale.
            $distanceSq = (((float) $candLat) - $lat) ** 2 + (((float) $candLng) - $lng) ** 2;
            if ($nearestDistanceSq === null || $distanceSq < $nearestDistanceSq) {
                $nearestDistanceSq = $distanceSq;
                $nearest = $cand;
            }
        }

        return $nearest;
    }

    /**
     * CX-102 part 2 gate — true when this source type has an agent-facing
     * screen to show/reject a match decision on (see
     * DECISION_TRACKED_SOURCE_TYPES). Everything else (P24, PP, CMA, manual)
     * matches exactly as before: no decision recorded, no veto consulted —
     * zero added cost on high-volume ingestion with no screen to show it on.
     */
    private function isDecisionTrackedSource(array $source): bool
    {
        return in_array($source['type'] ?? null, self::DECISION_TRACKED_SOURCE_TYPES, true)
            && !empty($source['ref']);
    }

    /**
     * CX-102 part 2 — the subject_key a decision-tracked source is recorded
     * and vetoed under. Stable across repeat captures of the same source
     * (the whole point: re-importing the same deed must not re-offer a
     * property an agent already rejected for it).
     */
    private function subjectKeyFor(array $source): string
    {
        return $source['type'] . ':' . $source['ref'];
    }

    /**
     * CX-102 part 2 — true when THIS capture has already had THIS candidate
     * rejected by an agent. Only ever true for decision-tracked sources
     * (see isDecisionTrackedSource) — every other source skips the lookup
     * entirely rather than pay for a check nothing can ever have rejected.
     */
    private function isVetoedCandidate(int $agencyId, array $source, TrackedProperty $candidate): bool
    {
        if (!$this->isDecisionTrackedSource($source)) {
            return false;
        }

        return app(PropertyMatchDecisionService::class)->isRejected(
            $agencyId,
            'deeds_capture',
            $this->subjectKeyFor($source),
            'tracked_property',
            $candidate->id,
        );
    }

    /**
     * CX-102 part 2 — accept a resolved candidate: vetoes it if an agent has
     * already rejected this exact (capture, property) pairing, otherwise
     * records which strategy produced it (read back by matchOrCreate()
     * immediately after resolveMatch() returns) and returns true. Every
     * strategy's return site is gated on this rather than returning
     * unconditionally, so a rejected candidate falls through to the NEXT
     * strategy exactly as if this one had found nothing — never a dead end,
     * per Johan's "staff keep working."
     */
    private function acceptCandidate(int $agencyId, array $source, TrackedProperty $candidate, string $strategy): bool
    {
        if ($this->isVetoedCandidate($agencyId, $source, $candidate)) {
            Log::debug('TrackedPropertyMatchOrCreateService::resolveMatch candidate VETOED (agent-rejected pairing)', [
                'agency_id' => $agencyId, 'tracked_property_id' => $candidate->id, 'strategy' => $strategy,
            ]);
            return false;
        }

        $this->lastMatchStrategy = $strategy;

        return true;
    }

    /**
     * CX-102 part 2 — plain-language sentence for the strategy that produced
     * a match, generated from the REAL values seen at match time (never
     * reconstructed later from whatever the matched record looks like
     * today — Johan, verbatim). Mirrors the resolution priority order in
     * the class doc comment.
     */
    private function reasonFor(string $strategy, array $facts, TrackedProperty $tp): string
    {
        return match ($strategy) {
            '0_address_history_exact' => 'A previous capture already confirmed this exact address for this property.',
            '0_address_history_gps' => 'GPS is within about 5 metres of an address already confirmed for this property.',
            '1_source_ref' => 'Same source reference as a previous capture of this property.',
            '2_gps_cma', '2_gps_latlng' => 'GPS is within about 5 metres of this property\'s known location.',
            '3_erf_suburb' => 'Same erf number (' . trim((string) ($facts['erf_number'] ?? '?')) . ') and suburb.',
            '3b_scheme_section' => 'Same sectional scheme and section/unit number (' . trim((string) ($facts['section_number'] ?? $facts['unit_number'] ?? '?')) . ').',
            '4_normalised_address' => 'Street number, street name and suburb all match exactly.',
            '5_token_overlap' => 'Address is a close text match on this street and suburb — no other property on this street was a candidate.',
            '5_token_overlap_gps_tiebreak' => 'Address is a close text match; more than one property on this street was possible, and GPS confirmed this one.',
            '5_token_overlap_untiebroken' => 'Address is a close text match; more than one property on this street was possible and neither GPS nor any other detail could tell them apart — this one was picked automatically. Worth checking.',
            default => 'Matched automatically.',
        };
    }

    /**
     * CX-102 part 2 — short human label for a tracked property, used in the
     * candidates list an agent picks from when a match ties. Deliberately
     * the same shape the Deeds Capture screen already renders for a row's
     * headline address (street number/name, suburb).
     */
    private function candidateLabel(TrackedProperty $tp): string
    {
        $addr = trim(($tp->street_number ?? '') . ' ' . ($tp->street_name ?? ''));
        return trim($addr . ($tp->suburb ? ', ' . $tp->suburb : '')) ?: ('Tracked property #' . $tp->id);
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
    private function resolveByAddressHistory(int $agencyId, array $facts, array $source): ?TrackedProperty
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
                if ($tp && ! $this->numbersConflict($facts, $tp) && $this->acceptCandidate($agencyId, $source, $tp, '0_address_history_exact')) {
                    return $tp;
                }
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
                if ($tp && ! $this->numbersConflict($facts, $tp) && $this->acceptCandidate($agencyId, $source, $tp, '0_address_history_gps')) {
                    return $tp;
                }
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
            // '-'" on the Deeds Capture screen.
            $existing = is_string($rawExisting) ? $this->normaliseCapturedScalar($rawExisting) : $rawExisting;
            $existingIsAbsent = ($existing === null || $existing === '');
            // "Junk" = there IS something stored (raw), but it normalises to
            // nothing — a placeholder written before this normalisation
            // existed. Distinct from a column that has simply always been
            // empty: junk is data already lost, not data being protected.
            $existingIsJunk = $existingIsAbsent && $rawExisting !== null && $rawExisting !== '';

            if ($newVal === null) {
                // canonicalFactsForWrite() keeps a key with a null value when
                // this capture ATTEMPTED the field and found nothing usable
                // (a placeholder or blank) — distinct from never asking at
                // all (which never reaches this loop). "Absent never
                // overwrites" is the right guard for a genuine existing
                // value, so it holds here unconditionally. But a STORED
                // placeholder isn't real data the guard should protect — it's
                // junk outliving every capture that also has nothing to
                // offer, which is exactly how property_type stayed stuck at
                // '-' on tracked_property 402 through this morning's
                // recapture (2026-08-19, Johan). Only a SOURCE_ALWAYS_WINS
                // source (deeds_capture) is trusted to clear it; every other
                // source's behaviour is byte-for-byte unchanged from before
                // this field could even reach here.
                if ($alwaysWins && $existingIsJunk) {
                    $diff[$key] = null;
                    $fieldChanges[] = ['field' => $key, 'previous' => $rawExisting, 'new' => null, 'change_type' => 'cleared'];
                }
                continue;
            }

            // Empty (or junk) existing → adopt the new value (covers most
            // enrichments, and cleans up junk as a side effect of a genuine
            // fill — reported honestly as a gain, not "replaced '-' with
            // X"). Unconditional: absent is not a value, so there is nothing
            // to "replace" here regardless of source.
            if ($existingIsAbsent) {
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
            // Sectional title unit extent (.ai/specs/deeds-capture.md §6 extent
            // contract, 2026-08-19) — its OWN column, deliberately separate from
            // both erf_size_m2 (freehold Extent) and cadastral_extent (freehold
            // Cadastral extent). Never substituted for either.
            'section_extent_m2',
            // Deeds-specific columns (.ai/specs/deeds-capture.md) — added here
            // so they flow through the SAME enrich() diff + precedence + audit
            // mechanism as every other fact, instead of a second, separate
            // unconditional-overwrite write in DeedsCaptureController. Harmless
            // to every other source: none of them ever populate these keys.
            'deeds_office', 'scheme_name', 'scheme_number', 'section_number',
            'bond_holder', 'bond_amount', 'sale_type', 'deeds_registered_date',
        ];

        // 2026-08-19 (Johan) — an attempted-but-placeholder field is kept as an
        // EXPLICIT null here, not dropped. "Absent never overwrites" is the
        // right guard for a genuine existing value, but it has a failure mode:
        // a field already poisoned with a stored placeholder (from before this
        // whole normalisation existed) could never be cleaned, because every
        // later capture that also only has a placeholder for it looked
        // identically absent — the key just vanished before enrich() ever saw
        // it. Keeping the key (with a null value) lets enrich() tell "this
        // capture tried and found nothing" apart from "this capture never
        // asked" — only the former is eligible to clear existing junk. A
        // non-deeds source (create() on a fresh row, or any other ingest path)
        // treats this null exactly like an absent key: nothing is written.
        $out = [];
        foreach ($writable as $col) {
            if (!array_key_exists($col, $facts)) {
                continue;
            }
            $out[$col] = $this->normaliseCapturedScalar($facts[$col]);
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
     * Canonicalise street name so "Mitchell St" and "MITCHELL STREET" land in
     * the same bucket. Delegates to TrackedPropertyAddress::normaliseStreet()
     * (2026-08-22, matcher-accuracy fix) — that is now the ONE implementation
     * (Crescent/ordinals/whitespace/apostrophe/unit-prefix/end-anchored
     * abbreviations all live there), so this method and TrackedPropertyAddress
     * can never silently drift apart on what "same street" means again.
     */
    private function normaliseStreetName(?string $name): ?string
    {
        return TrackedPropertyAddress::normaliseStreet($name);
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
        // size_m2 (2026-08-19, .ai/specs/deeds-capture.md §6 extent contract)
        // — the property record's own sectional/unit-size field ("Floor size"
        // in the UI). Fed from tracked_properties.section_extent_m2 ONLY —
        // never from erf_size_m2/cadastral_extent, and never the other
        // direction. See promoteToStock() below for why this pairing exists.
        'size_m2',
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
                // 2026-08-21 (property 15698 incident) — unit_number is compared
                // leading-zero-normalised, not by exact string, on BOTH sides: a
                // deed's section_number '2' must match a stored unit_number '02'.
                // Fetch every unit in the named complex (a small set) and filter in
                // PHP with normaliseNumericIdentifier() rather than exact SQL
                // equality, so this can never silently miss a real match again.
                $sectionKey = TrackedPropertyAddress::normaliseNumericIdentifier($section);
                $matches = Property::queryWithoutAgencyScope()
                    ->where('agency_id', $tp->agency_id)
                    ->whereNull('deleted_at')
                    ->whereRaw('LOWER(complex_name) = ?', [mb_strtolower($complexName)])
                    ->get()
                    ->filter(fn ($candidate) => TrackedPropertyAddress::normaliseNumericIdentifier($candidate->unit_number) === $sectionKey)
                    ->values();
                if ($match = $this->resolveOrLogAmbiguous($tp, $matches, 'sectional')) {
                    return $match;
                }
            }
        } elseif (filled($tp->erf_number) && filled($tp->suburb)) {
            // Same leading-zero normalisation for erf/stand number. Scoped to the
            // suburb (indexed) + erf_number NOT NULL to keep the PHP-side filter set
            // small — a suburb-wide fetch, not agency-wide.
            //
            // 2026-08-22 (matcher-accuracy build, real data evidence: stand 1166,
            // Lynne Avenue, Ramsgate — 6 genuinely different portions sharing the
            // identical bare stand number "1166" with no portion suffix captured
            // anywhere) — a bare erf/stand number can legitimately match MORE THAN
            // ONE distinct Property when a subdivided erf's portion was never
            // captured structurally. Collecting every candidate (rather than
            // ->first()) is what makes that ambiguity visible instead of silently
            // merging onto whichever portion happens to sort first.
            $erfKey = TrackedPropertyAddress::normaliseNumericIdentifier($tp->erf_number);
            $matches = Property::queryWithoutAgencyScope()
                ->where('agency_id', $tp->agency_id)
                ->whereNull('deleted_at')
                ->whereNotNull('erf_number')
                ->where('suburb_normalised', TrackedPropertyAddress::normaliseSuburb($tp->suburb))
                ->get()
                ->filter(fn ($candidate) => TrackedPropertyAddress::normaliseNumericIdentifier($candidate->erf_number) === $erfKey)
                ->values();
            if ($match = $this->resolveOrLogAmbiguous($tp, $matches, 'freehold_erf')) {
                return $match;
            }
        }

        // Fallback: normalised address + suburb, for either title type.
        if (filled($tp->street_number) && filled($tp->street_name) && filled($tp->suburb)) {
            $matches = Property::queryWithoutAgencyScope()
                ->where('agency_id', $tp->agency_id)
                ->whereNull('deleted_at')
                ->where('street_number', trim((string) $tp->street_number))
                ->where('street_name_normalised', TrackedPropertyAddress::normaliseStreet($tp->street_name))
                ->where('suburb_normalised', TrackedPropertyAddress::normaliseSuburb($tp->suburb))
                ->get()
                ->reject(fn ($candidate) => $this->propertyIdentityConflicts($tp, $candidate))
                ->values();
            if ($match = $this->resolveOrLogAmbiguous($tp, $matches, 'address_fallback')) {
                return $match;
            }
        }

        // NEW (2026-08-22, property 15698 gap) — GPS proximity. resolvePropertyMatch()
        // never checked distance at all before this: the panel already rendered a GPS
        // comparison row flagged 'used' => false with a comment saying exactly that.
        // 15698's nearest real candidate was 24.6m away and this method never looked —
        // structural identity (erf/section/address) found nothing, so it fell straight
        // to "create new" with zero awareness a close neighbour existed.
        //
        // Tight radius (25m) and NEVER auto-accepted even when it finds exactly one
        // candidate — GPS is a corroborating signal, not an identity one (geocode
        // confidence varies: suburb-centroid vs rooftop-accurate are indistinguishable
        // from the coordinates alone). Always logged for a human to review, never
        // silently linked and never silently dropped.
        if ($tp->latitude !== null && $tp->longitude !== null) {
            $tol = 0.00025; // ~25m bounding-box pre-filter; exact haversine below
            $nearby = Property::queryWithoutAgencyScope()
                ->where('agency_id', $tp->agency_id)
                ->whereNull('deleted_at')
                ->whereNotNull('latitude')->whereNotNull('longitude')
                ->whereBetween('latitude', [(float) $tp->latitude - $tol, (float) $tp->latitude + $tol])
                ->whereBetween('longitude', [(float) $tp->longitude - $tol, (float) $tp->longitude + $tol])
                ->get()
                ->filter(fn ($candidate) => TrackedPropertyAddress::haversineMetres(
                    (float) $tp->latitude, (float) $tp->longitude, (float) $candidate->latitude, (float) $candidate->longitude
                ) <= 25.0)
                ->reject(fn ($candidate) => $this->propertyIdentityConflicts($tp, $candidate))
                ->values();
            if ($nearby->isNotEmpty()) {
                Log::warning('TrackedPropertyMatchOrCreateService::resolvePropertyMatch GPS-proximity candidate(s) found — NOT auto-linked, needs human review', [
                    'agency_id' => $tp->agency_id, 'tracked_property_id' => $tp->id,
                    'candidate_property_ids' => $nearby->pluck('id')->all(),
                ]);
            }
        }

        return null;
    }

    /**
     * A strategy's candidate set collapsed to exactly one → that one, safely.
     * More than one (the Villa Del Sol / Lynne Avenue class of case — several
     * genuinely different units/portions share the same bare identity key) →
     * NEVER silently pick the first. Logged so the ambiguity is visible, and
     * this returns null exactly as if nothing had matched, so the caller falls
     * through to the next strategy (or "create new") rather than guessing —
     * "no stock" is the SAFE failure mode here; a wrong auto-merge is not.
     *
     * @param  \Illuminate\Support\Collection<int, Property>  $matches
     */
    private function resolveOrLogAmbiguous(TrackedProperty $tp, $matches, string $strategy): ?Property
    {
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1) {
            Log::warning('TrackedPropertyMatchOrCreateService::resolvePropertyMatch AMBIGUOUS — multiple candidates, none auto-linked', [
                'agency_id' => $tp->agency_id, 'tracked_property_id' => $tp->id, 'strategy' => $strategy,
                'candidate_property_ids' => $matches->pluck('id')->all(),
            ]);
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
        bool $forceCreate = false,
    ): Property {
        return DB::transaction(function () use ($trackedPropertyId, $promotingUserId, $propertyFields, $forceCreate) {
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

            // Deeds-capture duplicate-match take rule (Johan, 2026-08-21) — "Different
            // property" override. The agent has explicitly said this is NOT the
            // property resolvePropertyMatch() found, so skip it entirely and create
            // fresh, exactly as if no match existed. Opt-in and additive — every
            // other caller (the generic Tracked Properties promote button, etc.)
            // is unaffected; default false preserves existing behaviour everywhere.
            $existingProperty = $forceCreate ? null : $this->resolvePropertyMatch($tp);

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
                    // Extent contract (2026-08-19, .ai/specs/deeds-capture.md
                    // §6 Part A) — two DIFFERENT TP columns to two DIFFERENT
                    // Property columns, never crossed. This used to read
                    // $tp->cadastral_extent into erf_size_m2, which is exactly
                    // how a sectional unit's Section extent (which cadastral_
                    // extent was ALSO overloaded to carry, pre-fix) ended up in
                    // an erf-size column on properties 6166/6186 — a freehold
                    // erf and a sectional floor area are not interchangeable.
                    'erf_size_m2'              => $tp->erf_size_m2,
                    'size_m2'                  => $tp->section_extent_m2,
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
                // PROSPECTING (Johan, 2026-08-20/21 — .ai/specs/2026-08-20-
                // property-status-prospecting.md): this single Property::create()
                // is the shared gateway for BOTH promote buttons -- the
                // deeds-capture screen's and the generic Tracked Properties
                // screen's (which also serves P24/PP/cmainfo/chrome-capture
                // and manually-tracked properties). isFromAutomatedIngest()
                // is the one place that distinguishes them, so both promote
                // buttons stay correctly branched through one method instead
                // of needing their own copies of this logic. A purely
                // manually-tracked TP (an agent typed it in, nothing
                // automated ever contributed) stays 'draft' -- unchanged,
                // exactly matching "properties created by an agent still get
                // Draft".
                $status = $tp->isFromAutomatedIngest() ? Property::STATUS_PROSPECTING : 'draft';
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
                        'status'                   => $status,
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
                        // Extent contract (2026-08-19, .ai/specs/deeds-capture.md
                        // §6 Part A) — same pairing as the REFRESH branch above:
                        // erf_size_m2 (freehold erf) and size_m2 (sectional unit
                        // "Floor size") are fed from two DIFFERENT TP columns and
                        // never crossed. Neither was carried through on CREATE at
                        // all before this fix — a brand-new promotion silently
                        // lost the extent entirely.
                        'erf_size_m2'              => $tp->erf_size_m2,
                        'size_m2'                  => $tp->section_extent_m2,
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
