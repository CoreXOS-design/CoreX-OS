<?php

namespace App\Services\Rentals\TakeOnImport;

use App\Models\Branch;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Models\User;
use App\Services\ContactDuplicateService;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;

/**
 * .ai/specs/rental-takeon-import.md §5.2 — resolves, for every parsed row,
 * what WOULD happen on confirm, writing nothing to properties/contacts/
 * leases. Calls the two canonical de-dup services — no new matching logic
 * is written here (non-negotiable #10, the Universal Match-or-Create
 * Rule, and leases.md §6's own instruction to reuse this exact discipline).
 */
class RentalTakeOnDryRunResolver
{
    public function __construct(
        private TrackedPropertyMatchOrCreateService $propertyMatcher,
        private ContactDuplicateService $contactDuplicates,
    ) {
    }

    public function resolve(RentalTakeOnImportRow $row, RentalTakeOnImportRun $run): void
    {
        $payload = $row->payload_json ?? [];
        $errors = [];
        $warnings = [];

        $propertyMatch = $this->resolveProperty($payload, (int) $run->agency_id, $errors);
        $landlordMatches = $this->resolveContactGroup($payload, RentalTakeOnFieldSchema::landlordNumbers(), 'landlord', (int) $run->agency_id);
        $tenantMatches = $this->resolveContactGroup($payload, RentalTakeOnFieldSchema::tenantNumbers(), 'tenant', (int) $run->agency_id);

        $this->validateRequiredFields($payload, $errors, $warnings);
        $this->resolveAgentAndBranch($payload, (int) $run->agency_id, $warnings);

        $hasLandlord = trim((string) ($payload['landlord1_name'] ?? '')) !== '';
        $hasTenant = trim((string) ($payload['tenant1_name'] ?? '')) !== '';
        $hasRent = $payload['monthly_rental_amount'] ?? null;
        $hasStart = $payload['lease_start_date'] ?? null;

        $completeness = ($hasLandlord && $hasTenant && $hasRent !== null && $hasStart !== null)
            ? RentalTakeOnImportRow::COMPLETENESS_COMPLETE
            : RentalTakeOnImportRow::COMPLETENESS_DRAFT;

        $row->update([
            'property_match_action' => $propertyMatch['action'],
            'property_match_tracked_id' => $propertyMatch['tracked_id'],
            'property_match_label' => $propertyMatch['label'],
            'landlord_match_json' => $landlordMatches,
            'tenant_match_json' => $tenantMatches,
            'lease_completeness' => $completeness,
            'errors_json' => $errors ?: null,
            'warnings_json' => $warnings ?: null,
            'status' => $errors ? RentalTakeOnImportRow::STATUS_ERROR : RentalTakeOnImportRow::STATUS_PENDING,
        ]);
    }

    /**
     * @return array{action: ?string, tracked_id: ?int, label: ?string}
     */
    private function resolveProperty(array $payload, int $agencyId, array &$errors): array
    {
        $streetName = trim((string) ($payload['street_name'] ?? ''));
        $suburb = trim((string) ($payload['suburb'] ?? ''));
        $erf = trim((string) ($payload['erf_number'] ?? ''));

        if (($streetName === '' || $suburb === '') && ($erf === '' || $suburb === '')) {
            $errors[] = 'No property could be matched or created — provide Street name + Suburb, or Erf number + Suburb.';

            return ['action' => null, 'tracked_id' => null, 'label' => null];
        }

        $addressParts = array_filter([
            $payload['street_number'] ?? null,
            $streetName,
            $payload['unit_complex_name'] ?? null,
            $suburb,
        ]);

        $facts = array_filter([
            'street_number' => $payload['street_number'] ?? null,
            'street_name' => $streetName ?: null,
            'complex_name' => $payload['unit_complex_name'] ?? null,
            'suburb' => $suburb ?: null,
            'erf_number' => $erf ?: null,
            'address' => implode(' ', $addressParts),
        ], static fn ($v) => $v !== null && $v !== '');

        $tp = $this->propertyMatcher->findExistingMatch($agencyId, $facts);

        if (!$tp) {
            return ['action' => RentalTakeOnImportRow::ACTION_CREATE, 'tracked_id' => null, 'label' => 'Will create a new property'];
        }

        $description = $this->propertyMatcher->describeLastMatch($facts, $tp);
        $label = 'Matches existing property: ' . ($tp->displayAddress() ?? "#{$tp->id}")
            . ($description['reason'] ?? null ? ' (' . $description['reason'] . ')' : '');

        return ['action' => RentalTakeOnImportRow::ACTION_MATCH, 'tracked_id' => $tp->id, 'label' => $label];
    }

    private function resolveContactGroup(array $payload, array $numbers, string $prefix, int $agencyId): array
    {
        $matches = [];

        foreach ($numbers as $n) {
            $name = trim((string) ($payload["{$prefix}{$n}_name"] ?? ''));
            if ($name === '') {
                continue;
            }

            $phone = trim((string) ($payload["{$prefix}{$n}_phone"] ?? ''));
            $email = trim((string) ($payload["{$prefix}{$n}_email"] ?? ''));
            $idOrReg = trim((string) ($payload["{$prefix}{$n}_id_or_reg"] ?? ($payload["{$prefix}{$n}_id_number"] ?? '')));

            $idNumber = ($idOrReg !== '' && ctype_digit($idOrReg) && strlen($idOrReg) === 13) ? $idOrReg : null;
            $entityRegNo = ($idOrReg !== '' && $idNumber === null) ? $idOrReg : null;

            $duplicates = $this->contactDuplicates->findDuplicatesForIdentifiers(
                array_values(array_filter([$phone])),
                array_values(array_filter([$email])),
                $idNumber,
                $agencyId,
                null,
                $entityRegNo,
            );

            $existing = $duplicates->first();

            $matches[$n] = [
                'name' => $name,
                'action' => $existing ? 'match' : 'create',
                'existing_contact_id' => $existing?->id,
                'label' => $existing
                    ? 'Looks like an existing contact: ' . $existing->full_name . ($existing->phone ? ', ' . $existing->phone : '')
                    : 'Will create a new contact',
            ];
        }

        return $matches;
    }

    private function validateRequiredFields(array $payload, array &$errors, array &$warnings): void
    {
        $rent = $payload['monthly_rental_amount'] ?? null;
        if ($rent === null) {
            $errors[] = 'Monthly rental amount is missing or not a valid number.';
        } elseif ($rent <= 0) {
            $errors[] = 'Monthly rental amount must be greater than zero.';
        }

        if (($payload['lease_start_date'] ?? null) === null) {
            $errors[] = 'Lease start date is missing or not a valid date.';
        }

        $leaseType = trim((string) ($payload['lease_type'] ?? ''));
        if ($leaseType !== '' && !in_array($leaseType, RentalTakeOnFieldSchema::LEASE_TYPE_OPTIONS, true)) {
            $errors[] = "Lease type \"{$leaseType}\" is not recognised — use Fixed term or Month-to-month.";
        }

        if ($leaseType === RentalTakeOnFieldSchema::LEASE_TYPE_FIXED && ($payload['lease_end_date'] ?? null) === null) {
            $warnings[] = 'Fixed term lease has no end date — will import as a draft needing completion.';
        }

        if (trim((string) ($payload['landlord1_name'] ?? '')) === '') {
            $warnings[] = 'No landlord captured — will import as a draft needing completion.';
        }

        if (trim((string) ($payload['tenant1_name'] ?? '')) === '') {
            $warnings[] = 'No tenant captured — will import as a draft needing completion.';
        }

        if (($payload['management_fee_percent'] ?? null) !== null && ($payload['management_fee_amount'] ?? null) !== null) {
            $warnings[] = 'Both a management fee % and amount were given — the % will be used.';
        }
    }

    private function resolveAgentAndBranch(array $payload, int $agencyId, array &$warnings): void
    {
        $agentEmail = trim((string) ($payload['agent_email'] ?? ''));
        if ($agentEmail !== '') {
            $agent = User::withoutGlobalScopes()->where('agency_id', $agencyId)->whereRaw('LOWER(email) = ?', [strtolower($agentEmail)])->first();
            if (!$agent) {
                $warnings[] = "Agent email \"{$agentEmail}\" was not found on this agency — will assign to the person running this import.";
            }
        }

        $branchName = trim((string) ($payload['branch'] ?? ''));
        if ($branchName !== '') {
            $branch = Branch::where('agency_id', $agencyId)->whereRaw('LOWER(name) = ?', [strtolower($branchName)])->first();
            if (!$branch) {
                $warnings[] = "Branch \"{$branchName}\" was not found on this agency — will use your own branch.";
            }
        }
    }
}
