<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Scopes\AgencyScope;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Carbon\CarbonImmutable;

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

    public function __construct(
        private readonly TrackedPropertyMatchOrCreateService $matcher = new TrackedPropertyMatchOrCreateService(),
    ) {}

    /**
     * @param array{address: ?string, latitude: ?float, longitude: ?float, suburb: ?string} $facts
     * @return array{status: string, property_id?: int, agent_name?: ?string, days_in_state?: int, state_label?: string, sale_date?: ?string, expired_at?: ?string}
     */
    public function resolve(array $facts, int $agencyId, int $currentUserId): array
    {
        $factsForLookup = array_filter([
            'address'   => $facts['address']   ?? null,
            'latitude'  => $facts['latitude']  ?? null,
            'longitude' => $facts['longitude'] ?? null,
            'suburb'    => $facts['suburb']    ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        if (empty($factsForLookup)) {
            return ['status' => 'available'];
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
        return Property::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereBetween('latitude',  [$lat - $box, $lat + $box])
            ->whereBetween('longitude', [$lng - $box, $lng + $box])
            ->orderByDesc('updated_at')
            ->first();
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
