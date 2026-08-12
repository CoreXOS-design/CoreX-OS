<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Scopes\AgencyScope;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase A.2.5 — collision detector for the map's "Prospect Now" button.
 *
 * Given a Portal Stock record's address/GPS, returns one of six prospect
 * statuses describing HFC's current relationship to that address. The map
 * client uses the status to render the correct CTA (or block the click +
 * route the agent to coordinate with the responsible colleague).
 *
 * Schema note (corrected from spec at investigation time):
 *   - This codebase has NO `mandates` table. The mandate lifecycle is
 *     tracked via the `properties.status` enum (active / available /
 *     for_sale / to_let / draft / sold).
 *   - `deals` table has no `status` column either — uses
 *     accepted_status / commission_status.
 *   - The spec's `previously_held` status (mandate expired within 60 days)
 *     has no analog in this schema today. We don't emit it; the JS handles
 *     the case defensively if it ever appears.
 *
 * Status values:
 *   available        — no TP/Property match → safe to prospect
 *   held             — Property exists with a live status
 *   own_draft        — draft Property assigned to the current agent
 *   other_draft      — draft Property assigned to a different agent
 *   previously_sold  — Property was sold (status='sold')
 *   previously_held  — DEFINED for spec parity, not currently emitted
 */
final class MapProspectStatusService
{
    /** Property.status values that mean "HFC actively has this property right now". */
    private const HELD_STATUSES = ['active', 'available', 'for_sale', 'to_let'];

    /**
     * A coordinate shared by AT LEAST this many tracked properties is a
     * geocode default / suburb-centroid, not a real per-property location — it
     * cannot disambiguate one property from another, so proximity matching on it
     * is meaningless. (Real properties never share an identical 7-dp coordinate;
     * on QA1, 391 addressless tracked properties collide on the Ramsgate centroid
     * that happens to sit on property #2427 "6 Kerk St", so a no-address Pitch Now
     * on ANY of them resolved to 6 Kerk St.) Set well above any legitimate
     * same-building coincidence.
     */
    private const SHARED_COORD_THRESHOLD = 5;

    public function __construct(
        private readonly TrackedPropertyMatchOrCreateService $matcher = new TrackedPropertyMatchOrCreateService(),
    ) {}

    /**
     * @param array{address: ?string, street_number?: ?string, latitude: ?float, longitude: ?float, suburb: ?string} $facts
     * @return array{status: string, property_id?: int, agent_name?: ?string, days_in_state?: int, state_label?: string, sale_date?: ?string, expired_at?: ?string}
     */
    public function resolve(array $facts, int $agencyId, int $currentUserId): array
    {
        $factsForLookup = array_filter([
            'address'       => $facts['address']       ?? null,
            // AT-URGENT — without this, TrackedPropertyMatchOrCreateService's
            // numbersConflict() veto (built to stop "1 The Oval" colliding with
            // "2 The Oval") can never fire for a Pitch Now resolution, since it
            // only activates when a street_number is present on both sides.
            'street_number' => $facts['street_number'] ?? null,
            'latitude'      => $facts['latitude']       ?? null,
            'longitude'     => $facts['longitude']      ?? null,
            'suburb'        => $facts['suburb']         ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        if (empty($factsForLookup)) {
            return ['status' => 'available'];
        }

        // Non-disambiguating coordinate guard. A GPS coordinate that many tracked
        // properties share (a geocode default / suburb-centroid) can't tell one
        // property from another, so BOTH the 5-strategy matcher's GPS strategy and
        // the direct GPS fallback below would collapse disparate addressless listings
        // onto whichever property happens to sit at that point. Drop such a coordinate
        // so we never proximity-match on it; with no usable key left, the caller falls
        // through to promoting the listing's OWN property (cc1's promote path).
        if (isset($factsForLookup['latitude'], $factsForLookup['longitude'])
            && $this->isNonDisambiguatingCoordinate(
                (float) $factsForLookup['latitude'],
                (float) $factsForLookup['longitude'],
                $agencyId,
            )) {
            unset($factsForLookup['latitude'], $factsForLookup['longitude']);
            // With no address either (addressless Pitch Now), nothing left to match on.
            if (empty($factsForLookup) || !isset($factsForLookup['address'])) {
                return ['status' => 'available'];
            }
        }

        // A.2.7 — two-layer match. First the TP-based 5-strategy resolver
        // (handles addresses/scheme matches); when that yields no usable
        // Property, fall through to a direct GPS-proximity lookup against
        // the `properties` table itself. HFC has properties (Tucker Mews
        // §9, Madeira Gardens, etc.) that exist as Property rows directly
        // without a TrackedProperty linkage; the GPS fallback finds them.
        $property = null;
        $tp = $this->matcher->findExistingMatch($agencyId, $factsForLookup);
        if ($tp && $tp->promoted_to_property_id) {
            // Strip only AgencyScope — the explicit ->where('agency_id', …)
            // below scopes the lookup to the resolving agency, and stripping
            // AgencyScope avoids a clash when the auth user's effective
            // agency differs from the resolving agency (super-admin or
            // owner-agency-switch flows). SoftDeletes + BranchScope stay
            // active: archived / withdrawn properties must NOT resolve as
            // 'held', and branch-isolated users must not see cross-branch
            // properties on the map. Per CLAUDE.md NN#7.
            $property = Property::withoutGlobalScope(AgencyScope::class)
                ->where('id', $tp->promoted_to_property_id)
                ->where('agency_id', $agencyId)
                ->first();
        }
        if ($property === null) {
            $property = $this->findHfcPropertyByGps($factsForLookup, $agencyId);
        }
        if ($property === null) {
            return ['status' => 'available'];
        }

        $status = (string) ($property->status ?? '');

        if (in_array($status, self::HELD_STATUSES, true)) {
            return [
                'status'      => 'held',
                'property_id' => (int) $property->id,
                'agent_name'  => $this->resolveAgentName($property->agent_id),
            ];
        }

        if ($status === 'draft') {
            $isOwn = (int) $property->agent_id === (int) $currentUserId;
            $daysInState = (int) CarbonImmutable::now()->diffInDays(
                CarbonImmutable::parse($property->updated_at ?? $property->created_at ?? 'now'),
                absolute: true,
            );
            return [
                'status'        => $isOwn ? 'own_draft' : 'other_draft',
                'property_id'   => (int) $property->id,
                'agent_name'    => $this->resolveAgentName($property->agent_id),
                'days_in_state' => $daysInState,
                'state_label'   => 'draft',
            ];
        }

        if ($status === 'sold') {
            $saleDate = $this->resolveSaleDate($property);
            return [
                'status'      => 'previously_sold',
                'property_id' => (int) $property->id,
                'sale_date'   => $saleDate,
            ];
        }

        // Unknown / new status — safest to allow prospect; client falls
        // through to the 'available' default.
        return ['status' => 'available'];
    }

    /**
     * A.2.7 — GPS-proximity Property fallback. Many HFC properties exist
     * as direct Property rows without a TrackedProperty linkage, so the
     * TP-based matcher misses them. Coordinate-rounded lookup catches
     * those — uses the same 5dp ≈ ~1m / ~20m bbox precedent A.1 set for
     * location grouping.
     *
     * Returns the most-recently-updated matching property to prefer
     * current state when an agent has both an active mandate and a
     * historic sold record at the same address.
     */
    private function findHfcPropertyByGps(array $facts, int $agencyId): ?Property
    {
        if (!isset($facts['latitude'], $facts['longitude'])) return null;
        $lat = (float) $facts['latitude'];
        $lng = (float) $facts['longitude'];
        if ($lat === 0.0 || $lng === 0.0) return null;

        // ~20m bounding box. 0.00018° ≈ 20m at SA coastal latitudes (1°
        // lat ≈ 111km; 1° lng ≈ 95km at -30°).
        //
        // Strip only AgencyScope (same reason as resolve() above); leave
        // SoftDeletes + BranchScope active. The explicit whereNull(
        // 'deleted_at') below is now redundant with SoftDeletes restored
        // but is kept as belt-and-suspenders.
        $box = 0.00018;
        $candidates = Property::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereBetween('latitude',  [$lat - $box, $lat + $box])
            ->whereBetween('longitude', [$lng - $box, $lng + $box])
            ->orderByDesc('updated_at')
            ->get();

        // AT-URGENT — this is the SAME class of bug the TP-based resolver's
        // numbersConflict() veto exists to stop (e.g. "745 Beatty Drive" sitting
        // within the GPS box of the already-listed "8 Beatty Drive"), just on the
        // second, ungated fallback path that queries `properties` directly. A
        // 20m box on a coastal street can easily span several house numbers, so
        // this needs the same street-number discriminator before returning a hit.
        $factNumber = $facts['street_number'] ?? null;
        if ($factNumber === null || $factNumber === '') {
            return $candidates->first();
        }
        foreach ($candidates as $candidate) {
            $candNumber = $candidate->street_number ? trim((string) $candidate->street_number) : null;
            if (!$candNumber && $candidate->street_name) {
                if (preg_match('/^(\d+)\b/', strtolower(trim($candidate->street_name)), $m)) {
                    $candNumber = $m[1];
                }
            }
            if ($candNumber === null || $candNumber === '' || $candNumber === (string) $factNumber) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * True when a coordinate cannot disambiguate one property from another —
     * an obvious default (0,0), or a point that SHARED_COORD_THRESHOLD+ tracked
     * properties OR active listings report identically. Exact 7-dp equality: a
     * real per-property GPS is unique to sub-metre precision, so a coordinate
     * that many rows share is a placeholder (geocode default / suburb-centroid)
     * or a whole-building point — either way, proximity on it collapses
     * DISTINCT addressless listings onto one property. Two signals:
     *   - many tracked_properties at the point (e.g. the 391-way Ramsgate
     *     centroid on property #2427 "6 Kerk St"); and
     *   - many active listings at the point (e.g. 10 addressless listings on the
     *     single tracked property at Marlin Flats, promoted to the #4140 rental) —
     *     the tracked count alone is 1 there, so the listing count catches it.
     */
    private function isNonDisambiguatingCoordinate(float $lat, float $lng, int $agencyId): bool
    {
        if ($lat === 0.0 || $lng === 0.0) {
            return true;
        }

        $sharedTracked = DB::table('tracked_properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('latitude', $lat)
            ->where('longitude', $lng)
            ->limit(self::SHARED_COORD_THRESHOLD)
            ->count();
        if ($sharedTracked >= self::SHARED_COORD_THRESHOLD) {
            return true;
        }

        $sharedListings = DB::table('prospecting_listings as pl')
            ->join('tracked_properties as tp', 'tp.id', '=', 'pl.tracked_property_id')
            ->where('pl.agency_id', $agencyId)
            ->where('pl.is_active', true)
            ->whereNull('pl.deleted_at')
            ->where('tp.latitude', $lat)
            ->where('tp.longitude', $lng)
            ->limit(self::SHARED_COORD_THRESHOLD)
            ->count();

        return $sharedListings >= self::SHARED_COORD_THRESHOLD;
    }

    private function resolveAgentName(?int $agentId): ?string
    {
        if (!$agentId) return null;
        // User has the same SoftDeletes + AgencyScope + BranchScope bag as
        // Property. Stripping only AgencyScope means: deleted users return
        // null (correct), cross-branch agents return null when the requestor
        // is branch-isolated (also correct — branch privacy). The UI handles
        // null by showing "another agent" instead of the actual name.
        return \App\Models\User::withoutGlobalScope(AgencyScope::class)
            ->where('id', $agentId)
            ->value('name');
    }

    /**
     * Pick the best-available sale date from the linked deal (if any) or the
     * property's own last_sold_date. Returns ISO-8601 string or null.
     */
    private function resolveSaleDate(Property $property): ?string
    {
        $deal = \DB::table('deals')
            ->where('property_id', $property->id)
            ->whereNull('deleted_at')
            ->orderByDesc('registration_date')
            ->first(['registration_date', 'sale_date', 'deal_date']);
        $date = $deal?->registration_date ?? $deal?->sale_date ?? $deal?->deal_date ?? null;
        if ($date) return (string) $date;

        // Fallback to a property attribute if the column exists.
        try {
            return $property->last_sold_date ? CarbonImmutable::parse($property->last_sold_date)->toDateString() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
