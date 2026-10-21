<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\Contact;
use App\Models\ContactType;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Models\User;
use App\Services\ContactDuplicateService;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use App\Services\Rentals\LeaseActivationService;
use App\Services\Rentals\LeaseAgentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AT-479 / rental-takeon-import.md R6 — imports the ticked reOS rows. Each row is its own transaction: a failure rolls that
 * row back clean, is written to the row's import_error in plain words, and the others carry on.
 *
 * NOTHING in here mails, messages or notifies anyone (it touches TrackedProperty / Property / Contact / ContactProperty /
 * Lease / LeaseTenant / LeaseEvent only), and a property it creates is marked imported stock so no chase tasks or activity
 * points are raised for it (Property::withoutNewListingSideEffects).
 */
class ReosTenancyConfirmService
{
    /** @var array<int, array<string,int>>  run id => [name key => contact id] created/linked earlier in this process */
    private array $batchContacts = [];

    /** @var array<int,bool>  runs whose earlier-imported contacts have been read back into $batchContacts */
    private array $batchLoaded = [];

    public function __construct(
        private readonly TrackedPropertyMatchOrCreateService $properties,
        private readonly ContactDuplicateService $contacts,
        private readonly LeaseActivationService $activation,
        private readonly LeaseAgentService $leaseAgents,
        private readonly ReosRowResolver $resolver,
    ) {
    }

    /**
     * Imports ticked, unblocked, not-yet-imported rows until $maxRows or $budgetSeconds is reached.
     *
     * @param bool $retryFailed  true on a fresh press of Import (clears the previous failures first); false while the page
     *                           is carrying on through the rest — a row that failed this session is not tried again in a loop
     * @return array{done:int, created:int, updated:int, failed:int, remaining:int}
     */
    public function importChunk(RentalTakeOnImportRun $run, User $actor, bool $retryFailed, int $maxRows = 20, int $budgetSeconds = 25): array
    {
        if ($retryFailed) {
            RentalTakeOnImportRow::query()->where('run_id', $run->id)->where('selected', true)
                ->where('status', '!=', RentalTakeOnImportRow::STATUS_CONFIRMED)->whereNotNull('import_error')->update(['import_error' => null]);
        }

        $started = microtime(true);
        $out = ['done' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0, 'remaining' => 0];

        $query = fn () => RentalTakeOnImportRow::query()
            ->where('run_id', $run->id)->where('selected', true)->where('is_blocked', false)
            ->where('status', RentalTakeOnImportRow::STATUS_PENDING)->whereNull('import_error')
            ->orderBy('row_number');

        foreach ($query()->limit($maxRows)->get() as $row) {
            if ((microtime(true) - $started) > $budgetSeconds) {
                break;
            }
            $r = $this->importRow($row, $actor);
            $out['done']++;
            if (! $r['ok']) {
                $out['failed']++;
            } elseif ($r['action'] === RentalTakeOnImportRow::RESULT_UPDATED) {
                $out['updated']++;
            } else {
                $out['created']++;
            }
        }

        $out['remaining'] = $query()->count();

        $counts = $run->fresh()->counts_json ?? [];
        $counts['imported_created'] = (int) ($counts['imported_created'] ?? 0) + $out['created'];
        $counts['imported_updated'] = (int) ($counts['imported_updated'] ?? 0) + $out['updated'];
        $counts['imported_failed'] = RentalTakeOnImportRow::query()->where('run_id', $run->id)->whereNotNull('import_error')->count();
        $run->update(['counts_json' => $counts]);

        $stillWaiting = RentalTakeOnImportRow::query()->where('run_id', $run->id)->where('status', '!=', RentalTakeOnImportRow::STATUS_CONFIRMED)->exists();
        if (! $stillWaiting) {
            $run->update(['status' => RentalTakeOnImportRun::STATUS_COMPLETED, 'completed_at' => now()]);
        }

        return $out;
    }

    /** @return array{ok:bool, action:?string, message:?string} */
    public function importRow(RentalTakeOnImportRow $row, User $actor): array
    {
        if ($row->status === RentalTakeOnImportRow::STATUS_CONFIRMED) {
            return ['ok' => false, 'action' => null, 'message' => 'Already imported.'];
        }
        $blockers = ReosBlockers::compute($row);
        if ($blockers !== []) {
            $row->forceFill(['import_error' => $blockers[0]['message']])->save();

            return ['ok' => false, 'action' => null, 'message' => $blockers[0]['message']];
        }

        $run = $row->run()->withTrashed()->firstOrFail();
        $memoBefore = $this->batchContacts[$run->id] ?? [];

        try {
            $action = DB::transaction(function () use ($row, $run, $actor) {
                $existing = $this->resolver->existingLease((int) $run->agency_id, (string) $row->external_id);
                if ($existing && $existing->trashed()) {
                    throw new \DomainException('This lease is in CoreX but archived — restore it from the Leases list first.');
                }

                return $existing ? $this->updateLease($row, $run, $existing, $actor) : $this->createLease($row, $run, $actor);
            });
        } catch (ValidationException $e) {
            return $this->fail($row, $run, $memoBefore, collect($e->errors())->flatten()->first() ?? 'CoreX could not accept this lease.');
        } catch (\DomainException $e) {
            return $this->fail($row, $run, $memoBefore, $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $this->fail($row, $run, $memoBefore, 'Something went wrong importing this lease, so nothing was saved for it. It can be tried again; if it keeps failing, tell support (the details are in the log).');
        }

        return ['ok' => true, 'action' => $action, 'message' => null];
    }

    // ── create ─────────────────────────────────────────────────────────────────────────────

    private function createLease(RentalTakeOnImportRow $row, RentalTakeOnImportRun $run, User $actor): string
    {
        $p = $row->payload_json ?? [];
        $d = $row->decisions_json ?? [];
        $agencyId = (int) $run->agency_id;

        $agentId = (int) ($d['agent_user_id'] ?? 0);
        $agent = $agentId > 0 ? User::withoutGlobalScopes()->where('agency_id', $agencyId)->find($agentId) : null;
        if (! $agent) {
            throw new \DomainException('The agent chosen for this lease can no longer be found — pick the agent again.');
        }
        $branchId = $agent->branch_id ?: $run->branch_id;
        if (! $branchId) {
            throw new \DomainException('Neither the agent nor this batch has a branch, and a property needs one.');
        }

        $property = $this->resolveProperty($row, $run, $agent, (int) $branchId);

        $ownerId = $this->resolveParty('owner', $row, $run, (int) $branchId, $actor);
        if ($ownerId !== null) {
            ContactPropertyLinker::link($ownerId, $property->id, 'landlord');
        }
        $tenantId = $this->resolveParty('tenant', $row, $run, (int) $branchId, $actor);

        $end = $p['lease_end_date'] ?? null;
        $lease = Lease::create([
            'agency_id' => $agencyId,
            'branch_id' => $branchId,
            'property_id' => $property->id,
            'owner_agent_user_id' => $agent->id,
            'tenant_agent_user_id' => $agent->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => $p['rent'],
            'deposit_amount' => $p['deposit'] ?? null,
            'start_date' => $p['lease_start_date'],
            'end_date' => $end,
            'is_month_to_month' => $end === null,
            'lease_type' => isset($p['lease_type']) ? mb_substr((string) $p['lease_type'], 0, 40) : null,
            'rollover' => $p['rollover'] ?? null,
            'tenant_wallet_ref' => $d['tenant_wallet_ref'] ?? null,
            'external_source' => Lease::EXTERNAL_SOURCE_REOS,
            'external_id' => $row->external_id,
            'external_url' => $row->external_url,
            'source' => Lease::SOURCE_MIGRATED_TAKEON,
            'created_by_user_id' => $actor->id,
            'migrated_from_table' => 'rental_take_on_import_rows',
            'migrated_from_id' => $row->id,
        ]);

        if ($tenantId !== null) {
            LeaseTenant::firstOrCreate(['lease_id' => $lease->id, 'contact_id' => $tenantId], ['is_primary' => true]);
        }

        $this->event($lease, LeaseEvent::TYPE_LEASE_CREATED, "Imported from reOS (take-on batch #{$run->id}).", $actor, ['source' => 'reos_import', 'run_id' => $run->id, 'row_number' => $row->row_number]);

        $note = $this->activateIfWanted($lease, $p);

        $this->finishRow($row, $property, $lease, RentalTakeOnImportRow::RESULT_CREATED, $note, $actor, null,
            $ownerId !== null ? [$ownerId] : [], $tenantId !== null ? [$tenantId] : []);

        return RentalTakeOnImportRow::RESULT_CREATED;
    }

    private function resolveProperty(RentalTakeOnImportRow $row, RentalTakeOnImportRun $run, User $agent, int $branchId): Property
    {
        $d = $row->decisions_json ?? [];

        // A property the suburb-less lookup found (or the user picked from several): the lease goes on THAT property. Nothing is
        // created or matched again, so it can never turn into a duplicate. The property itself is not changed.
        if (! empty($d['property']['link_id']) && ($d['property']['choice'] ?? null) !== 'new') {
            $linked = Property::withoutGlobalScopes()->where('agency_id', (int) $run->agency_id)->whereNull('deleted_at')->find((int) $d['property']['link_id']);
            if (! $linked) {
                throw new \DomainException('The property chosen for this lease can no longer be found — choose again.');
            }

            return $linked;
        }

        // The SAME facts the preview matched on: the user's suburb/town/province when they gave one (a suburb pick can be what
        // makes CoreX recognise the property, so dropping it here could miss the match and create a duplicate). A property that
        // matched without any suburb simply has none to pass. The suburb control is only offered for a property that did not match.
        $location = $d['property'] ?? [];
        $built = ReosPropertyFacts::build($row->payload_json['address'] ?? null, $location);

        return Property::withoutNewListingSideEffects(function () use ($row, $run, $agent, $branchId, $built, $location) {
            $tp = $this->properties->matchOrCreate((int) $run->agency_id, $built['facts'], [
                'type' => 'rental_takeon_import',
                'ref' => 'reos:' . $row->external_id,
            ]);

            $fields = array_filter([
                'listing_type' => 'rental',
                'branch_id' => $branchId,
                'p24_suburb_id' => $location['suburb_id'] ?? null,
                'p24_city_id' => $location['city_id'] ?? null,
                'p24_province_id' => $location['province_id'] ?? null,
                'suburb' => $location['suburb'] ?? null,
                'town' => $location['town'] ?? null,
                'province' => $location['province'] ?? null,
            ], static fn ($v) => $v !== null && $v !== '');

            return $this->properties->promoteToStock($tp->id, (int) $agent->id, $fields, false);
        });
    }

    /**
     * The contact for the owner / tenant of this row, or null when the user chose to leave them out (or the cell named
     * nobody). Writes the REAL outcome (created / matched) back onto the row's match json, as the archive needs it.
     */
    private function resolveParty(string $party, RentalTakeOnImportRow $row, RentalTakeOnImportRun $run, int $branchId, User $actor): ?int
    {
        $p = $row->payload_json ?? [];
        $decision = ($row->decisions_json ?? [])[$party] ?? ['action' => 'none'];
        $name = trim((string) ($p["{$party}_name"] ?? ''));
        $agencyId = (int) $run->agency_id;
        $matchColumn = $party === 'owner' ? 'landlord_match_json' : 'tenant_match_json';
        $match = $row->{$matchColumn} ?? [];

        if ($name === '' || in_array($decision['action'] ?? 'none', ['none', 'skip'], true)) {
            return null;
        }

        $emails = ReosContactNames::splitValues($p["{$party}_email"] ?? null, true);
        $phones = ReosContactNames::splitValues($p["{$party}_phone"] ?? null, false);
        $key = ReosContactNames::key($name);

        $contactId = null;
        $outcome = 'create';

        if (($decision['action'] ?? null) === 'link') {
            $exists = Contact::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->whereKey((int) ($decision['contact_id'] ?? 0))->exists();
            if (! $exists) {
                throw new \DomainException("The contact chosen for the {$party} can no longer be found — choose again.");
            }
            $contactId = (int) $decision['contact_id'];
            $outcome = 'match';
        } else {
            // Re-match NOW, not only at preview: anything created since — including by an earlier row of this very batch —
            // is found by its phone / e-mail.
            $found = $this->contacts->findDuplicatesForIdentifiers($phones, $emails, null, $agencyId)->first();
            if ($found) {
                $contactId = (int) $found->id;
                $outcome = 'match';
            } elseif (($reuse = $this->batchContact($run, $key, $emails, $phones)) !== null) {
                // No identifier to match on (or none matched): the same name, already brought in by this batch, is the same person.
                $contactId = $reuse;
                $outcome = 'match';
            } else {
                $contactId = $this->createContact($party, $name, $emails, $phones, $agencyId, $branchId, $actor)->id;
            }
        }

        $this->batchContacts[$run->id][$key] = $contactId;

        $match[1] = array_merge($match[1] ?? ['name' => $name], ['action' => $outcome, 'existing_contact_id' => $outcome === 'match' ? $contactId : null]);
        $row->{$matchColumn} = $match;

        return $contactId;
    }

    /** A contact an earlier row of this batch already used for the same name, when nothing contradicts it. */
    private function batchContact(RentalTakeOnImportRun $run, string $key, array $emails, array $phones): ?int
    {
        $id = $this->batchContacts[$run->id][$key] ?? $this->contactFromEarlierRows($run, $key);
        if ($id === null) {
            return null;
        }
        // Different e-mail AND no overlap → treat as a different person with the same name; do not merge.
        if ($emails !== []) {
            $c = Contact::withoutGlobalScopes()->find($id);
            $have = array_filter([strtolower((string) $c?->email)]);
            if ($have !== [] && ! array_intersect($have, array_map('strtolower', $emails))) {
                return null;
            }
        }

        return $id;
    }

    /** After a restart of the process (the next chunk of the same Import) the in-memory map is gone: rebuild it from the rows already imported. */
    private function contactFromEarlierRows(RentalTakeOnImportRun $run, string $key): ?int
    {
        if (isset($this->batchLoaded[$run->id])) {
            return null;
        }
        $this->batchLoaded[$run->id] = true;

        $ids = [];
        RentalTakeOnImportRow::query()->where('run_id', $run->id)->where('status', RentalTakeOnImportRow::STATUS_CONFIRMED)
            ->get(['target_landlord_contact_ids_json', 'target_tenant_contact_ids_json'])
            ->each(function ($r) use (&$ids) {
                foreach (array_merge($r->target_landlord_contact_ids_json ?? [], $r->target_tenant_contact_ids_json ?? []) as $id) {
                    $ids[(int) $id] = true;
                }
            });
        if ($ids === []) {
            return $this->batchContacts[$run->id][$key] ?? null;
        }

        Contact::withoutGlobalScopes()->whereIn('id', array_keys($ids))->get()
            ->each(function (Contact $c) use ($run) {
                $this->batchContacts[$run->id][ReosContactNames::key($c->full_name)] ??= (int) $c->id;
            });

        return $this->batchContacts[$run->id][$key] ?? null;
    }

    private function createContact(string $party, string $name, array $emails, array $phones, int $agencyId, int $branchId, User $actor): Contact
    {
        $c = ReosContactNames::classify($name);

        $attributes = [
            'agency_id' => $agencyId,
            'branch_id' => $branchId,
            'first_name' => $c['first_name'],
            'last_name' => $c['last_name'],
            'phone' => $phones[0] ?? null,
            'email' => $emails[0] ?? null,
            'created_by_user_id' => $actor->id,
        ];
        if ($c['kind'] === 'company') {
            // The same shape ComposeSellerService::resolveOrCreateEntitySellerContact() writes. No registration number is
            // needed (checked: nullable everywhere) — reOS does not carry one.
            $attributes['contact_kind'] = Contact::TYPE_ENTITY;
            $attributes['entity_name'] = $c['entity_name'];
        }
        // A landlord's contact type is set by ContactPropertyLinker::link() when the owner is linked; a tenant gets "Tenant".
        if ($party === 'tenant') {
            $attributes['contact_type_id'] = ContactType::where('name', 'Tenant')->value('id');
        }

        return Contact::create($attributes);
    }

    private function activateIfWanted(Lease $lease, array $payload): ?string
    {
        if (($payload['status_action'] ?? null) !== ReosTenancyScheduleParser::STATUS_ACTIVATE) {
            return null;   // Draft stays draft. Never activated.
        }
        try {
            $this->activation->activate($lease);
        } catch (ValidationException $e) {
            // Another lease is already active on this property: this one stays a draft with the reason on the row, nothing is lost.
            return collect($e->errors())->flatten()->first() ?? 'Could not activate: another lease is already active on this property.';
        }

        return null;
    }

    // ── update (re-import) ─────────────────────────────────────────────────────────────────

    private function updateLease(RentalTakeOnImportRow $row, RentalTakeOnImportRun $run, Lease $lease, User $actor): string
    {
        if ($lease->isLockedForSigning()) {
            throw new \DomainException('This lease\'s agreement is out for signing, so the import does not change it.');
        }
        $p = $row->payload_json ?? [];
        $d = $row->decisions_json ?? [];
        $end = $p['lease_end_date'] ?? null;

        $new = [
            'rental_amount' => $p['rent'],
            'deposit_amount' => $p['deposit'] ?? null,
            'start_date' => $p['lease_start_date'],
            'end_date' => $end,
            'is_month_to_month' => $end === null,
            'lease_type' => isset($p['lease_type']) ? mb_substr((string) $p['lease_type'], 0, 40) : $lease->lease_type,
            'rollover' => $p['rollover'] ?? $lease->rollover,
            'tenant_wallet_ref' => ($d['tenant_wallet_ref'] ?? null) ?: $lease->tenant_wallet_ref,
            'external_url' => $row->external_url ?: $lease->external_url,
        ];

        $changes = [];
        foreach ($new as $field => $value) {
            $from = self::comparable($field, $lease->{$field});
            $to = self::comparable($field, $value);
            if ($from !== $to) {
                $changes[$field] = ['from' => $from, 'to' => $to];
                $lease->{$field} = $value;
            }
        }

        if ($changes !== []) {
            $lease->save();
            $this->event($lease, LeaseEvent::TYPE_LEASE_EDITED, 'Updated from a reOS re-import: ' . implode('; ', array_map(
                fn (string $f, array $c) => str_replace('_', ' ', $f) . ' ' . self::shown($c['from']) . ' → ' . self::shown($c['to']),
                array_keys($changes), $changes
            )) . '.', $actor, ['source' => 'reos_import', 'run_id' => $run->id, 'changes' => $changes]);
        }

        // The agent, when the review screen carried one: the same logged assignment the lease screen uses.
        $agentId = (int) ($d['agent_user_id'] ?? 0);
        if ($agentId > 0) {
            $this->leaseAgents->assign($lease, [LeaseAgentService::SIDE_OWNER => $agentId, LeaseAgentService::SIDE_TENANT => $agentId], $actor);
        }

        // A CoreX draft that reOS now shows as running: activate it, as for a new one. An active lease is never moved back.
        $note = $lease->status === Lease::STATUS_DRAFT ? $this->activateIfWanted($lease, $p) : null;

        $property = Property::withoutGlobalScopes()->withTrashed()->find($lease->property_id);
        $this->finishRow($row, $property, $lease, RentalTakeOnImportRow::RESULT_UPDATED, $note, $actor, $changes === [] ? 'Already up to date.' : null);

        return RentalTakeOnImportRow::RESULT_UPDATED;
    }

    // ── shared ─────────────────────────────────────────────────────────────────────────────

    /** A value in the one shape used to decide "did this change": dates as Y-m-d, money to 2 places, text trimmed, empty = null. */
    private static function comparable(string $field, mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_bool($v) || in_array($field, ['is_month_to_month', 'rollover'], true)) {
            return $v === null ? null : (bool) $v;
        }
        if (in_array($field, ['rental_amount', 'deposit_amount'], true)) {
            return $v === null || $v === '' ? null : round((float) $v, 2);
        }
        $v = is_string($v) ? trim($v) : $v;

        return $v === '' ? null : $v;
    }

    private static function shown(mixed $v): string
    {
        return $v === null ? '—' : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
    }

    /**
     * @param list<int> $landlordIds
     * @param list<int> $tenantIds
     */
    private function finishRow(RentalTakeOnImportRow $row, ?Property $property, Lease $lease, string $result, ?string $note, User $actor, ?string $info = null, array $landlordIds = [], array $tenantIds = []): void
    {
        $warnings = $row->warnings_json ?? [];
        foreach ([$note, $info] as $extra) {
            if ($extra) {
                $warnings[] = $extra;
            }
        }

        $row->forceFill([
            'property_match_action' => $result === RentalTakeOnImportRow::RESULT_UPDATED || ! $property || ! $property->wasRecentlyCreated
                ? RentalTakeOnImportRow::ACTION_MATCH : RentalTakeOnImportRow::ACTION_CREATE,
            'target_property_id' => $property?->id,
            'target_lease_id' => $lease->id,
            'target_landlord_contact_ids_json' => $landlordIds,
            'target_tenant_contact_ids_json' => $tenantIds,
            'warnings_json' => $warnings ?: null,
            'import_error' => null,
            'result_action' => $result,
            'status' => RentalTakeOnImportRow::STATUS_CONFIRMED,
            'confirmed_by' => $actor->id,
            // Stamped LAST (the archive compares updated_at against it — see RentalTakeOnArchiveService).
            'confirmed_at' => now(),
        ])->save();
    }

    /**
     * The row's transaction has rolled back: forget everything this attempt did in memory too (the contacts it created are
     * gone, so a later row must not be pointed at them; the outcomes it wrote onto the row are not true), then say why.
     *
     * @param array<string,int> $memoBefore
     * @return array{ok:bool, action:?string, message:?string}
     */
    private function fail(RentalTakeOnImportRow $row, RentalTakeOnImportRun $run, array $memoBefore, string $message): array
    {
        $this->batchContacts[$run->id] = $memoBefore;
        $row->refresh();
        $row->forceFill(['import_error' => $message])->save();

        return ['ok' => false, 'action' => null, 'message' => $message];
    }

    private function event(Lease $lease, string $type, string $description, User $actor, array $metadata): void
    {
        LeaseEvent::create([
            'lease_id' => $lease->id,
            'event_type' => $type,
            'description' => mb_substr($description, 0, 500),
            'actor_user_id' => $actor->id,
            'metadata' => $metadata,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
