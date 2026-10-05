<?php

namespace App\Services\Rentals\TakeOnImport;

use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-takeon-import.md §7 — "Archive batch" soft-archives only
 * what THIS import actually CREATED (never a record it merely matched to
 * an existing one) and only while untouched SINCE CONFIRM — compared
 * against the row's own `confirmed_at`, not `created_at`. `created_at`
 * was tried first and rejected: RentalTakeOnConfirmService's own pipeline
 * makes at least one further save() AFTER creating a Lease (activation
 * flips its status, and may also save() the Property) — both part of
 * confirm's own normal work, not an agent's edit — so comparing
 * updated_at to created_at can go wrong (either direction) purely on
 * which second those two writes land in. confirmed_at is stamped LAST,
 * strictly after every write confirm itself makes, so "untouched" =
 * updated_at <= confirmed_at is correct regardless of second-boundary
 * timing; anything an agent touches afterward lands strictly later.
 */
class RentalTakeOnArchiveService
{
    /**
     * @return array{archived: array<string,int>, left_alone_edited: array<string,int>, left_alone_existing: array<string,int>}
     */
    public function archive(RentalTakeOnImportRun $run): array
    {
        $counts = $this->emptyCounts();

        DB::transaction(function () use ($run, &$counts) {
            $rows = $run->rows()->where('status', RentalTakeOnImportRow::STATUS_CONFIRMED)->whereNull('archived_at')->get();

            foreach ($rows as $row) {
                $this->archiveLease($row, $counts);
                $this->archiveProperty($row, $counts);
                $this->archiveContacts($row, $row->target_landlord_contact_ids_json ?? [], 'landlord', $counts);
                $this->archiveContacts($row, $row->target_tenant_contact_ids_json ?? [], 'tenant', $counts);

                $row->update(['archived_at' => now()]);
            }

            $run->delete();
        });

        return $counts;
    }

    public function restore(RentalTakeOnImportRun $run): void
    {
        DB::transaction(function () use ($run) {
            $rows = $run->rows()->whereNotNull('archived_at')->get();

            foreach ($rows as $row) {
                if ($row->target_lease_id) {
                    Lease::withTrashed()->whereKey($row->target_lease_id)->first()?->restore();
                }
                if ($row->target_property_id && $row->property_match_action === RentalTakeOnImportRow::ACTION_CREATE) {
                    Property::withoutGlobalScopes()->withTrashed()->whereKey($row->target_property_id)->first()?->restore();
                }
                $this->restoreContacts($row->target_landlord_contact_ids_json ?? [], $row->landlord_match_json ?? []);
                $this->restoreContacts($row->target_tenant_contact_ids_json ?? [], $row->tenant_match_json ?? []);

                $row->update(['archived_at' => null]);
            }

            $run->restore();
        });
    }

    private function emptyCounts(): array
    {
        return ['archived' => ['leases' => 0, 'properties' => 0, 'contacts' => 0],
            'left_alone_edited' => ['leases' => 0, 'properties' => 0, 'contacts' => 0],
            'left_alone_existing' => ['properties' => 0, 'contacts' => 0]];
    }

    private function archiveLease(RentalTakeOnImportRow $row, array &$counts): void
    {
        $lease = $row->target_lease_id ? Lease::withoutGlobalScopes()->find($row->target_lease_id) : null;
        if (!$lease) {
            return;
        }

        if ($this->untouchedSinceConfirm($lease, $row)) {
            $lease->delete();
            $counts['archived']['leases']++;
        } else {
            $counts['left_alone_edited']['leases']++;
        }
    }

    private function archiveProperty(RentalTakeOnImportRow $row, array &$counts): void
    {
        if (!$row->target_property_id) {
            return;
        }

        if ($row->property_match_action !== RentalTakeOnImportRow::ACTION_CREATE) {
            $counts['left_alone_existing']['properties']++;

            return;
        }

        $property = Property::withoutGlobalScopes()->find($row->target_property_id);
        if (!$property) {
            return;
        }

        // PropertyObserver::deleting() (landed independently of this
        // feature) refuses to soft-delete a Property with an active
        // lease — the SAME property-has-active-lease rule archiveLease()
        // above exists to clear first. It is checked again here,
        // explicitly, because leaseA.delete() and this call are two
        // separate statements: if the lease's own OWN archive was itself
        // skipped (e.g. the lease got touched by something between
        // creation and now, including this feature's own activation
        // save landing in a later second than create()), the property
        // must be left alone too, not crash the whole batch's archive
        // transaction on an uncaught exception.
        if (!$this->untouchedSinceConfirm($property, $row) || $property->blockingActiveLease()) {
            $counts['left_alone_edited']['properties']++;

            return;
        }

        $property->delete();
        $counts['archived']['properties']++;
    }

    private function archiveContacts(RentalTakeOnImportRow $row, array $contactIds, string $prefix, array &$counts): void
    {
        $matchJson = $prefix === 'landlord' ? ($row->landlord_match_json ?? []) : ($row->tenant_match_json ?? []);
        $actionsByPosition = array_values(array_map(fn ($m) => $m['action'] ?? null, $matchJson));

        foreach ($contactIds as $i => $contactId) {
            $action = $actionsByPosition[$i] ?? null;
            if ($action !== 'create') {
                $counts['left_alone_existing']['contacts']++;

                continue;
            }

            $contact = Contact::withoutGlobalScopes()->find($contactId);
            if (!$contact) {
                continue;
            }

            if ($this->untouchedSinceConfirm($contact, $row)) {
                $contact->delete();
                $counts['archived']['contacts']++;
            } else {
                $counts['left_alone_edited']['contacts']++;
            }
        }
    }

    private function restoreContacts(array $contactIds, array $matchJson): void
    {
        $actionsByPosition = array_values(array_map(fn ($m) => $m['action'] ?? null, $matchJson));

        foreach ($contactIds as $i => $contactId) {
            if (($actionsByPosition[$i] ?? null) !== 'create') {
                continue;
            }
            Contact::withoutGlobalScopes()->withTrashed()->whereKey($contactId)->first()?->restore();
        }
    }

    private function untouchedSinceConfirm($model, RentalTakeOnImportRow $row): bool
    {
        if (!$model->updated_at || !$row->confirmed_at) {
            return true;
        }

        return $model->updated_at->lessThanOrEqualTo($row->confirmed_at);
    }
}
