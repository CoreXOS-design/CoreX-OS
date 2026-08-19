<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Support\OwnerEntityClassifier;
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
        // cmainfo.co.za PropSearch often returns a comparable with a '0' / blank /
        // unparseable sale or registered date. `nullable|date` only skips a NULL — a
        // non-null '0' hits the `date` rule, fails, and (because validation runs over
        // the whole captures[] array up front) 422s the ENTIRE capture batch before a
        // single record is ingested. Coerce any date the `date` rule would reject to
        // null here so one bad comp date never blocks the import; genuine dates are
        // left untouched and validate + store exactly as before.
        $captures = $request->input('captures');
        if (is_array($captures)) {
            foreach ($captures as $i => $capture) {
                if (!isset($capture['sale']) || !is_array($capture['sale'])) {
                    continue;
                }
                foreach (['sale_date', 'registered_date'] as $dateField) {
                    if (array_key_exists($dateField, $capture['sale'])) {
                        $captures[$i]['sale'][$dateField] = $this->sanitizeCaptureDate($capture['sale'][$dateField]);
                    }
                }
                // Same shape of bug, numeric side: a comp with no sale price shows as
                // '-' on cmainfo. The extension's own parseCurrency() already turns
                // that into null before sending — verified live-repro 2026-08-17 — so
                // this rule's real-world exposure is a case parseCurrency doesn't (a
                // future source, or a payload built another way) sending a literal
                // non-numeric placeholder straight through. `numeric` doesn't skip a
                // non-null, non-numeric string, so it would 422 the whole batch the
                // same way an unsanitized date does. Sanitizing here closes that gap
                // symmetrically rather than leaving it to the sender's own parsing.
                foreach (['sale_price', 'bond_amount'] as $numericField) {
                    if (array_key_exists($numericField, $capture['sale'])) {
                        $captures[$i]['sale'][$numericField] = $this->sanitizeCaptureNumeric($capture['sale'][$numericField]);
                    }
                }
            }
            $request->merge(['captures' => $captures]);
        }

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
            // Extent contract (.ai/specs/deeds-capture.md §6 Part A, 2026-08-19)
            // — three independent, optional values. section_extent_m2 already
            // existed (kept, name unchanged, for backward compatibility with an
            // older extension build); erf_extent_m2/cadastral_extent_m2 are new.
            'captures.*.property.section_extent_m2'    => 'nullable|numeric',
            'captures.*.property.erf_extent_m2'        => 'nullable|numeric',
            'captures.*.property.cadastral_extent_m2'  => 'nullable|numeric',
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

    /**
     * Return the value unchanged when it is a date the `nullable|date` rule would
     * accept, otherwise null. cmainfo PropSearch comps arrive with '0' / '' /
     * unparseable sale + registered dates; nulling them lets the capture proceed
     * instead of 422-ing the whole batch. Mirrors Laravel's `date` rule (strtotime
     * must parse it, and a full calendar date must be real per checkdate — so an
     * impossible date like 2023-02-30 is nulled too, never stored).
     */
    private function sanitizeCaptureDate($value): ?string
    {
        if ($value === null || (!is_string($value) && !is_numeric($value))) {
            return null;
        }
        $v = trim((string) $value);
        if ($v === '' || $v === '0' || strtotime($v) === false) {
            return null;
        }
        $parts = date_parse($v);
        if (!is_array($parts) || $parts['error_count'] > 0) {
            return null;
        }
        if ($parts['year'] && $parts['month'] && $parts['day']
            && !checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year'])) {
            return null;
        }

        return $v;
    }

    /**
     * Return the value unchanged when it is something the `numeric` rule would
     * accept, otherwise null. Mirrors sanitizeCaptureDate() for the price/bond
     * side — a non-null, non-numeric placeholder (e.g. '-') would otherwise hit
     * `numeric` and 422 the whole batch the same way an unsanitized date does.
     */
    private function sanitizeCaptureNumeric($value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        if ($v === '' || !is_numeric($v)) {
            return null;
        }

        return (float) $v;
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
        $blockedCompanies = [];
        foreach ($owners as $o) {
            $ownerId    = isset($o['id_number']) ? preg_replace('/\s+/', '', (string) $o['id_number']) : null;
            $ownerName  = trim((string) ($o['name'] ?? ''));
            $surname    = isset($o['surname']) ? trim((string) $o['surname']) : null;
            $firstNames = isset($o['first_names']) ? trim((string) $o['first_names']) : null;
            if ($ownerName === '' && !$ownerId) {
                continue; // nothing usable on this row
            }

            // Classify the owner as a natural person or a juristic ENTITY
            // (company / CC / trust / body corporate). This REPLACES the
            // 2026-08-13 interim "block companies" rule: a genuine entity owner
            // is now CAPTURED as an entity Contact (contact_kind='entity',
            // entity_name, entity_reg_no) instead of dropped — see
            // .ai/specs/contact-entity-type.md §6.1. The classifier
            // (App\Support\OwnerEntityClassifier) also fixes the old
            // false-positive that blocked real natural persons whose scraped ID
            // was not a valid 13-digit SA ID (foreign national / OCR / partial
            // capture — the "12 Radstock Road, Southbroom" case): a valid SA ID
            // is definitive proof of a person, and an owner with no company
            // evidence defaults to a person rather than being blocked.
            $isEntity = OwnerEntityClassifier::isEntity($ownerName, $o['id_type'] ?? null, $ownerId);

            $contactId = $this->resolveOwnerContact(
                $agencyId, $user, $ownerName, $ownerId, $o['id_type'] ?? null, $dupes, $surname, $firstNames, $isEntity
            );
            $resolvedOwners[] = [
                'contact_id' => $contactId,
                'name'       => $ownerName !== '' ? $ownerName : null,
                'id_number'  => $ownerId,
                'id_type'    => $o['id_type'] ?? null,
                'is_entity'  => $isEntity,
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
        // 2026-08-19 (Johan, .ai/specs/deeds-capture.md §6 Part A) — EVERY field
        // this capture actually read now flows through ONE mechanism: this
        // $facts array feeds matchOrCreate() → enrich(), which is the ONLY
        // place precedence is decided and the ONLY place the audit trail
        // (source_chain[].field_changes, read by the Deeds Capture screen) is
        // built. Previously deeds_office/scheme_name/bond_holder/bond_amount/
        // sale_type/deeds_registered_date bypassed enrich() entirely via a
        // second, separate fill()+save() below with different (unconditional)
        // overwrite semantics and no audit trail — that split is exactly what
        // let GPS silently lose to a stale import while these fields silently
        // won unconditionally. One mechanism, one rule, one audit trail now.
        //
        // Extent contract (Johan, 2026-08-19) — THREE distinct values, three
        // distinct homes, never substituted for one another. A freehold panel
        // has Extent + Cadastral extent and no Section extent; a sectional
        // panel has Section extent and no Extent/Cadastral extent. All three
        // optional and independent — absent is absent, no fallback:
        //   erf_extent_m2        (freehold "Extent")           -> erf_size_m2
        //   cadastral_extent_m2  (freehold "Cadastral extent") -> cadastral_extent
        //   section_extent_m2    (sectional "Section extent")  -> section_extent_m2
        // section_extent_m2 keeps its payload NAME for backward compatibility
        // with an extension build that predates this contract — but that value
        // now correctly lands in ITS OWN column instead of overloading
        // cadastral_extent (the root cause of a sectional unit's floor area
        // landing in a promoted Property's erf-size column — see
        // TrackedPropertyMatchOrCreateService::promoteToStock()).
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
            'erf_size_m2'           => isset($p['erf_extent_m2']) ? (string) $p['erf_extent_m2'] : null,
            'cadastral_extent'      => isset($p['cadastral_extent_m2']) ? (string) $p['cadastral_extent_m2'] : null,
            'section_extent_m2'     => isset($p['section_extent_m2']) ? (string) $p['section_extent_m2'] : null,
            'property_type'         => $p['property_type'] ?? null,
            'deeds_office'          => $p['deeds_office'] ?? null,
            'scheme_name'           => $p['scheme_name'] ?? null,
            'scheme_number'         => $p['scheme_number'] ?? null,
            'last_known_sold_price' => $s['sale_price'] ?? null,
            'last_known_sold_date'  => $s['sale_date'] ?? null,
            'bond_holder'           => $s['bond_holder'] ?? null,
            'bond_amount'           => $s['bond_amount'] ?? null,
            'sale_type'             => $s['sale_type'] ?? null,
            'deeds_registered_date' => $s['registered_date'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        $tp = $matcher->matchOrCreate(
            agencyId: $agencyId,
            facts: $facts,
            source: ['type' => 'deeds_capture', 'ref' => $ref, 'payload' => ['source' => 'cmainfo']],
            actorUserId: (int) $user->id,
        );

        $created = (bool) $tp->wasRecentlyCreated;

        // owner_contact_id is a relationship pointer, not a captured physical
        // fact — it deliberately stays OUTSIDE the facts/enrich()/audit
        // mechanism above (multi-owner precedence is a separate, not-yet-built
        // concern — .ai/specs/deeds-capture.md §7, out of scope here). Kept to
        // its pre-existing behaviour: set whenever this capture resolved one.
        if ($ownerContactId !== null) {
            $tp->owner_contact_id = $ownerContactId;
        }

        // Tag as a deeds capture ONLY when the deeds capture created this TP (or a
        // prior deeds capture already tagged it). Enriching an existing prospecting
        // TP must NOT pull it out of Opportunities.
        if ($created && empty($tp->capture_kind)) {
            $tp->capture_kind = 'deeds_capture';
        }

        // DEEDS BUG 1 fix (2026-08-19) — the deeds-capture EVENT marker, stamped
        // on EVERY deeds capture (created OR existing), independent of the
        // capture_kind pipeline classification above. Without this, a capture
        // landing on a property already classified as a prospecting/P24 lead (or
        // already deeds-captured previously) enriched the TrackedProperty but the
        // capture_kind guard above never fired — the capture "vanished": the
        // extension reported success, but nothing appeared on the Deeds Capture
        // screen. DeedsCaptureController::index() (CoreX, the screen's own
        // controller) surfaces on deeds_captured_at IS NOT NULL so a re-capture of
        // an existing lead now shows there too, flagged as existing/linked,
        // WITHOUT changing capture_kind and therefore without pulling the lead out
        // of its MIC Opportunities pipeline.
        $tp->deeds_captured_at = now();

        $tp->save();

        $this->syncOwners($tp, $resolvedOwners);

        return [
            'source_ref'          => $ref,
            'tracked_property_id' => $tp->id,
            'owner_contact_id'    => $ownerContactId,
            'owner_contact_ids'   => array_values(array_filter(array_column($resolvedOwners, 'contact_id'))),
            'created'             => $created,
            'blocked_companies'   => $blockedCompanies,
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
        ?string $surname = null, ?string $firstNames = null, bool $isEntity = false
    ): ?int
    {
        // Juristic entity (company/CC/trust) — capture as an entity Contact.
        if ($isEntity) {
            return $this->resolveEntityOwnerContact($agencyId, $user, $name, $idNumber, $dupes);
        }

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

    /**
     * Entity owner (company / CC / trust / body corporate) — capture as an
     * entity Contact: contact_kind='entity', entity_name = the owner name,
     * entity_reg_no = the captured registration number (moved OFF the overloaded
     * id_number/id_type columns per .ai/specs/contact-entity-type.md §6.1). The
     * representative (director/trustee) is left blank — the scraper knows the
     * entity, not yet the human behind it; that link is added later (§5(a)).
     * first_name/last_name are mirrored from entity_name by ContactObserver;
     * set here too so the NOT NULL columns are satisfied regardless of hook
     * order. Dedupe on entity_reg_no — the entity join key — mirroring how the
     * natural-person path dedupes on id_number.
     */
    private function resolveEntityOwnerContact(int $agencyId, $user, string $name, ?string $regNo, $dupes): ?int
    {
        $regNo = ($regNo !== null && $regNo !== '') ? $regNo : null;

        if ($regNo) {
            $matches = $dupes->findDuplicatesForIdentifiers([], [], null, $agencyId, null, $regNo);
            if ($matches->isNotEmpty()) {
                $existing = $matches->first();
                $patch = [];
                if (empty($existing->entity_reg_no)) { $patch['entity_reg_no'] = $regNo; }
                if ($existing->contact_kind !== Contact::TYPE_ENTITY) { $patch['contact_kind'] = Contact::TYPE_ENTITY; }
                if (empty($existing->entity_name) && $name !== '') { $patch['entity_name'] = $name; }
                if ($patch !== []) { $existing->update($patch); }
                return (int) $existing->id;
            }
        }

        $entityName = $name !== '' ? $name : 'Unnamed entity';
        $contact = Contact::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $user->branch_id,
            'contact_kind'       => Contact::TYPE_ENTITY,
            'entity_name'        => $entityName,
            'entity_reg_no'      => $regNo,
            'first_name'         => $entityName,
            'last_name'          => '',
            'phone'              => '',
            'created_by_user_id' => (int) $user->id,
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
