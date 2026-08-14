<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Services\ContactDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TVA company DIRECTORSHIP capture — POST /api/v1/tva-company-directors.
 *
 * A TVA company record (searched by CIPC registration number) has no
 * contactable numbers of its own; its people are its DIRECTORS. The extension
 * scrapes the on-screen directorship table (#directorshipTable) — each director
 * is a natural person (SA ID + "SURNAME, INITIALS" name + gender icon) — plus
 * the company (reg-no from the URL, name from the table's Company column). This
 * endpoint:
 *   - match-or-creates the company ENTITY Contact (contact_kind=entity,
 *     entity_name, entity_reg_no) — normally already created by the CMA/deeds
 *     capture and matched here on reg-no; created if absent so the link always
 *     has a target;
 *   - match-or-creates each DIRECTOR as a natural-person Contact (by SA ID,
 *     via ContactDuplicateService — Non-Negotiable #10 match-or-create);
 *   - links each director to the entity via the existing contact_representatives
 *     model (ContactRepresentative), first/only director marked is_primary when
 *     the entity has no primary yet.
 *
 * It does NOT scrape the directors' phone numbers — the agent runs the normal
 * TVA PERSON scrape (/Search/Person) on each director afterward, and those
 * numbers then match these director contacts by id_number (that flow is
 * unchanged). Scope: ingestion + director→entity link only; no DR2/e-sign
 * representation logic.
 */
class TvaCompanyDirectorsController extends Controller
{
    public function store(Request $request, ContactDuplicateService $dupes): JsonResponse
    {
        $validated = $request->validate([
            'source'                       => 'nullable|string|max:30',
            'company'                      => 'required|array',
            'company.registration_number'  => 'required|string|max:50',
            'company.name'                 => 'nullable|string|max:255',
            'directors'                    => 'required|array|min:1',
            'directors.*.id_number'         => 'nullable|string|max:20',
            'directors.*.full_name'         => 'nullable|string|max:255',
            'directors.*.gender'            => 'nullable|string|max:10',
        ]);

        $user = $request->user();
        $agencyId = (int) ($user?->effectiveAgencyId() ?? $user?->agency_id ?? 0);
        abort_if($agencyId === 0, 403, 'No agency context for this token.');

        $regNo = trim((string) $validated['company']['registration_number']);
        $companyName = trim((string) ($validated['company']['name'] ?? '')) ?: ('Company ' . $regNo);

        $entityId = $this->resolveEntityContact($agencyId, $user, $companyName, $regNo, $dupes);

        // The VISIBLE landing (Johan): directors must appear on /corex/deeds-capture
        // under the company's property/deed, exactly like scraped deed owners. The
        // CMA/deeds capture stored the company's registration number as its owner-row
        // id_number, so we match the SAME tracked_property on the reg-no and add each
        // director as a tracked_property_owner (natural person to work). This also
        // wires the existing person-number flow: when the agent later runs the TVA
        // person scrape on a director, that capture nests under this property by
        // matching the director's id_number as an owner here.
        $trackedPropertyId = $this->matchCompanyProperty($agencyId, $regNo);

        // Only stamp is_primary if the entity has no primary representative yet.
        $entityHasPrimary = ContactRepresentative::where('entity_contact_id', $entityId)
            ->where('is_primary', true)->exists();

        $out = [];
        foreach ($validated['directors'] as $d) {
            $id   = preg_replace('/\s+/', '', (string) ($d['id_number'] ?? ''));
            $name = trim((string) ($d['full_name'] ?? ''));
            if ($id === '' && $name === '') {
                continue; // nothing usable on this row
            }

            try {
                $directorId = $this->resolveDirectorContact($agencyId, $user, $name, $id, $d['gender'] ?? null, $dupes);

                $primary = false;
                if (!$entityHasPrimary) {
                    $primary = true;
                    $entityHasPrimary = true;
                }
                $linkId = $this->linkDirector($entityId, $directorId, $primary);

                // Land the director in DEEDS — as a person to work on the company's
                // property (visible on /corex/deeds-capture).
                $landedInDeeds = false;
                if ($trackedPropertyId !== null && $id !== '') {
                    $this->addDirectorAsPropertyOwner($trackedPropertyId, $directorId, $name, $id);
                    $landedInDeeds = true;
                }

                $out[] = [
                    'id_number'              => $id !== '' ? $id : null,
                    'contact_id'             => $directorId,
                    'representative_link_id' => $linkId,
                    'landed_in_deeds'        => $landedInDeeds,
                ];
            } catch (\Throwable $e) {
                Log::warning('TVA director capture failed', ['id_number' => $id, 'error' => $e->getMessage()]);
                $out[] = ['id_number' => $id !== '' ? $id : null, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'ok'                  => true,
            'entity_contact_id'   => $entityId,
            'tracked_property_id' => $trackedPropertyId,
            'company'             => ['registration_number' => $regNo, 'name' => $companyName],
            'directors'           => $out,
        ]);
    }

    /**
     * Match the company's tracked property/deed by registration number. The
     * CMA/deeds capture records the company owner's reg-no as the owner-row
     * id_number, so directors land on the SAME deed the property was captured
     * under. Returns null when the company property hasn't been CMA-captured yet
     * (directors still capture as contacts + the entity link).
     */
    private function matchCompanyProperty(int $agencyId, string $regNo): ?int
    {
        $id = DB::table('tracked_property_owners as o')
            ->join('tracked_properties as t', 't.id', '=', 'o.tracked_property_id')
            ->where('t.agency_id', $agencyId)
            ->whereNull('t.deleted_at')
            ->where('t.capture_kind', 'deeds_capture')
            ->where('o.id_number', $regNo)
            ->orderByDesc('t.id')
            ->value('o.tracked_property_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Add a director as a (non-primary) owner-row on the company's deed so it
     * surfaces on /corex/deeds-capture as a natural person to work. Keyed on
     * (tracked_property_id, id_number) so re-capture updates, never duplicates.
     */
    private function addDirectorAsPropertyOwner(int $trackedPropertyId, int $contactId, string $name, string $idNumber): void
    {
        \App\Models\Prospecting\TrackedPropertyOwner::updateOrCreate(
            ['tracked_property_id' => $trackedPropertyId, 'id_number' => $idNumber],
            [
                'contact_id' => $contactId,
                'name'       => $name !== '' ? $name : null,
                'id_type'    => 'sa_id',
                'is_primary' => false,
            ]
        );
    }

    /** Match-or-create the company entity Contact on its CIPC reg-no. */
    private function resolveEntityContact(int $agencyId, $user, string $name, string $regNo, ContactDuplicateService $dupes): int
    {
        $matches = $dupes->findDuplicatesForIdentifiers([], [], null, $agencyId, null, $regNo);
        if ($matches->isNotEmpty()) {
            $existing = $matches->first();
            $patch = [];
            if ($existing->contact_kind !== Contact::TYPE_ENTITY) { $patch['contact_kind'] = Contact::TYPE_ENTITY; }
            if (empty($existing->entity_reg_no)) { $patch['entity_reg_no'] = $regNo; }
            if (empty($existing->entity_name)) { $patch['entity_name'] = $name; }
            if ($patch !== []) { $existing->update($patch); }
            return (int) $existing->id;
        }

        $contact = Contact::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $user->branch_id,
            'contact_kind'       => Contact::TYPE_ENTITY,
            'entity_name'        => $name,
            'entity_reg_no'      => $regNo,
            'first_name'         => $name, // mirrored by ContactObserver; set for the NOT NULL column
            'last_name'          => '',
            'phone'              => '',
            'created_by_user_id' => (int) $user->id,
        ]);
        return (int) $contact->id;
    }

    /** Match-or-create a director as a natural-person Contact (by SA ID). */
    private function resolveDirectorContact(int $agencyId, $user, string $name, string $idNumber, ?string $gender, ContactDuplicateService $dupes): int
    {
        [$first, $last] = $this->splitDirectorName($name);

        if ($idNumber !== '') {
            $matches = $dupes->findDuplicatesForIdentifiers([], [], $idNumber, $agencyId);
            if ($matches->isNotEmpty()) {
                $existing = $matches->first();
                $patch = [];
                if (empty($existing->id_number)) { $patch['id_number'] = $idNumber; }
                if ($first !== '' && (empty($existing->first_name) || $existing->first_name === 'Director')) { $patch['first_name'] = $first; }
                if ($last !== '' && empty($existing->last_name)) { $patch['last_name'] = $last; }
                if ($patch !== []) { $existing->update($patch); }
                return (int) $existing->id;
            }
        }

        $g = $this->normaliseGender($gender);
        $contact = Contact::create([
            'agency_id'             => $agencyId,
            'branch_id'             => $user->branch_id,
            'contact_kind'          => Contact::TYPE_NATURAL_PERSON,
            'first_name'            => $first !== '' ? $first : 'Director',
            'last_name'             => $last,
            'phone'                 => '',
            'id_number'             => $idNumber !== '' ? $idNumber : null,
            'id_number_captured_at' => $idNumber !== '' ? now() : null,
            'id_number_source'      => $idNumber !== '' ? 'tva_directorship' : null,
            'notes'                 => $g !== null ? ('Director (via TVA directorship). Gender: ' . $g) : null,
            'created_by_user_id'    => (int) $user->id,
        ]);
        return (int) $contact->id;
    }

    /** Create/restore the entity↔director representative link (unique pair). */
    private function linkDirector(int $entityId, int $directorId, bool $primary): int
    {
        $link = ContactRepresentative::withTrashed()
            ->where('entity_contact_id', $entityId)
            ->where('representative_contact_id', $directorId)
            ->first();

        if ($link) {
            if ($link->trashed()) { $link->restore(); }
            return (int) $link->id;
        }

        $link = ContactRepresentative::create([
            'entity_contact_id'         => $entityId,
            'representative_contact_id' => $directorId,
            'is_primary'                => $primary,
        ]);
        return (int) $link->id;
    }

    /** TVA directorship name "SURNAME, INITIALS" → [first(=initials), last(=surname)]. */
    private function splitDirectorName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['', ''];
        }
        if (str_contains($name, ',')) {
            [$sur, $rest] = array_pad(explode(',', $name, 2), 2, '');
            return [trim($rest), trim($sur)];
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        if (count($parts) <= 1) {
            return ['', $name];
        }
        $last = array_pop($parts);
        return [implode(' ', $parts), $last];
    }

    private function normaliseGender(?string $gender): ?string
    {
        $g = strtoupper(trim((string) $gender));
        if ($g === '') {
            return null;
        }
        if (in_array($g, ['M', 'MALE'], true)) {
            return 'M';
        }
        if (in_array($g, ['F', 'FEMALE'], true)) {
            return 'F';
        }
        return null;
    }
}
