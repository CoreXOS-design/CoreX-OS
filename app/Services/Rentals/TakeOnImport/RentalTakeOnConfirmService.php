<?php

namespace App\Services\Rentals\TakeOnImport;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactType;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\RentalTakeOnImportRow;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use App\Services\Rentals\LeaseActivationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/rental-takeon-import.md §5.3 — one DB transaction per row. Calls
 * the SAME two canonical services the dry run previewed against
 * (TrackedPropertyMatchOrCreateService, via matchOrCreate() + promoteToStock()
 * this time — non-negotiable #10) plus ContactPropertyLinker and
 * LeaseActivationService, already-built production code. No notification
 * of any kind is ever sent from this path — see the class-level guarantee
 * asserted in tests/Feature/Rentals/TakeOnImport/NoNotificationTest.php.
 */
class RentalTakeOnConfirmService
{
    public function __construct(
        private TrackedPropertyMatchOrCreateService $propertyMatcher,
        private LeaseActivationService $leaseActivation,
    ) {
    }

    /**
     * @return array{ok: bool, message: ?string}
     */
    public function confirmRow(RentalTakeOnImportRow $row, int $actingUserId): array
    {
        if (in_array($row->status, [RentalTakeOnImportRow::STATUS_CONFIRMED, RentalTakeOnImportRow::STATUS_EXCLUDED], true)) {
            return ['ok' => false, 'message' => 'Row already ' . $row->status . '.'];
        }
        if ($row->hasBlockingErrors()) {
            return ['ok' => false, 'message' => 'Row has blocking errors and cannot be confirmed.'];
        }

        $run = $row->run;
        $agencyId = (int) $run->agency_id;
        $payload = $row->payload_json ?? [];

        try {
            DB::transaction(function () use ($row, $run, $agencyId, $payload, $actingUserId) {
                [$agentId, $branchId] = $this->resolveAgentAndBranch($payload, $agencyId, $actingUserId, $run->branch_id);

                $property = $this->resolveProperty($payload, $agencyId, $agentId, $branchId, $row);

                $landlordContactIds = $this->resolveContacts($payload, $row->landlord_match_json ?? [], RentalTakeOnFieldSchema::landlordNumbers(), 'landlord', $agencyId);
                foreach ($landlordContactIds as $contactId) {
                    ContactPropertyLinker::link($contactId, $property->id, 'landlord');
                }

                $tenantContactIds = $this->resolveContacts($payload, $row->tenant_match_json ?? [], RentalTakeOnFieldSchema::tenantNumbers(), 'tenant', $agencyId);

                $leaseType = trim((string) ($payload['lease_type'] ?? ''));
                $isMonthToMonth = $leaseType === RentalTakeOnFieldSchema::LEASE_TYPE_MONTH_TO_MONTH;

                // leases.md §17 — the lease's two agents. The spreadsheet row names the managing agent ($agentId; the person
                // running the import when it names none), so that person stands in for "who created the lease" in the
                // default rules: the owner's agent is the property's agent, the tenant's agent is the row's agent.
                $agents = app(\App\Services\Rentals\LeaseAgentService::class)->defaultsForNewLease($property, null, (int) $agentId);

                $lease = Lease::create([
                    'agency_id' => $agencyId,
                    'branch_id' => $branchId,
                    'property_id' => $property->id,
                    'owner_agent_user_id' => $agents['owner']['id'],
                    'tenant_agent_user_id' => $agents['tenant']['id'],
                    'status' => Lease::STATUS_DRAFT,
                    'rental_amount' => $payload['monthly_rental_amount'] ?? 0,
                    'deposit_amount' => $payload['deposit_held'] ?? null,
                    'start_date' => $payload['lease_start_date'] ?? null,
                    'end_date' => $payload['lease_end_date'] ?? null,
                    'is_month_to_month' => $isMonthToMonth,
                    'source' => Lease::SOURCE_MIGRATED_TAKEON,
                    'created_by_user_id' => $actingUserId,
                    'migrated_from_table' => 'rental_take_on_import_rows',
                    'migrated_from_id' => $row->id,
                    // rental-takeon-import.md §6 — captured AND shown now
                    // (read-only, lease detail screen), never posted to a
                    // ledger (none exists) and never fed into any
                    // calculation (escalation_percent here is a historical
                    // fact about the arrangement the agency inherited, not
                    // a scheduled-escalation feature — see leases.md §3.4
                    // for why that's a materially different, unbuilt thing).
                    'migrated_escalation_percent' => $payload['escalation_percent'] ?? null,
                    'migrated_next_escalation_date' => $payload['next_escalation_date'] ?? null,
                    'migrated_opening_arrears' => $payload['arrears_opening_balance'] ?? null,
                    'migrated_last_inspection_date' => $payload['last_inspection_date'] ?? null,
                ]);

                foreach ($tenantContactIds as $i => $contactId) {
                    LeaseTenant::create([
                        'lease_id' => $lease->id,
                        'contact_id' => $contactId,
                        'is_primary' => $i === 0,
                    ]);
                }

                $activationNote = null;
                if (($payload['lease_start_date'] ?? null) !== null) {
                    try {
                        $this->leaseActivation->activate($lease);
                    } catch (ValidationException $e) {
                        // leases.md §3.5 — a genuine overlap (another row in this
                        // same batch already resolved to this property). The lease
                        // and its contacts are NOT lost — it stays draft, exactly
                        // the "flagged needs completing" behaviour the instruction
                        // asks for, never a failed/half-imported row.
                        $activationNote = collect($e->errors())->flatten()->first() ?? 'Could not activate: another lease is already active on this property.';
                    }
                }

                $row->update([
                    // Overwritten with the REAL outcome, not the dry run's
                    // prediction: promoteToStock() runs its own SECOND,
                    // independent match against the live properties table
                    // (resolvePropertyMatch()) on top of the dry run's
                    // TrackedProperty-only check, so a row the preview
                    // called "create" can still end up reusing an existing
                    // Property at confirm time. The archive decision (§7)
                    // must key off what actually happened, not the preview.
                    'property_match_action' => $property->wasRecentlyCreated ? RentalTakeOnImportRow::ACTION_CREATE : RentalTakeOnImportRow::ACTION_MATCH,
                    'target_property_id' => $property->id,
                    'target_lease_id' => $lease->id,
                    'target_landlord_contact_ids_json' => $landlordContactIds,
                    'target_tenant_contact_ids_json' => $tenantContactIds,
                    'confirmed_at' => now(),
                    'confirmed_by' => $actingUserId,
                    'status' => RentalTakeOnImportRow::STATUS_CONFIRMED,
                    'warnings_json' => $activationNote
                        ? array_values(array_filter(array_merge($row->warnings_json ?? [], [$activationNote])))
                        : $row->warnings_json,
                ]);
            });
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => 'Could not import this row: ' . $e->getMessage()];
        }

        return ['ok' => true, 'message' => null];
    }

    /**
     * @return array{0: int, 1: ?int} [agentUserId, branchId]
     */
    private function resolveAgentAndBranch(array $payload, int $agencyId, int $actingUserId, ?int $runBranchId): array
    {
        $agentId = $actingUserId;
        $agentEmail = trim((string) ($payload['agent_email'] ?? ''));
        if ($agentEmail !== '') {
            $agent = User::withoutGlobalScopes()->where('agency_id', $agencyId)->whereRaw('LOWER(email) = ?', [strtolower($agentEmail)])->first();
            if ($agent) {
                $agentId = $agent->id;
            }
        }

        $branchId = $runBranchId;
        $branchName = trim((string) ($payload['branch'] ?? ''));
        if ($branchName !== '') {
            $branch = Branch::where('agency_id', $agencyId)->whereRaw('LOWER(name) = ?', [strtolower($branchName)])->first();
            if ($branch) {
                $branchId = $branch->id;
            }
        }

        return [$agentId, $branchId];
    }

    private function resolveProperty(array $payload, int $agencyId, int $agentId, ?int $branchId, RentalTakeOnImportRow $row)
    {
        $streetName = trim((string) ($payload['street_name'] ?? ''));
        $suburb = trim((string) ($payload['suburb'] ?? ''));
        $erf = trim((string) ($payload['erf_number'] ?? ''));

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

        $tp = $this->propertyMatcher->matchOrCreate($agencyId, $facts, [
            'type' => 'rental_takeon_import',
            'ref' => "run:{$row->run_id}:row:{$row->id}",
        ]);

        $propertyFields = array_filter([
            'listing_type' => 'rental',
            'branch_id' => $branchId,
        ], static fn ($v) => $v !== null);

        return $this->propertyMatcher->promoteToStock($tp->id, $agentId, $propertyFields, false);
    }

    /**
     * @return array<int, int> contact ids, in column order (index 0 = primary)
     */
    private function resolveContacts(array $payload, array $matchJson, array $numbers, string $prefix, int $agencyId): array
    {
        $contactIds = [];

        foreach ($numbers as $n) {
            $name = trim((string) ($payload["{$prefix}{$n}_name"] ?? ''));
            if ($name === '') {
                continue;
            }

            $existingId = $matchJson[$n]['existing_contact_id'] ?? null;
            if ($existingId) {
                $contactIds[] = (int) $existingId;
                continue;
            }

            $contactIds[] = $this->createContact($payload, $prefix, $n, $agencyId)->id;
        }

        return $contactIds;
    }

    private function createContact(array $payload, string $prefix, int $n, int $agencyId): Contact
    {
        $name = trim((string) ($payload["{$prefix}{$n}_name"] ?? ''));
        $phone = trim((string) ($payload["{$prefix}{$n}_phone"] ?? ''));
        $email = trim((string) ($payload["{$prefix}{$n}_email"] ?? ''));
        $idOrReg = trim((string) ($payload["{$prefix}{$n}_id_or_reg"] ?? ($payload["{$prefix}{$n}_id_number"] ?? '')));

        $isPersonalId = $idOrReg !== '' && ctype_digit($idOrReg) && strlen($idOrReg) === 13;

        $nameParts = preg_split('/\s+/', $name, 2);
        $firstName = $nameParts[0] ?? $name;
        $lastName = $nameParts[1] ?? '';

        $typeName = $prefix === 'landlord' ? 'Landlord' : 'Tenant';
        $contactTypeId = ContactType::where('name', $typeName)->value('id');

        $attributes = [
            'agency_id' => $agencyId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone ?: null,
            'email' => $email ?: null,
            'contact_type_id' => $contactTypeId,
        ];

        if ($isPersonalId) {
            $attributes['id_number'] = $idOrReg;
        } elseif ($idOrReg !== '') {
            $attributes['contact_kind'] = 'entity';
            $attributes['entity_name'] = $name;
            $attributes['entity_reg_no'] = $idOrReg;
        }

        return Contact::create($attributes);
    }
}
