<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Contact;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Scopes\ContactScope;
use Illuminate\Support\Facades\DB;

/**
 * MIC ↔ Deeds ↔ Contact loop — Part A (Johan 2026-08-14).
 *
 * The MIC "compose pitch / capture seller contact" screen had no link to the deed the agent
 * just scraped. A deed capture (CMA/deeds-office) lands as a `tracked_properties` row
 * (capture_kind='deeds_capture') whose registered owner(s) are stored on
 * `tracked_property_owners` (name + SA-ID `id_number`), each already resolved to a `Contact`
 * (deduped on id_number, phone left empty) — see .ai/specs/deeds-capture.md §3.
 *
 * This service RESOLVES the deed-capture owner(s) for the property being pitched, so the
 * compose screen can surface "the owner you scraped" inline (name + ID) with a "Use from
 * deeds" prefill — instead of the agent re-typing what CoreX already ingested.
 *
 * READ-ONLY across the deeds domain (cc5) and the contact pillar (cc3): it queries their
 * tables/models, never writes them. Matching reuses the SAME TrackedProperty identity keys the
 * reconciliation spine uses (erf+suburb, street+suburb, GPS ~5m) so a deed captured under a
 * slightly different address string is still found, and the duplicate-TP reality (several TP
 * rows per asset — see MicPropertyReconciliationService) is handled by matching on identity
 * rather than a single row id.
 */
class DeedsCaptureLinkService
{
    /** ~5 metres in degrees (latitude); the deeds/portal GPS-proximity tolerance. */
    private const GPS_BOX_DEG = 0.00005;

    /**
     * Resolve deed-capture owner(s) for a prospecting listing (the MIC compose entry point).
     *
     * @return array{tracked_property_id: int|null, address: string|null, owners: array<int, array{name: string, first_name: string, last_name: string, id_number: string|null, id_type: string|null, is_primary: bool, contact_id: int|null}>}
     */
    public function ownersForListing(int $agencyId, object $listing): array
    {
        // Remembered manual link (teaches the matcher): a deed the agent previously linked to this
        // listing via the "Link a deed" modal is a definitive confirmed match on every later visit.
        if (! empty($listing->linked_deed_tracked_property_id)) {
            $linked = TrackedProperty::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereKey((int) $listing->linked_deed_tracked_property_id)
                ->first();
            if ($linked && $this->trackedPropertyHasOwners((int) $linked->id)) {
                return $this->owners($agencyId, $linked) + ['candidates' => []];
            }
        }

        $seedTp = null;
        if (! empty($listing->tracked_property_id)) {
            $seedTp = TrackedProperty::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereKey((int) $listing->tracked_property_id)
                ->first();
        }

        $facts = [
            'address'             => $listing->address ?? null,
            'suburb'              => $listing->suburb ?? null,
            'street_number'       => ProspectingListing::parseStreetNumber($listing->address ?? null),
            'matched_property_id' => $listing->matched_property_id ?? null,
        ];

        return $this->resolve($agencyId, $seedTp, $facts);
    }

    /**
     * Resolve deed-capture owner(s) for a TrackedProperty directly (the map T-pin compose
     * entry point, where a TrackedProperty is already in hand).
     *
     * @return array{tracked_property_id: int|null, address: string|null, owners: array<int, array<string, mixed>>}
     */
    public function ownersForTrackedProperty(int $agencyId, TrackedProperty $trackedProperty): array
    {
        return $this->resolve($agencyId, $trackedProperty, [
            'address'             => $trackedProperty->address,
            'suburb'              => $trackedProperty->suburb,
            'street_number'       => $trackedProperty->street_number,
            'matched_property_id' => $trackedProperty->promoted_to_property_id ?? null,
        ]);
    }

    /**
     * Core resolution: from a seed TrackedProperty (and/or raw address facts) find the
     * deeds-capture TrackedProperty that holds registered owners for this asset, and return
     * its owner rows enriched with the linked contact's first/last name.
     *
     * Returns two tiers:
     *  - `owners`     — a CONFIRMED match (the seed TP itself carries owners, or a sibling shares
     *                   a strong identity key: erf+suburb, street+suburb, or GPS ~5m). Safe to
     *                   present as "the deed for this property".
     *  - `candidates` — POSSIBLE matches (same street number + shared suburb token) surfaced only
     *                   when there is no confirmed match. These exist because the deeds-office
     *                   address routinely diverges from the portal/marketing address (Johan's real
     *                   case: portal "516 Bream Crescent, Ramsgate" vs deed "516 Bidstone, The Nest,
     *                   Ramsgate Beach, erf 516" — no shared erf and a corrupt deed GPS). Too weak to
     *                   AUTO-link (a bare street-number+suburb match would attach the wrong owner's
     *                   SA-ID to a property), so they are shown as "verify this is the same property"
     *                   and only used when the agent actively clicks — never silently linked.
     *
     * @param  array{address: ?string, suburb: ?string, street_number: ?string, matched_property_id?: int|null}  $facts
     * @return array{tracked_property_id: int|null, address: string|null, owners: array<int, array<string, mixed>>, candidates: array<int, array<string, mixed>>}
     */
    private function resolve(int $agencyId, ?TrackedProperty $seedTp, array $facts): array
    {
        // 1) The seed TP itself may carry owners (the deed enriched the SAME TP the listing
        //    is linked to — the common case when the identity spine matched them together).
        if ($seedTp && $this->trackedPropertyHasOwners((int) $seedTp->id)) {
            return $this->owners($agencyId, $seedTp) + ['candidates' => []];
        }

        // 2) PROPERTY-PILLAR link (Johan 2026-08-14) — if this asset resolves to a canonical
        //    Property (explicit listing link, a promoted seed TP, or via the reconciliation spine),
        //    a deed promoted to that SAME Property is a definitive match. This matches through the
        //    property pillar, NOT any address string, so the P24-vs-deeds-office address divergence
        //    is bridged by identity rather than text.
        $propertyId = $this->resolveCanonicalPropertyId($agencyId, $seedTp, $facts);
        if ($propertyId) {
            $deedTp = $this->deedTrackedPropertyForProperty($agencyId, $propertyId);
            if ($deedTp) {
                return $this->owners($agencyId, $deedTp) + ['candidates' => []];
            }
        }

        // 3) Otherwise find a deeds-capture sibling — a TrackedProperty in the same agency that
        //    HAS owners and shares this asset's identity (erf+suburb, street+suburb, or GPS ~5m).
        //    Prefer an actual deeds_capture, then most-recently-updated.
        $sibling = $this->findOwnerBearingSibling($agencyId, $seedTp, $facts);
        if ($sibling) {
            return $this->owners($agencyId, $sibling) + ['candidates' => []];
        }

        // 4) No confirmed match — surface possible matches for the agent to verify (never link).
        //    This is the tier that fires for Johan's real case (portal "516 Bream Crescent,
        //    Ramsgate" vs deed "516 Bidstone, The Nest, Ramsgate Beach"): neither is promoted, they
        //    share no erf/GPS, so the only signal is street number + suburb — shown for the agent
        //    to confirm, never silently linked.
        return [
            'tracked_property_id' => null,
            'address'             => null,
            'owners'              => [],
            'candidates'          => $this->findCandidates($agencyId, $seedTp, $facts),
        ];
    }

    /**
     * Resolve the canonical Property id for this asset: an explicit listing/TP link, a promoted
     * seed TP, or the reconciliation spine (the SAME resolver deeds promote uses). Read-only;
     * returns null when the asset has not resolved to a property yet (e.g. Johan's un-promoted case).
     *
     * @param  array{address: ?string, suburb: ?string, street_number: ?string, matched_property_id?: int|null}  $facts
     */
    private function resolveCanonicalPropertyId(int $agencyId, ?TrackedProperty $seedTp, array $facts): ?int
    {
        if (! empty($facts['matched_property_id'])) {
            return (int) $facts['matched_property_id'];
        }
        if ($seedTp && ! empty($seedTp->promoted_to_property_id)) {
            return (int) $seedTp->promoted_to_property_id;
        }

        try {
            $p = app(MicPropertyReconciliationService::class)->resolveExistingProperty($agencyId, array_filter([
                'address'       => $facts['address'] ?? null,
                'suburb'        => $facts['suburb'] ?? ($seedTp->suburb ?? null),
                'street_number' => $facts['street_number'] ?? ($seedTp->street_number ?? null),
                'street_name'   => $seedTp->street_name ?? null,
                'latitude'      => $seedTp->latitude ?? null,
                'longitude'     => $seedTp->longitude ?? null,
            ], static fn ($v) => $v !== null && $v !== ''));

            return $p?->id;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** An owner-bearing deeds TrackedProperty promoted to the given canonical property, if any. */
    private function deedTrackedPropertyForProperty(int $agencyId, int $propertyId): ?TrackedProperty
    {
        return TrackedProperty::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('promoted_to_property_id', $propertyId)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tracked_property_owners as tpo')
                    ->whereColumn('tpo.tracked_property_id', 'tracked_properties.id');
            })
            ->orderByRaw("CASE WHEN capture_kind = 'deeds_capture' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * Possible (agent-verified) deed matches: owner-bearing TrackedProperties in the same agency
     * sharing this asset's street number AND the leading token of its suburb — the strongest signal
     * available when the deeds-office and portal addresses diverge. Excludes the seed TP. Ordered
     * deeds-capture first, most-recent next; capped so the screen stays a short "is this it?" list.
     *
     * @param  array{address: ?string, suburb: ?string, street_number: ?string}  $facts
     * @return array<int, array{tracked_property_id: int, address: string|null, owners: array<int, array<string, mixed>>}>
     */
    private function findCandidates(int $agencyId, ?TrackedProperty $seedTp, array $facts): array
    {
        $streetNumber = $seedTp->street_number ?? ($facts['street_number'] ?? null);
        $suburbNorm   = $seedTp->suburb_normalised ?? (
            ! empty($facts['suburb']) ? TrackedProperty::normaliseSuburb((string) $facts['suburb']) : null
        );
        $streetNumber = $streetNumber !== null ? trim((string) $streetNumber) : '';
        $suburbNorm   = $suburbNorm !== null ? trim((string) $suburbNorm) : '';
        if ($streetNumber === '' || $suburbNorm === '') {
            return [];
        }
        $token = explode(' ', $suburbNorm)[0] ?? '';
        if ($token === '') {
            return [];
        }

        $tps = TrackedProperty::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->when($seedTp !== null, fn ($q) => $q->where('id', '!=', $seedTp->id))
            ->where('street_number', $streetNumber)
            ->whereRaw("SUBSTRING_INDEX(suburb_normalised, ' ', 1) = ?", [$token])
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tracked_property_owners as tpo')
                    ->whereColumn('tpo.tracked_property_id', 'tracked_properties.id');
            })
            ->orderByRaw("CASE WHEN capture_kind = 'deeds_capture' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        return $tps->map(fn (TrackedProperty $tp) => $this->owners($agencyId, $tp))->all();
    }

    private function trackedPropertyHasOwners(int $trackedPropertyId): bool
    {
        return DB::table('tracked_property_owners')
            ->where('tracked_property_id', $trackedPropertyId)
            ->exists();
    }

    /**
     * Find a TrackedProperty (with owners) matching the asset identity. Uses the seed TP's own
     * normalised keys when available (authoritative), else derives them from the raw facts.
     */
    private function findOwnerBearingSibling(int $agencyId, ?TrackedProperty $seedTp, array $facts): ?TrackedProperty
    {
        $erf              = $seedTp->erf_number ?? null;
        $suburbNormalised = $seedTp->suburb_normalised ?? (
            ! empty($facts['suburb']) ? TrackedProperty::normaliseSuburb((string) $facts['suburb']) : null
        );
        $streetNumber = $seedTp->street_number ?? ($facts['street_number'] ?? null);
        $streetName   = $seedTp->street_name ?? null;

        // GPS: prefer the deeds-office authoritative pin, then the portal pin.
        $lat = $seedTp->cma_gps_lat ?? $seedTp->latitude ?? null;
        $lng = $seedTp->cma_gps_lng ?? $seedTp->longitude ?? null;

        $hasErf    = ! empty($erf) && ! empty($suburbNormalised);
        $hasStreet = ! empty($streetNumber) && ! empty($streetName) && ! empty($suburbNormalised);
        $hasGps    = $lat !== null && $lng !== null;
        if (! $hasErf && ! $hasStreet && ! $hasGps) {
            return null;
        }

        return TrackedProperty::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->when($seedTp !== null, fn ($q) => $q->where('id', '!=', $seedTp->id))
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tracked_property_owners as tpo')
                    ->whereColumn('tpo.tracked_property_id', 'tracked_properties.id');
            })
            ->where(function ($q) use ($hasErf, $hasStreet, $hasGps, $erf, $suburbNormalised, $streetNumber, $streetName, $lat, $lng) {
                if ($hasErf) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('erf_number', $erf)
                        ->where('suburb_normalised', $suburbNormalised));
                }
                if ($hasStreet) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('street_number', $streetNumber)
                        ->where('street_name', $streetName)
                        ->where('suburb_normalised', $suburbNormalised));
                }
                if ($hasGps) {
                    $q->orWhere(fn ($qq) => $qq
                        ->whereBetween('cma_gps_lat', [$lat - self::GPS_BOX_DEG, $lat + self::GPS_BOX_DEG])
                        ->whereBetween('cma_gps_lng', [$lng - self::GPS_BOX_DEG, $lng + self::GPS_BOX_DEG]))
                      ->orWhere(fn ($qq) => $qq
                        ->whereBetween('latitude', [$lat - self::GPS_BOX_DEG, $lat + self::GPS_BOX_DEG])
                        ->whereBetween('longitude', [$lng - self::GPS_BOX_DEG, $lng + self::GPS_BOX_DEG]));
                }
            })
            ->orderByRaw("CASE WHEN capture_kind = 'deeds_capture' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * Build the owner payload for a resolved TrackedProperty, enriching each owner with its
     * linked contact's first/last name (falling back to splitting the deed's full-name string).
     *
     * @return array{tracked_property_id: int, address: string|null, owners: array<int, array<string, mixed>>}
     */
    private function owners(int $agencyId, TrackedProperty $tp): array
    {
        $rows = DB::table('tracked_property_owners')
            ->where('tracked_property_id', $tp->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        return [
            'tracked_property_id' => (int) $tp->id,
            'address'             => $tp->address ?: ($tp->displayAddress() ?? null),
            'owners'              => $this->enrichOwnerRows($agencyId, $rows),
        ];
    }

    /**
     * Enrich raw tracked_property_owners rows with each owner's linked-contact first/last name
     * (falling back to splitting the deed's full-name string). Loads all needed contacts in ONE
     * query — safe to pass rows spanning multiple TrackedProperties.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function enrichOwnerRows(int $agencyId, $rows): array
    {
        $contactIds = $rows->pluck('contact_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();
        $contacts = [];
        $deadEnds = [];
        if ($contactIds !== []) {
            $contacts = Contact::withoutGlobalScope(ContactScope::class)
                ->where('agency_id', $agencyId)
                ->whereIn('id', $contactIds)
                ->get(['id', 'first_name', 'last_name', 'id_number', 'id_type', 'contact_kind', 'entity_name', 'entity_reg_no'])
                ->keyBy('id');

            // Part B — mark owners already recorded as a dead end so the compose panels warn a
            // future agent immediately (nothing contactable here).
            $deadEnds = \App\Models\ContactDeadEndFlag::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereIn('contact_id', $contactIds)
                ->get(['contact_id', 'reason'])
                ->keyBy('contact_id');
        }

        $owners = [];
        foreach ($rows as $row) {
            $contact = $row->contact_id ? ($contacts[(int) $row->contact_id] ?? null) : null;

            if ($contact) {
                $first = (string) ($contact->first_name ?? '');
                $last  = (string) ($contact->last_name ?? '');
            } else {
                [$first, $last] = $this->splitName((string) $row->name);
            }

            $contactId = $contact?->id ?? ($row->contact_id ? (int) $row->contact_id : null);
            $deadEnd = ($contactId !== null && isset($deadEnds[$contactId])) ? [
                'reason' => $deadEnds[$contactId]->reason,
                'label'  => \App\Models\ContactDeadEndFlag::reasonLabel($deadEnds[$contactId]->reason),
            ] : null;

            // Entity foundation (cc6) — a company/CC/trust owner is captured as an entity Contact
            // (contact_kind='entity', entity_name, entity_reg_no). Surface that here so the orange
            // block can label it "Reg:" and the seller-link keys on the entity, not a 13-digit SA ID.
            // Fall back to the tracked_property_owners.id_type flag for a not-yet-resolved capture.
            $isEntity = ($contact && $contact->contact_kind === Contact::TYPE_ENTITY)
                || (string) ($row->id_type ?? '') === 'company_reg';
            $entityRegNo = $contact && $contact->entity_reg_no !== null && $contact->entity_reg_no !== ''
                ? (string) $contact->entity_reg_no
                : ($isEntity && $row->id_number !== null && $row->id_number !== '' ? (string) $row->id_number : null);

            $owners[] = [
                'tracked_property_id' => (int) $row->tracked_property_id,
                'name'                => (string) $row->name,
                'first_name'          => trim($first),
                'last_name'           => trim($last),
                'is_entity'           => $isEntity,
                'display_name'        => $isEntity
                    ? (string) (($contact->entity_name ?? null) ?: $row->name)
                    : (trim($first . ' ' . $last) ?: (string) $row->name),
                'entity_reg_no'       => $entityRegNo,
                'id_number'           => $row->id_number !== null && $row->id_number !== '' ? (string) $row->id_number : null,
                'id_type'             => $row->id_type ?? ($contact->id_type ?? null),
                'is_primary'          => (bool) $row->is_primary,
                'contact_id'          => $contactId,
                'dead_end'            => $deadEnd,
            ];
        }

        return $owners;
    }

    /**
     * Clean deeds list for the manual "Link a deed" modal — every owner-bearing deeds capture in
     * the agency, with enough to identify it at a glance (scheme/address, erf, owner name(s), sold
     * price/date) plus a lower-cased `search` blob for client-side filtering. Batched (no N+1);
     * deeds captures first, most recent next. Read-only.
     *
     * @return array<int, array{tracked_property_id:int, address:string, erf:?string, scheme:?string, suburb:?string, sold_price:?string, sold_date:?string, owner_names:string, owners:array<int,array<string,mixed>>, search:string}>
     */
    public function availableDeeds(int $agencyId, int $limit = 500): array
    {
        $tps = TrackedProperty::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tracked_property_owners as tpo')
                    ->whereColumn('tpo.tracked_property_id', 'tracked_properties.id');
            })
            ->orderByRaw("CASE WHEN capture_kind = 'deeds_capture' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'erf_number', 'scheme_name', 'complex_name', 'street_number', 'street_name',
                'suburb', 'town', 'last_known_sold_price', 'last_known_sold_date']);

        if ($tps->isEmpty()) {
            return [];
        }

        $ownerRows = DB::table('tracked_property_owners')
            ->whereIn('tracked_property_id', $tps->pluck('id')->all())
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
        $enriched = $this->enrichOwnerRows($agencyId, $ownerRows);

        $ownersByTp = [];
        foreach ($enriched as $o) {
            $ownersByTp[$o['tracked_property_id']][] = $o;
        }

        $out = [];
        foreach ($tps as $tp) {
            $owners = $ownersByTp[(int) $tp->id] ?? [];

            $streetLine = trim(implode(' ', array_filter([$tp->street_number, $tp->street_name])));
            $scheme     = $tp->scheme_name ?: $tp->complex_name;
            $address    = trim(implode(', ', array_filter([$streetLine, $scheme, $tp->suburb]))) ?: (string) ($tp->town ?? '');

            $ownerNames = implode('; ', array_map(
                fn ($o) => trim($o['first_name'] . ' ' . $o['last_name']) ?: $o['name'],
                $owners
            ));

            $search = mb_strtolower(implode(' ', array_filter([
                $address, $scheme, $tp->suburb, $tp->erf_number, $ownerNames,
                implode(' ', array_map(fn ($o) => (string) $o['id_number'], $owners)),
            ])));

            $out[] = [
                'tracked_property_id' => (int) $tp->id,
                'address'             => $address,
                'erf'                 => $tp->erf_number ? (string) $tp->erf_number : null,
                'scheme'              => $scheme ?: null,
                'suburb'              => $tp->suburb ? (string) $tp->suburb : null,
                'sold_price'          => $tp->last_known_sold_price !== null ? (string) $tp->last_known_sold_price : null,
                'sold_date'           => $tp->last_known_sold_date ? (string) $tp->last_known_sold_date : null,
                'owner_names'         => $ownerNames,
                'owners'              => $owners,
                'search'              => $search,
            ];
        }

        return $out;
    }

    /**
     * Split a deed's full-name string into [first, last] — last whitespace token is the
     * surname, the remainder the first name(s). A single token is treated as a first name.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') {
            return ['', ''];
        }
        $parts = explode(' ', $name);
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }
        $last = array_pop($parts);

        return [implode(' ', $parts), $last];
    }
}
