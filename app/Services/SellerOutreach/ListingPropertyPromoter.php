<?php

namespace App\Services\SellerOutreach;

use App\Models\Property;
use App\Models\ProspectingListing;
use App\Services\Prospecting\MicPropertyReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The listing route: one prospecting listing becomes (or finds) one CoreX property, in the agency's "prospecting" state.
 *
 * Moved out of EntryPointController unchanged (road-to-sold.md 22.10) so the seller-outreach entry, the MIC slide-over and the Road to
 * Sold pitch path call ONE implementation. Behaviour is exactly what the controller had: reuse by source ref, then by a real address, then
 * by the canonical tracked-property identity; otherwise create. An empty or placeholder address never matches an existing property.
 */
class ListingPropertyPromoter
{
    /**
     * Promote a prospecting_listing to a real Property row.
     *
     * Idempotency: if a Property already exists for this agency with the
     * same normalised address (street_number+street_name OR the free-text
     * `address` column) + suburb, reuse it.
     *
     * Traceability: external_id is set to `prospecting:{listing.id}` so the
     * promoted Property can be traced back to its source listing. The
     * listing's `matched_property_id` is set in the caller's transaction.
     */
    public function promote(int $agencyId, $listing, $actor, ?string $overrideAddress = null, ?array $structuredAddress = null): Property
    {
        // #3 Address-first: an address-less import (pull-all) reaches here with a
        // blank listing address. The capture step collects one and passes it as
        // $overrideAddress so a real street address always lands on the promoted
        // Property (never the "Prospecting listing N" placeholder). A non-empty
        // listing address always wins — the override only fills the gap.
        $address = trim((string) ($listing->address ?? ''));
        if ($address === '' && $overrideAddress !== null) {
            $address = trim($overrideAddress);
        }
        // The legacy "Address not available" placeholder is NOT an address — fold
        // it to empty so it can never dedupe against, nor be stored on, a property.
        if (strtolower($address) === 'address not available') {
            $address = '';
        }
        $suburb  = trim((string) ($listing->suburb ?? ''));
        $normalised = trim(strtolower((string) ($listing->normalized_address ?? $address)));

        // Ramsgate bug fix — disambiguate by the listing's OWN source-ref FIRST.
        // 'prospecting:{id}' is unique per listing, so re-promoting the same listing
        // reuses ITS property and two DIFFERENT listings never collide.
        $existing = Property::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where('external_id', 'prospecting:' . $listing->id)
            ->first();

        // Only when there is NO source-ref match AND we hold a REAL address may we
        // dedupe by address. An empty address must NEVER match — that empty-address
        // collapse is what landed a no-address Pitch Now on the wrong property.
        // Addressless listings therefore always create their OWN property (keyed
        // by external_id), never collapsing onto an existing addressless one.
        if (!$existing && $address !== '') {
            $existing = Property::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($address, $normalised) {
                    $q->where('address', $address)
                      ->orWhereRaw('LOWER(TRIM(address)) = ?', [$normalised]);
                })
                ->when($suburb !== '', fn ($q) => $q->where(function ($qq) use ($suburb) {
                    $qq->where('suburb', $suburb)->orWhereNull('suburb');
                }))
                ->first();
        }
        if ($existing) {
            // Heal a property promoted before this fix (or any reuse) that
            // has no structured street — only when BOTH are empty, so a
            // manually-edited address is never overwritten.
            if (empty($existing->street_number) && empty($existing->street_name)) {
                [$sNo, $sName] = $this->parseStreet($address, $suburb);
                $patch = [];
                if ($sNo !== null && $sNo !== '') {
                    $patch['street_number'] = $sNo;
                }
                if ($sName !== null && $sName !== '') {
                    $patch['street_name'] = $sName;
                }
                if ($patch !== []) {
                    $existing->forceFill($patch)->save();
                }
            }
            return $existing;
        }

        // CoreX rejects system_owner / super_admin as a Property agent. Fall
        // back to the agency's first admin/branch_manager/agent if the actor
        // is an owner-level account. The promoted property is a temporary
        // record anyway — the assigned agent can reassign later.
        $propertyAgentId = $this->resolvePromotionAgentId($agencyId, $actor);
        $propertyBranchId = $actor->branch_id
            ?? DB::table('users')->where('id', $propertyAgentId)->value('branch_id');

        // Parse the free-text address into structured fields so the pitched
        // property's Internal Address modal + the outreach address line
        // (built from street_number+street_name+suburb) are populated.
        [$streetNumber, $streetName] = $this->parseStreet($address, $suburb);
        $district = trim((string) ($listing->district ?? ''));

        // When the address came from the shared "Property Address" modal (structured
        // capture on the pitch flow), prefer its EXACT parts over parsing the composed
        // string, and carry the richer columns (complex/unit/city/province + P24 ids)
        // onto the new property so it is pre-filled exactly like a contact-started one.
        $extra = [];
        if (is_array($structuredAddress)) {
            if (($structuredAddress['street_number'] ?? '') !== '') { $streetNumber = $structuredAddress['street_number']; }
            if (($structuredAddress['street_name'] ?? '') !== '')   { $streetName   = $structuredAddress['street_name']; }
            if (($structuredAddress['suburb'] ?? '') !== '')        { $suburb       = $structuredAddress['suburb']; }
            foreach ([
                'complex_name'       => 'complex_name',
                'unit_number'        => 'unit_number',
                'floor_number'       => 'floor_number',
                'unit_section_block' => 'unit_section_block',
                'city'               => 'city',
                'province'           => 'province',
            ] as $src => $col) {
                if (($structuredAddress[$src] ?? '') !== '') { $extra[$col] = $structuredAddress[$src]; }
            }
            foreach ([
                'pitch_addr_province_id' => 'p24_province_id',
                'pitch_addr_city_id'     => 'p24_city_id',
                'pitch_addr_suburb_id'   => 'p24_suburb_id',
            ] as $src => $col) {
                if (!empty($structuredAddress[$src])) { $extra[$col] = (int) $structuredAddress[$src]; }
            }
        }

        // MIC ↔ property-pillar reconciliation (Johan 2026-08-14). The external_id + raw
        // address-string dedup above only catches EXACT string matches. Before minting a NEW
        // property, resolve the FINAL address against the canonical TrackedProperty identity spine
        // (the SAME resolver deeds promote uses: source-ref → GPS ~5m → erf+suburb → normalised
        // structured address → token) so an address that already exists — e.g. a deeds-promoted
        // property under a slightly different string — reconciles to it instead of duplicating.
        // Read-only (findExistingMatch creates nothing); failure-isolated (falls through to create).
        try {
            $reconLat = null;
            $reconLng = null;
            if (!empty($listing->tracked_property_id)) {
                $tpGps = DB::table('tracked_properties')
                    ->where('id', (int) $listing->tracked_property_id)
                    ->where('agency_id', $agencyId)
                    ->first(['latitude', 'longitude']);
                if ($tpGps) {
                    $reconLat = $tpGps->latitude !== null ? (float) $tpGps->latitude : null;
                    $reconLng = $tpGps->longitude !== null ? (float) $tpGps->longitude : null;
                }
            }
            $reconFacts = array_filter([
                'address'       => $address ?: ($listing->address ?? null),
                'street_number' => $streetNumber ?: ProspectingListing::parseStreetNumber($listing->address ?? null),
                'street_name'   => $streetName ?: null,
                'suburb'        => $suburb ?: ($listing->suburb ?? null),
                'latitude'      => $reconLat,
                'longitude'     => $reconLng,
            ], static fn ($v) => $v !== null && $v !== '');

            // Only attempt when we hold a real locating key (a real address or GPS) — never let an
            // empty/placeholder address collapse onto an existing property (the Ramsgate landmine).
            if (($address !== '') || ($reconLat !== null && $reconLng !== null)) {
                $canonical = app(MicPropertyReconciliationService::class)
                    ->resolveExistingProperty($agencyId, $reconFacts);
                if ($canonical) {
                    return $canonical;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MIC property-pillar reconciliation failed (falling through to create)', [
                'listing_id' => $listing->id ?? null,
                'error'      => $e->getMessage(),
            ]);
        }

        // properties.beds/baths/garages/price/suburb/property_type are NOT
        // NULL — fall back to the schema defaults (0 / empty / 'house') when
        // the prospecting row doesn't carry the value. 'status' is explicit,
        // not defaulted (2026-08-21, Johan, .ai/specs/2026-08-20-property-
        // status-prospecting.md): this path promotes a MIC ProspectingListing
        // straight to Property, bypassing TrackedProperty entirely -- it is
        // unambiguously MIC ingest, so it always lands as Prospecting, never
        // the schema's 'draft' default (that default is for the agent-create
        // wizard, not this path).
        return Property::create(array_merge([
            'agency_id'     => $agencyId,
            'branch_id'     => $propertyBranchId,
            'agent_id'      => $propertyAgentId,
            'status'        => Property::STATUS_PROSPECTING,
            'external_id'   => 'prospecting:' . $listing->id,
            'title'         => $address !== '' ? $address : 'Prospecting listing ' . $listing->id,
            'address'       => $address !== '' ? $address : null,
            'street_number' => $streetNumber,
            'street_name'   => $streetName,
            'suburb'        => $suburb !== '' ? $suburb : '',
            'district'      => $district !== '' ? $district : null,
            'price'         => $listing->price ?? 0,
            'beds'          => $listing->bedrooms ?? 0,
            'baths'         => $listing->bathrooms ?? 0,
            'garages'       => $listing->garages ?? 0,
            'property_type' => $listing->property_type ?? 'house',
            // No listing_type on prospecting_listings — default to 'sale'.
            'listing_type'  => 'sale',
        ], $extra));
    }

    /**
     * Compose a one-line display address from the structured "Property Address" modal
     * fields (unit / section / complex / street / suburb / city / province), mirroring
     * the contact component's summary. Used as the promoted property's address string.
     */
    public function composeAddress(array $a): string
    {
        $parts = [];
        $unit    = trim((string) ($a['unit_number'] ?? ''));
        $section = trim((string) ($a['unit_section_block'] ?? ''));
        $complex = trim((string) ($a['complex_name'] ?? ''));
        $sNo     = trim((string) ($a['street_number'] ?? ''));
        $sName   = trim((string) ($a['street_name'] ?? ''));
        $suburb  = trim((string) ($a['suburb'] ?? ''));
        $city    = trim((string) ($a['city'] ?? ''));
        $prov    = trim((string) ($a['province'] ?? ''));

        if ($unit !== '')    { $parts[] = 'Unit ' . $unit; }
        if ($section !== '') { $parts[] = $section; }
        if ($complex !== '') { $parts[] = $complex; }
        if ($sNo !== '' && $sName !== '') { $parts[] = trim($sNo . ' ' . $sName); }
        elseif ($sName !== '')            { $parts[] = $sName; }
        if ($suburb !== '') { $parts[] = $suburb; }
        if ($city !== '' && strtolower($city) !== strtolower($suburb)) { $parts[] = $city; }
        if ($prov !== '') { $parts[] = $prov; }

        return implode(', ', array_filter($parts));
    }

    /**
     * Prospecting listings carry only a free-text `address` string (plus a
     * separate suburb/district) — NO structured street fields. Parse the
     * street line (the segment before the first comma) into
     * [street_number, street_name] so a pitched Property's Internal Address
     * and the outreach address line are populated instead of "(no address)".
     * Returns [null, null] when there is no real street (e.g. the address is
     * just the suburb).
     */
    public function parseStreet(string $address, string $suburb): array
    {
        $address = trim($address);
        if ($address === '') {
            return [null, null];
        }
        $streetLine = trim((string) (explode(',', $address)[0] ?? ''));
        $suburb = trim($suburb);
        if ($streetLine === ''
            || ($suburb !== '' && mb_strtolower($streetLine) === mb_strtolower($suburb))) {
            return [null, null];
        }
        // Leading street number: "173", "12A", "1/3", "12-14".
        if (preg_match('/^\s*(\d+[A-Za-z]?(?:\s*[\/-]\s*\d+[A-Za-z]?)?)\s+(.+)$/u', $streetLine, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        // No leading number — the whole line is the street name.
        return [null, $streetLine];
    }

    public function resolvePromotionAgentId(int $agencyId, $actor): int
    {
        $role = $actor->role ?? null;
        if ($role && !in_array($role, ['super_admin', 'system'], true)) {
            return (int) $actor->id;
        }
        $fallback = DB::table('users')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereIn('role', ['admin', 'branch_manager', 'agent'])
            ->orderByRaw("FIELD(role, 'admin', 'branch_manager', 'agent')")
            ->orderBy('id')
            ->value('id');
        abort_if(!$fallback, 422, 'No agent available in this agency to assign the promoted property.');
        return (int) $fallback;
    }
}
