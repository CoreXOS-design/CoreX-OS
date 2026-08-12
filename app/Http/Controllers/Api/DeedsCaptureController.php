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
            'captures.*.owner'                      => 'nullable|array',
            'captures.*.owner.name'                 => 'nullable|string|max:255',
            'captures.*.owner.id_number'            => 'nullable|string|max:20',
            'captures.*.owner.id_type'              => 'nullable|in:sa_id,company_reg',
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
        $p   = $capture['property'];
        $o   = $capture['owner'] ?? [];
        $s   = $capture['sale'] ?? [];
        $ref = $capture['source_ref'];

        // Owner contact — deduped on the owner ID (the join key). Phone left empty.
        $ownerId   = isset($o['id_number']) ? preg_replace('/\s+/', '', (string) $o['id_number']) : null;
        $ownerName = trim((string) ($o['name'] ?? ''));
        $ownerContactId = ($ownerName !== '' || $ownerId)
            ? $this->resolveOwnerContact($agencyId, $user, $ownerName, $ownerId, $o['id_type'] ?? null, $dupes)
            : null;

        // Match-or-create the tracked property (shared plumbing).
        $facts = array_filter([
            'street_number'         => $p['street_number'] ?? null,
            'street_name'           => $p['street_name'] ?? null,
            'unit_number'           => $p['unit_number'] ?? null,
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

        return [
            'source_ref'          => $ref,
            'tracked_property_id' => $tp->id,
            'owner_contact_id'    => $ownerContactId,
            'created'             => $created,
        ];
    }

    private function resolveOwnerContact(int $agencyId, $user, string $name, ?string $idNumber, ?string $idType, $dupes): ?int
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
        [$first, $last] = $this->splitName($name);
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
