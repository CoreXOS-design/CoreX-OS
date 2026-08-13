<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\ContactDuplicateService;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CMA / deeds capture ingest (phase 1). Mirrors the portal-capture ingest
 * (ProspectingApiController::import): Sanctum-authed, batch payload, never
 * hard-fails the whole batch on one bad row.
 *
 * A capture lands in tracked_properties via the shared match-or-create
 * plumbing (source 'deeds_capture'), gets the deeds-specific fields, and — only
 * when the deeds capture CREATES the tracked property — is tagged
 * capture_kind='deeds_capture' so it shows on the dedicated Deeds Capture screen
 * and is filtered OUT of MIC Opportunities. Deeds data that merely ENRICHES an
 * existing prospecting tracked property leaves capture_kind alone (that lead
 * stays in Opportunities, now enriched).
 *
 * The owner becomes a contact (name + owner ID, phone LEFT EMPTY) linked via
 * tracked_properties.owner_contact_id and deduped on the owner ID — the join key
 * the phase-2 Virtual Agent uses to fill the phone later.
 *
 * Payload contract: see .ai/specs/deeds-capture.md.
 */
final class DeedsCaptureController extends Controller
{
    public function store(
        Request $request,
        TrackedPropertyMatchOrCreateService $matcher,
        ContactDuplicateService $dupes
    ): JsonResponse {
        $validated = $request->validate([
            'source'                                => 'nullable|string|max:50',
            'captures'                              => 'required|array|min:1',
            'captures.*.source_ref'                 => 'required|string|max:200',
            'captures.*.property'                   => 'required|array',
            'captures.*.property.deeds_office'      => 'nullable|string|max:100',
            'captures.*.property.scheme_name'       => 'nullable|string|max:200',
            'captures.*.property.scheme_number'     => 'nullable|string|max:100',
            'captures.*.property.section_number'    => 'nullable|string|max:50',
            'captures.*.property.erf_number'        => 'nullable|string|max:100',
            'captures.*.property.address'           => 'nullable|string|max:255',
            'captures.*.property.street_number'     => 'nullable|string|max:50',
            'captures.*.property.street_name'       => 'nullable|string|max:200',
            'captures.*.property.unit_number'       => 'nullable|string|max:50',
            'captures.*.property.complex_name'      => 'nullable|string|max:200',
            'captures.*.property.suburb'            => 'nullable|string|max:100',
            'captures.*.property.municipality'      => 'nullable|string|max:100',
            'captures.*.property.province'          => 'nullable|string|max:100',
            'captures.*.property.latitude'          => 'nullable|numeric',
            'captures.*.property.longitude'         => 'nullable|numeric',
            'captures.*.property.section_extent_m2' => 'nullable|numeric',
            'captures.*.property.property_type'     => 'nullable|string|max:100',
            'captures.*.property.title_deed_number' => 'nullable|string|max:100',
            // Multi-owner (2026-08-12) — CMA lists more than one registered owner on
            // some properties (joined " ; " on the source page); a single owner
            // object can't hold two id_number's without blowing the 20-char column,
            // which is exactly the bug this replaced. owners[] validates each
            // owner's id_number against its own real limit.
            'captures.*.owners'                     => 'nullable|array',
            'captures.*.owners.*.name'               => 'nullable|string|max:255',
            'captures.*.owners.*.surname'            => 'nullable|string|max:150',
            'captures.*.owners.*.first_names'        => 'nullable|string|max:200',
            'captures.*.owners.*.id_number'          => 'nullable|string|max:20',
            'captures.*.owners.*.id_type'            => 'nullable|in:sa_id,company_reg',
            'captures.*.sale'                       => 'nullable|array',
            'captures.*.sale.sale_price'            => 'nullable|numeric',
            'captures.*.sale.sale_date'             => 'nullable|date',
            'captures.*.sale.registered_date'       => 'nullable|date',
            'captures.*.sale.bond_holder'           => 'nullable|string|max:150',
            'captures.*.sale.bond_amount'           => 'nullable|numeric',
            'captures.*.sale.sale_type'             => 'nullable|string|max:60',
        ]);

        $user = $request->user();
        $agencyId = $user?->effectiveAgencyId() ?? $user?->agency_id;
        abort_if($agencyId === null, 403, 'No agency context for this token.');

        $results = [];
        foreach ($validated['captures'] as $capture) {
            try {
                $results[] = $this->ingestOne($capture, (int) $agencyId, $user, $matcher, $dupes);
            } catch (\Throwable $e) {
                Log::warning('Deeds capture ingest failed for one record', [
                    'source_ref' => $capture['source_ref'] ?? null,
                    'error'      => $e->getMessage(),
                ]);
                $results[] = ['source_ref' => $capture['source_ref'] ?? null, 'error' => $e->getMessage()];
            }
        }

        return response()->json(['ok' => true, 'results' => $results]);
    }

    private function ingestOne(array $capture, int $agencyId, $user, $matcher, $dupes): array
    {
        $p      = $capture['property'];
        $owners = $capture['owners'] ?? [];
        $s      = $capture['sale'] ?? [];
        $ref    = $capture['source_ref'];

        // Resolve/create a Contact per owner — deduped on the owner ID (the join
        // key), same as before, just looped for however many owners CMA listed.
        // Phone left empty on every owner (phase-2 Virtual Agent fills it).
        $resolvedOwners = [];
        foreach ($owners as $o) {
            $ownerId    = isset($o['id_number']) ? preg_replace('/\s+/', '', (string) $o['id_number']) : null;
            $ownerName  = trim((string) ($o['name'] ?? ''));
            $surname    = isset($o['surname']) ? trim((string) $o['surname']) : null;
            $firstNames = isset($o['first_names']) ? trim((string) $o['first_names']) : null;
            if ($ownerName === '' && !$ownerId) {
                continue; // nothing usable on this row
            }
            $contactId = $this->resolveOwnerContact(
                $agencyId, $user, $ownerName, $ownerId, $o['id_type'] ?? null, $dupes, $surname, $firstNames
            );
            $resolvedOwners[] = [
                'contact_id' => $contactId,
                'name'       => $ownerName !== '' ? $ownerName : null,
                'id_number'  => $ownerId,
                'id_type'    => $o['id_type'] ?? null,
            ];
        }
        // owner_contact_id stays the FIRST/primary owner — existing consumers of
        // that column (e.g. TrackedProperty::ownerContact(), the Pitch entry
        // point) are untouched; the full list lives in tracked_property_owners.
        $ownerContactId = $resolvedOwners[0]['contact_id'] ?? null;

        // Match-or-create the tracked property (shared plumbing).
        // section_number (2026-08-13): the sectional-title dedup discriminator
        // — was missing entirely, so the matcher's numbersConflict() guard had
        // no way to see it and two different units in the same scheme/building
        // (same street address, often the same GPS pin) collapsed into one
        // TrackedProperty. See TrackedPropertyMatchOrCreateService::
        // numbersConflict() for the other half of this fix.
        $facts = array_filter([
            'street_number'         => $p['street_number'] ?? null,
            'street_name'           => $p['street_name'] ?? null,
            'unit_number'           => $p['unit_number'] ?? null,
            'section_number'        => $p['section_number'] ?? null,
            'complex_name'          => $p['complex_name'] ?? null,
            'address'               => $p['address'] ?? null,
            'suburb'                => $p['suburb'] ?? null,
            'town'                  => $p['municipality'] ?? null,
            'province'              => $p['province'] ?? null,
            'latitude'              => $p['latitude'] ?? null,
            'longitude'             => $p['longitude'] ?? null,
            'erf_number'            => $p['erf_number'] ?? null,
            'title_deed_number'     => $p['title_deed_number'] ?? null,
            'cadastral_extent'      => isset($p['section_extent_m2']) ? (string) $p['section_extent_m2'] : null,
            'property_type'         => $p['property_type'] ?? null,
            'last_known_sold_price' => $s['sale_price'] ?? null,
            'last_known_sold_date'  => $s['sale_date'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        $tp = $matcher->matchOrCreate(
            agencyId: $agencyId,
            facts: $facts,
            source: ['type' => 'deeds_capture', 'ref' => $ref, 'payload' => ['source' => 'cmainfo']],
            actorUserId: (int) $user->id,
        );

        $created = (bool) $tp->wasRecentlyCreated;

        // Deeds-specific fields (never blank out an existing value with null).
        $tp->fill(array_filter([
            'deeds_office'          => $p['deeds_office'] ?? null,
            'scheme_name'           => $p['scheme_name'] ?? null,
            'scheme_number'         => $p['scheme_number'] ?? null,
            'section_number'        => $p['section_number'] ?? null,
            'title_deed_number'     => $p['title_deed_number'] ?? null,
            'erf_number'            => $p['erf_number'] ?? null,
            'cadastral_extent'      => isset($p['section_extent_m2']) ? (string) $p['section_extent_m2'] : null,
            'property_type'         => $p['property_type'] ?? null,
            'last_known_sold_price' => $s['sale_price'] ?? null,
            'last_known_sold_date'  => $s['sale_date'] ?? null,
            'bond_holder'           => $s['bond_holder'] ?? null,
            'bond_amount'           => $s['bond_amount'] ?? null,
            'sale_type'             => $s['sale_type'] ?? null,
            'deeds_registered_date' => $s['registered_date'] ?? null,
            'owner_contact_id'      => $ownerContactId,
        ], static fn ($v) => $v !== null && $v !== ''));

        // Tag as a deeds capture ONLY when the deeds capture created this TP (or a
        // prior deeds capture already tagged it). Enriching an existing prospecting
        // TP must NOT pull it out of Opportunities.
        if ($created && empty($tp->capture_kind)) {
            $tp->capture_kind = 'deeds_capture';
        }

        $tp->save();

        $this->syncOwners($tp, $resolvedOwners);

        return [
            'source_ref'          => $ref,
            'tracked_property_id' => $tp->id,
            'owner_contact_id'    => $ownerContactId,
            'owner_contact_ids'   => array_values(array_filter(array_column($resolvedOwners, 'contact_id'))),
            'created'             => $created,
        ];
    }

    /**
     * Persist the full owner list. Keyed on (tracked_property_id, id_number) so a
     * re-capture of the same property updates the same rows instead of piling up
     * duplicates; an owner with no id_number always inserts fresh (nothing to key
     * a dedupe on) — a real edge case, not the common deeds-capture path.
     */
    private function syncOwners(\App\Models\Prospecting\TrackedProperty $tp, array $resolvedOwners): void
    {
        foreach ($resolvedOwners as $i => $o) {
            if ($o['id_number']) {
                \App\Models\Prospecting\TrackedPropertyOwner::updateOrCreate(
                    ['tracked_property_id' => $tp->id, 'id_number' => $o['id_number']],
                    ['contact_id' => $o['contact_id'], 'name' => $o['name'], 'id_type' => $o['id_type'], 'is_primary' => $i === 0]
                );
            } else {
                \App\Models\Prospecting\TrackedPropertyOwner::create([
                    'tracked_property_id' => $tp->id,
                    'contact_id'          => $o['contact_id'],
                    'name'                => $o['name'],
                    'id_number'           => null,
                    'id_type'             => $o['id_type'],
                    'is_primary'          => $i === 0,
                ]);
            }
        }
    }

    private function resolveOwnerContact(
        int $agencyId, $user, string $name, ?string $idNumber, ?string $idType, $dupes,
        ?string $surname = null, ?string $firstNames = null
    ): ?int
    {
        // Dedupe on the owner ID — the join key.
        if ($idNumber) {
            $matches = $dupes->findDuplicatesForIdentifiers([], [], $idNumber, $agencyId);
            if ($matches->isNotEmpty()) {
                $existing = $matches->first();
                $patch = [];
                if (empty($existing->id_number)) { $patch['id_number'] = $idNumber; }
                if (empty($existing->id_type) && $idType) { $patch['id_type'] = $idType; }
                if ($patch !== []) { $existing->update($patch); }
                return (int) $existing->id;
            }
        }

        // New owner — name + owner ID, phone LEFT EMPTY (phase-2 Virtual Agent
        // fills it, keyed by the owner ID). contacts.last_name/phone are NOT NULL.
        // Prefer the extension's already-parsed surname/first_names (handles
        // CMA's surname-first + compound-surname layout correctly) over the
        // naive first-space split, which mis-splits both of those cases.
        if ($surname !== null && $surname !== '') {
            $first = $firstNames ?? '';
            $last  = $surname;
        } else {
            [$first, $last] = $this->splitName($name);
        }
        $contact = Contact::create([
            'agency_id'             => $agencyId,
            'branch_id'             => $user->branch_id,
            'first_name'            => $first !== '' ? $first : 'Owner',
            'last_name'             => $last,
            'phone'                 => '',
            'id_number'             => $idNumber,
            'id_type'               => $idType,
            'id_number_captured_at' => $idNumber ? now() : null,
            'id_number_source'      => $idNumber ? 'deeds_capture' : null,
            'created_by_user_id'    => (int) $user->id,
        ]);

        return (int) $contact->id;
    }

    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/', $name);
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }
        $first = array_shift($parts);
        return [$first, implode(' ', $parts)];
    }
}
