<?php

namespace App\Services\Rentals;

use App\Models\Contact;
use App\Models\Document;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalInventory;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;

/**
 * .ai/specs/rental-portal-access.md §6 — AT-445. THE scoping layer for the
 * tenant/landlord portal: every single-record lookup goes through here,
 * never a bare `Model::find($id)`. Mirrors ClientPortalController's own
 * resolveContact()/authorizeMatch() pattern — AgencyScope is NOT applied
 * automatically for a ClientUser-authenticated request (it resolves off
 * the staff `Auth::user()`, which a ClientUser request never is), so every
 * method here explicitly strips it and filters by `agency_id` manually,
 * exactly like ClientAuthService's own sanctioned bypasses.
 *
 * withoutGlobalScopes() also strips SoftDeletes, so EVERY query here pins
 * `deleted_at IS NULL` explicitly: an archived record is neither listed nor
 * openable by id from the portal — it 404s exactly like an out-of-scope one.
 *
 * A Contact passed in here is always the one already resolved for the
 * caller's own current agency (ClientPortalController::resolveContact()
 * equivalent) — never a client-supplied id.
 */
class RentalPortalScopeService
{
    /**
     * The tenant's own live leases. An archived lease is not a lease the portal
     * can see, so every tenant lookup below that keys off these ids (lease,
     * faults, work orders, inspections, inventories, job cards) drops with it.
     */
    /**
     * Every contact record of THIS person in this agency. One login (ClientUser) can carry more than one contact —
     * a person who is tenant on one lease and landlord of another property under two contact records, or a duplicate
     * record — and the portal must show all of it under the one login, not just whichever record sorts first.
     * A contact with no login is only itself. rental-portal-access.md §16.
     *
     * @return array<int,int>
     */
    public function personContactIds(Contact $contact): array
    {
        if (! $contact->client_user_id) {
            return [$contact->id];
        }

        $ids = Contact::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('agency_id', $contact->agency_id)
            ->where('client_user_id', $contact->client_user_id)
            ->pluck('id')
            ->all();

        return $ids === [] ? [$contact->id] : $ids;
    }

    public function tenantLeaseIds(Contact $contact): array
    {
        $ids = LeaseTenant::query()->whereIn('contact_id', $this->personContactIds($contact))->pluck('lease_id')->all();
        if (!$ids) {
            return [];
        }

        // A DRAFT lease is the agent's working copy (terms still being changed, not yet signed): the portal shows a
        // tenancy only once it is live. Every tenant-side query in this service goes through this method.
        return Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->where('status', '!=', Lease::STATUS_DRAFT)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();
    }

    public function isTenant(Contact $contact): bool
    {
        return LeaseTenant::query()->whereIn('contact_id', $this->personContactIds($contact))->exists();
    }

    public function landlordPropertyIds(Contact $contact): array
    {
        return $this->personContacts($contact)
            ->flatMap(fn (Contact $c) => $c->properties()->wherePivotIn('role', ['landlord', 'lessor'])->pluck('properties.id'))
            ->unique()
            ->values()
            ->all();
    }

    public function isLandlord(Contact $contact): bool
    {
        return $this->personContacts($contact)
            ->contains(fn (Contact $c) => $c->properties()->wherePivotIn('role', ['landlord', 'lessor'])->exists());
    }

    /** @return \Illuminate\Support\Collection<int,Contact> */
    private function personContacts(Contact $contact): \Illuminate\Support\Collection
    {
        $ids = $this->personContactIds($contact);
        if ($ids === [$contact->id]) {
            return collect([$contact]);
        }

        return Contact::withoutGlobalScopes()->whereIn('id', $ids)->get();
    }

    /** The one lease this tenant is asking about, or null if it isn't theirs. */
    public function tenantLease(Contact $contact, int $leaseId): ?Lease
    {
        if (!in_array($leaseId, $this->tenantLeaseIds($contact), true)) {
            return null;
        }

        return Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->find($leaseId);
    }

    /** Every lease this tenant is a party to, newest first. */
    public function tenantLeases(Contact $contact)
    {
        return Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('id', $this->tenantLeaseIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    /** The property id(s) of every lease this tenant is a party to. */
    public function tenantPropertyIds(Contact $contact): array
    {
        return Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('id', $this->tenantLeaseIds($contact))
            ->pluck('property_id')
            ->unique()
            ->all();
    }

    public function tenantOwnsProperty(Contact $contact, int $propertyId): bool
    {
        return in_array($propertyId, $this->tenantPropertyIds($contact), true);
    }

    /** One of the tenant's own live properties (via a live lease), or null — archived properties 404 too. */
    public function tenantProperty(Contact $contact, int $propertyId): ?\App\Models\Property
    {
        if (!$this->tenantOwnsProperty($contact, $propertyId)) {
            return null;
        }

        return \App\Models\Property::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->find($propertyId);
    }

    /** The tenant's own live lease on this property, for attaching a new fault report. */
    public function tenantLeaseIdForProperty(Contact $contact, int $propertyId): ?int
    {
        return Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->where('property_id', $propertyId)
            ->whereIn('id', $this->tenantLeaseIds($contact))
            ->value('id');
    }

    public function tenantFaultReport(Contact $contact, int $faultReportId): ?RentalFaultReport
    {
        $leaseIds = $this->tenantLeaseIds($contact);

        return RentalFaultReport::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('lease_id', $leaseIds)
            ->find($faultReportId);
    }

    public function tenantFaultReports(Contact $contact)
    {
        return RentalFaultReport::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('lease_id', $this->tenantLeaseIds($contact))
            ->orderByDesc('reported_at')
            ->get();
    }

    public function tenantWorkOrder(Contact $contact, int $workOrderId): ?RentalWorkOrder
    {
        return RentalWorkOrder::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('lease_id', $this->tenantLeaseIds($contact))
            ->find($workOrderId);
    }

    /**
     * The tenant's own inspections THE PORTAL MAY SHOW: ones that have been sent, and future booked dates. Never a draft,
     * one in progress or still in signing, or a cancelled one (see inspectionPortalState()).
     */
    public function tenantInspections(Contact $contact)
    {
        return RentalInspection::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('lease_id', $this->tenantLeaseIds($contact))
            ->orderByDesc('id')
            ->get()
            ->filter(fn (RentalInspection $i) => $this->inspectionPortalState($i) !== null)
            ->values();
    }

    public const INSPECTION_SENT = 'sent';
    public const INSPECTION_SCHEDULED = 'scheduled';

    /**
     * rental-portal-access.md §20 — the ONE rule for which inspections a tenant or owner may see at all:
     *   - SENT: cc6's definition, RentalInspection::isDistributed() (completed, or a copy logged as sent to a tenant / landlord);
     *   - SCHEDULED: a booked date today or later whose recording has not reached signing (draft or in progress), shown as a
     *     date and a type only — nothing recorded is ever exposed;
     *   - anything else — a draft with no date, one in signing that is not yet sent, a booked date that passed without a
     *     report, a cancelled one — is nothing the portal shows (null).
     * The portal keeps no definition of "sent" of its own.
     */
    public function inspectionPortalState(RentalInspection $inspection): ?string
    {
        if ($inspection->trashed() || $inspection->status === RentalInspection::STATUS_CANCELLED) {
            return null;
        }
        if ($inspection->isDistributed()) {
            return self::INSPECTION_SENT;
        }
        if (in_array($inspection->status, [RentalInspection::STATUS_DRAFT, RentalInspection::STATUS_IN_PROGRESS], true)
            && $inspection->scheduled_for
            && $inspection->scheduled_for->copy()->startOfDay()->gte(now()->startOfDay())) {
            return self::INSPECTION_SCHEDULED;
        }

        return null;
    }

    public function tenantInventories(Contact $contact)
    {
        return RentalInventory::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('lease_id', $this->tenantLeaseIds($contact))
            ->get();
    }

    /**
     * A document is tenant-visible only if it is BOTH flagged
     * tenant_portal_visible AND attached (document_contacts) to this exact
     * Contact — never a blanket "any document on the property", which
     * would leak a previous tenant's paperwork to the current one.
     */
    public function tenantDocuments(Contact $contact)
    {
        return Document::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('documents.deleted_at')
            ->where('tenant_portal_visible', true)
            ->whereHas('contacts', fn ($q) => $q->whereIn('contacts.id', $this->personContactIds($contact)))
            ->orderByDesc('id')
            ->get();
    }

    public function landlordProperty(Contact $contact, int $propertyId): ?\App\Models\Property
    {
        if (!in_array($propertyId, $this->landlordPropertyIds($contact), true)) {
            return null;
        }

        return \App\Models\Property::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->find($propertyId);
    }

    public function landlordProperties(Contact $contact)
    {
        return \App\Models\Property::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('id', $this->landlordPropertyIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    /** Every lease (current + past) on properties this contact landlords. */
    public function landlordLeases(Contact $contact)
    {
        return Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->where('status', '!=', Lease::STATUS_DRAFT)
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    /** Every live lease (current + past) on ONE of the landlord's own properties. */
    public function landlordPropertyLeases(Contact $contact, int $propertyId)
    {
        return Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->where('status', '!=', Lease::STATUS_DRAFT)
            ->where('property_id', $propertyId)
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    /** The live active lease on one of the landlord's own properties, for attaching a new fault report. */
    public function landlordActiveLeaseId(Contact $contact, int $propertyId): ?int
    {
        return Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->where('property_id', $propertyId)
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->where('status', Lease::STATUS_ACTIVE)
            ->value('id');
    }

    public function landlordFaultReport(Contact $contact, int $faultReportId): ?RentalFaultReport
    {
        // Fault flow F2: an unsent fault does not exist as far as the owner is concerned (visibleToOwner()).
        return RentalFaultReport::withoutGlobalScopes()->visibleToOwner()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->find($faultReportId);
    }

    /** Live fault reports and work orders waiting on THIS landlord's decision (the "needs my decision" list). */
    public function landlordPendingFaultReports(Contact $contact)
    {
        return RentalFaultReport::withoutGlobalScopes()->visibleToOwner()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->where('owner_approval_status', RentalFaultReport::APPROVAL_PENDING)
            ->get();
    }

    public function landlordPendingWorkOrders(Contact $contact)
    {
        return RentalWorkOrder::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->where('owner_approval_status', RentalWorkOrder::APPROVAL_PENDING)
            ->get();
    }

    /** BUILD 2 (§17.7.4) — requests for extra work waiting for THIS owner's decision (their properties only; explicit agency + soft-delete filters). */
    public function landlordPendingVariations(Contact $contact)
    {
        return \App\Models\RentalWorkOrderVariation::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->where('status', \App\Models\RentalWorkOrderVariation::STATUS_AWAITING_OWNER)
            ->whereIn('rental_work_order_id', RentalWorkOrder::withoutGlobalScopes()
                ->where('agency_id', $contact->agency_id)->whereNull('deleted_at')
                ->whereIn('property_id', $this->landlordPropertyIds($contact))->select('id'))
            ->orderBy('id')
            ->get();
    }

    /** BUILD 2 — one variation on THIS owner's properties, or null (never another owner's, another agency's, or an archived work order's). */
    public function landlordVariation(Contact $contact, int $variationId): ?\App\Models\RentalWorkOrderVariation
    {
        return $this->landlordPendingVariationsQuery($contact)->find($variationId);
    }

    private function landlordPendingVariationsQuery(Contact $contact)
    {
        return \App\Models\RentalWorkOrderVariation::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('rental_work_order_id', RentalWorkOrder::withoutGlobalScopes()
                ->where('agency_id', $contact->agency_id)->whereNull('deleted_at')
                ->whereIn('property_id', $this->landlordPropertyIds($contact))->select('id'));
    }

    public function landlordFaultReports(Contact $contact)
    {
        return RentalFaultReport::withoutGlobalScopes()->visibleToOwner()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->orderByDesc('reported_at')
            ->get();
    }

    public function landlordWorkOrder(Contact $contact, int $workOrderId): ?RentalWorkOrder
    {
        return RentalWorkOrder::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->find($workOrderId);
    }

    public function landlordWorkOrders(Contact $contact)
    {
        return RentalWorkOrder::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    /** The owner's inspections on their own properties THE PORTAL MAY SHOW — the same rule as the tenant's (inspectionPortalState()). */
    public function landlordInspections(Contact $contact)
    {
        return RentalInspection::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->orderByDesc('id')
            ->get()
            ->filter(fn (RentalInspection $i) => $this->inspectionPortalState($i) !== null)
            ->values();
    }

    public function landlordDocuments(Contact $contact)
    {
        return Document::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('documents.deleted_at')
            ->where('landlord_portal_visible', true)
            ->whereHas('contacts', fn ($q) => $q->whereIn('contacts.id', $this->personContactIds($contact)))
            ->orderByDesc('id')
            ->get();
    }

    // BUILD 3 BEGIN — work orders & completion rounds for the tenant (rental-work-orders.md §17.3.5, §17.10.10) ───────
    // The portal's "Jobs" are work orders now. A tenant sees work orders on THEIR OWN live lease(s); a round id (or a work
    // order id) from another tenant, lease or agency resolves to null — a 404 at the controller. Global scopes are
    // stripped (a portal request has no staff user), so agency_id and deleted_at are pinned explicitly.

    public function tenantWorkOrders(Contact $contact)
    {
        return RentalWorkOrder::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('lease_id', $this->tenantLeaseIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    /** The round this tenant may answer: the work order must be theirs, and the round must be its newest. */
    public function tenantCompletionRound(Contact $contact, int $workOrderId, ?int $roundId = null): ?\App\Models\RentalWorkCompletionRound
    {
        $workOrder = $this->tenantWorkOrder($contact, $workOrderId);
        if (! $workOrder) {
            return null;
        }

        $query = \App\Models\RentalWorkCompletionRound::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->where('rental_work_order_id', $workOrder->id);

        return $roundId !== null
            ? $query->whereKey($roundId)->first()
            : $query->orderByDesc('round_no')->first();
    }
    // BUILD 3 END

    // W4 (8 Oct 2026): no job-card readers here any more - the job card is INTERNAL; tenants and owners read WORK ORDERS.
}
