<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\Lease;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Services\ContactDuplicateService;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;

/**
 * AT-479 / rental-takeon-import.md R4 — works out, for one parsed reOS row, what CoreX already knows (property, owner,
 * tenant, the lease itself by reOS id) and the user's starting choices. Writes nothing to properties / contacts / leases:
 * only to the staging row. The same two canonical services the existing take-on dry run uses do the matching
 * (TrackedPropertyMatchOrCreateService, ContactDuplicateService) — no matching logic of its own.
 */
class ReosRowResolver
{
    public function __construct(
        private readonly TrackedPropertyMatchOrCreateService $properties,
        private readonly ContactDuplicateService $contacts,
        private readonly ReosAgentMatcher $agents,
    ) {
    }

    /** First resolution of a freshly parsed row (its payload_json and errors_json are already set). */
    public function resolve(RentalTakeOnImportRow $row, RentalTakeOnImportRun $run): RentalTakeOnImportRow
    {
        $p = $row->payload_json ?? [];
        $agencyId = (int) $run->agency_id;

        $existing = $row->external_id ? $this->existingLease($agencyId, (string) $row->external_id) : null;

        $property = $this->matchProperty($agencyId, $p, []);
        $owner = $this->matchContact($p['owner_name'] ?? null, $p['owner_email'] ?? null, $p['owner_phone'] ?? null, $agencyId);
        $tenant = $this->matchContact($p['tenant_name'] ?? null, $p['tenant_email'] ?? null, $p['tenant_phone'] ?? null, $agencyId);
        $agent = $this->agents->match($p['agent_text'] ?? null, $agencyId);

        $decisions = [
            'property' => ['create' => true, 'suburb_id' => null, 'suburb' => null, 'town' => null, 'province' => null, 'city_id' => null, 'province_id' => null],
            'owner' => $this->defaultPartyDecision($owner),
            'tenant' => $this->defaultPartyDecision($tenant),
            'agent_user_id' => $agent['user_id'],
            'agent_note' => $agent['reason'],
            'tenant_wallet_ref' => $p['tenant_wallet_ref'] ?? null,
        ];

        $complete = ($p['owner_name'] ?? null) && ($p['tenant_name'] ?? null) && ($p['rent'] ?? null) !== null && ($p['lease_start_date'] ?? null) !== null;

        $row->forceFill([
            'existing_lease_id' => $existing?->id,
            'property_match_action' => $property['action'],
            'property_match_tracked_id' => $property['tracked_id'],
            'property_match_label' => $property['label'],
            'landlord_match_json' => $owner ? [1 => $owner] : null,
            'tenant_match_json' => $tenant ? [1 => $tenant] : null,
            'lease_completeness' => $complete ? RentalTakeOnImportRow::COMPLETENESS_COMPLETE : RentalTakeOnImportRow::COMPLETENESS_DRAFT,
            'decisions_json' => $decisions,
            'status' => empty($row->errors_json) ? RentalTakeOnImportRow::STATUS_PENDING : RentalTakeOnImportRow::STATUS_ERROR,
            'selected' => false,
        ])->save();

        return ReosBlockers::refresh($row);
    }

    /**
     * The user picked (or cleared) a suburb: look again, now that CoreX knows the suburb — a property that was "not in
     * CoreX" only for want of one may well be there. A match drops the suburb requirement.
     */
    public function rematchProperty(RentalTakeOnImportRow $row): RentalTakeOnImportRow
    {
        $d = $row->decisions_json ?? [];
        $m = $this->matchProperty((int) $row->agency_id, $row->payload_json ?? [], $d['property'] ?? []);

        $row->forceFill([
            'property_match_action' => $m['action'],
            'property_match_tracked_id' => $m['tracked_id'],
            'property_match_label' => $m['label'],
        ])->save();

        return ReosBlockers::refresh($row);
    }

    public function existingLease(int $agencyId, string $externalId): ?Lease
    {
        return Lease::withoutGlobalScopes()->withTrashed()
            ->where('agency_id', $agencyId)
            ->where('external_source', Lease::EXTERNAL_SOURCE_REOS)
            ->where('external_id', $externalId)
            ->first();
    }

    /** @return array{action:string, tracked_id:?int, label:string, notes:list<string>} */
    private function matchProperty(int $agencyId, array $payload, array $location): array
    {
        $built = ReosPropertyFacts::build($payload['address'] ?? null, $location);
        $tp = $this->properties->findExistingMatch($agencyId, $built['facts']);

        if (! $tp) {
            return ['action' => RentalTakeOnImportRow::ACTION_CREATE, 'tracked_id' => null, 'label' => 'Not in CoreX', 'notes' => $built['notes']];
        }

        $why = $this->properties->describeLastMatch($built['facts'], $tp);

        return [
            'action' => RentalTakeOnImportRow::ACTION_MATCH,
            'tracked_id' => (int) $tp->id,
            'label' => 'In CoreX: ' . ($tp->displayAddress() ?? "#{$tp->id}") . (($why['reason'] ?? null) ? ' (' . $why['reason'] . ')' : ''),
            'notes' => $built['notes'],
        ];
    }

    /** @return array<string,mixed>|null  null when the cell named nobody */
    private function matchContact(?string $name, ?string $email, ?string $phone, int $agencyId): ?array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $kind = ReosContactNames::classify($name)['kind'];
        $found = $this->contacts->findDuplicatesForIdentifiers(
            ReosContactNames::splitValues($phone, false),
            ReosContactNames::splitValues($email, true),
            null,
            $agencyId,
        )->first();

        return [
            'name' => $name,
            'kind' => $kind,
            'action' => $found ? 'match' : 'create',
            'existing_contact_id' => $found?->id,
            'label' => $found
                ? 'In CoreX: ' . $found->full_name . ($found->phone ? ', ' . $found->phone : '')
                : 'Not in CoreX',
        ];
    }

    /** @return array{action:string, contact_id:?int} */
    private function defaultPartyDecision(?array $match): array
    {
        if ($match === null) {
            return ['action' => 'none', 'contact_id' => null];
        }

        return $match['action'] === 'match'
            ? ['action' => 'link', 'contact_id' => (int) $match['existing_contact_id']]
            : ['action' => 'create', 'contact_id' => null];
    }
}
