<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
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

        $tp = $this->matcher->findExistingMatch($agencyId, $factsForLookup);
        if ($tp === null) {
            return ['status' => 'available'];
        }

        // No promoted Property → HFC has prospect intel but no listing.
        // Treat as available — agent can continue prospecting.
        if (!$tp->promoted_to_property_id) {
            return ['status' => 'available'];
        }

        /** @var ?Property $property */
        $property = Property::withoutGlobalScopes()
            ->where('id', $tp->promoted_to_property_id)
            ->where('agency_id', $agencyId)
            ->first();
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

    private function resolveAgentName(?int $agentId): ?string
    {
        if (!$agentId) return null;
        return \App\Models\User::withoutGlobalScopes()
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
