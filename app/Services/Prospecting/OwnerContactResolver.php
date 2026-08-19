<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyOwner;
use App\Models\Property;
use App\Models\User;
use App\Services\ContactDuplicateService;
use App\Support\OwnerEntityClassifier;
use Illuminate\Support\Facades\DB;

/**
 * Turns OwnershipHistoryParser output into real Contact + TrackedPropertyOwner
 * rows, and links CURRENT owners onto a promoted Property. New, standalone
 * service — deliberately NOT a refactor of
 * Api\DeedsCaptureController::resolveOwnerContact()/resolveEntityOwnerContact()
 * (those stay untouched; that file is currently held by another lane). Reuses
 * the same lower-level shared services those methods use
 * (ContactDuplicateService, OwnerEntityClassifier) so the contact-creation
 * shape stays consistent, without touching the file that owns them.
 *
 * Full contract: .ai/specs/deeds-capture.md §7.8 (dedupe), §7.11 (linking).
 *
 * NOT yet wired into any live request path (Api\DeedsCaptureController::store()
 * or CoreX\DeedsCaptureController::promote()) — that wiring is spec §7.15
 * Stage 3, deliberately deferred until those files are clear of the other
 * lane currently working in them. This service is complete and tested on its
 * own; only the two call sites are missing.
 */
final class OwnerContactResolver
{
    public function __construct(private readonly ContactDuplicateService $dupes)
    {
    }

    /**
     * Persist one TrackedPropertyOwner row per parsed position, resolving/
     * creating/deduping its Contact along the way. Always inserts fresh rows
     * (no re-capture idempotency in this pass — see .ai/specs/deeds-capture.md
     * §7.15's Stage 3 note; the existing single-owner syncOwners() dedup key
     * doesn't extend cleanly here since one person can now hold several
     * distinct deed positions on the same property).
     *
     * @param OwnershipOwnerRow[] $rows
     * @return TrackedPropertyOwner[]
     */
    public function persist(TrackedProperty $trackedProperty, array $rows, int $agencyId, User $user): array
    {
        $persisted = [];
        foreach ($rows as $row) {
            $contactId = $this->resolveContact($agencyId, $user, $row);
            $persisted[] = TrackedPropertyOwner::create([
                'tracked_property_id' => $trackedProperty->id,
                'contact_id'          => $contactId,
                'name'                => $row->name,
                'id_number'           => $row->idNumber,
                'id_type'             => $row->idType,
                'is_primary'          => false,
                'role'                => TrackedPropertyOwner::ROLE_OWNER,
                'ownership_share_pct' => $row->sharePct,
                'deed_reference'      => $row->deedReference,
                // NEVER default a null ownershipStatus to 'current' here — null means the
                // parser could not classify this position (§7.9 case 4, an unparseable deed).
                // Storing it as-is (including null) is what keeps it out of
                // linkCurrentOwners()'s query below, structurally, not by a filter someone
                // could get wrong.
                'ownership_status'    => $row->ownershipStatus,
            ]);
        }

        return $persisted;
    }

    /**
     * §7.11 — link every CURRENT owner's contact onto the promoted Property as
     * its owner (contact_property, role='owner'). PAST owners, and any row
     * with no classification at all (§7.9 case 4), are structurally never
     * touched by this method — there is no code path here that could link
     * them, by construction, not by a filter someone could forget.
     *
     * @return int number of contact_property rows written
     */
    public function linkCurrentOwners(TrackedProperty $trackedProperty, Property $property): int
    {
        $contactIds = $trackedProperty->owners()
            ->where('ownership_status', TrackedPropertyOwner::OWNERSHIP_CURRENT)
            ->pluck('contact_id')
            ->filter()
            ->unique();

        foreach ($contactIds as $contactId) {
            DB::table('contact_property')->updateOrInsert(
                ['contact_id' => $contactId, 'property_id' => $property->id],
                ['role' => 'owner', 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return $contactIds->count();
    }

    private function resolveContact(int $agencyId, User $user, OwnershipOwnerRow $row): ?int
    {
        $isEntity = OwnerEntityClassifier::isEntity($row->name, $row->idType, $row->idNumber);

        return $isEntity
            ? $this->resolveEntityContact($agencyId, $user, $row)
            : $this->resolvePersonContact($agencyId, $user, $row);
    }

    /**
     * Dedupe on id_number — the join key. An ID-less row (still masked after
     * reveal, or genuinely never captured) does not dedupe against anything
     * and always inserts fresh — matching the existing single-owner path
     * (Api\DeedsCaptureController::resolveOwnerContact()), not a new gap.
     */
    private function resolvePersonContact(int $agencyId, User $user, OwnershipOwnerRow $row): ?int
    {
        if ($row->idNumber) {
            $matches = $this->dupes->findDuplicatesForIdentifiers([], [], $row->idNumber, $agencyId);
            if ($matches->isNotEmpty()) {
                $existing = $matches->first();
                $patch = [];
                if (empty($existing->id_number)) {
                    $patch['id_number'] = $row->idNumber;
                }
                if (empty($existing->id_type) && $row->idType) {
                    $patch['id_type'] = $row->idType;
                }
                if ($patch !== []) {
                    $existing->update($patch);
                }

                return (int) $existing->id;
            }
        }

        [$first, $last] = $this->splitName($row->name);
        $contact = Contact::create([
            'agency_id'             => $agencyId,
            'branch_id'             => $user->branch_id,
            'first_name'            => $first !== '' ? $first : 'Owner',
            'last_name'             => $last,
            'phone'                 => '',
            'id_number'             => $row->idNumber,
            'id_type'               => $row->idType,
            'id_number_captured_at' => $row->idNumber ? now() : null,
            'id_number_source'      => $row->idNumber ? 'deeds_capture_history' : null,
            'created_by_user_id'    => (int) $user->id,
        ]);

        return (int) $contact->id;
    }

    /**
     * Entity owner (trust / company). §7.7 — the registration number
     * (row->idNumber, already routed here never split/masked by the parser)
     * is stored on entity_reg_no, NEVER id_number/sa_id. Dedupes on
     * entity_reg_no, mirroring resolvePersonContact()'s id_number dedupe.
     */
    private function resolveEntityContact(int $agencyId, User $user, OwnershipOwnerRow $row): ?int
    {
        $regNo = $row->idNumber;

        if ($regNo) {
            $matches = $this->dupes->findDuplicatesForIdentifiers([], [], null, $agencyId, null, $regNo);
            if ($matches->isNotEmpty()) {
                $existing = $matches->first();
                $patch = [];
                if (empty($existing->entity_reg_no)) {
                    $patch['entity_reg_no'] = $regNo;
                }
                if ($existing->contact_kind !== Contact::TYPE_ENTITY) {
                    $patch['contact_kind'] = Contact::TYPE_ENTITY;
                }
                if (empty($existing->entity_name) && $row->name !== '') {
                    $patch['entity_name'] = $row->name;
                }
                if ($patch !== []) {
                    $existing->update($patch);
                }

                return (int) $existing->id;
            }
        }

        $entityName = $row->name !== '' ? $row->name : 'Unnamed entity';
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
        $parts = preg_split('/\s+/', $name) ?: [$name];
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }
        $first = array_shift($parts);

        return [$first, implode(' ', $parts)];
    }
}
