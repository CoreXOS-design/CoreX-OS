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
    public function tenantLeaseIds(Contact $contact): array
    {
        $ids = LeaseTenant::query()->where('contact_id', $contact->id)->pluck('lease_id')->all();
        if (!$ids) {
            return [];
        }

        return Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();
    }

    public function isTenant(Contact $contact): bool
    {
        return LeaseTenant::query()->where('contact_id', $contact->id)->exists();
    }

    public function landlordPropertyIds(Contact $contact): array
    {
        return $contact->properties()
            ->wherePivotIn('role', ['landlord', 'lessor'])
            ->pluck('properties.id')
            ->all();
    }

    public function isLandlord(Contact $contact): bool
    {
        return $contact->properties()->wherePivotIn('role', ['landlord', 'lessor'])->exists();
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

    public function tenantInspections(Contact $contact)
    {
        return RentalInspection::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('lease_id', $this->tenantLeaseIds($contact))
            ->orderByDesc('id')
            ->get();
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
            ->whereHas('contacts', fn ($q) => $q->where('contacts.id', $contact->id))
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
        return RentalFaultReport::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->find($faultReportId);
    }

    /** Live fault reports and work orders waiting on THIS landlord's decision (the "needs my decision" list). */
    public function landlordPendingFaultReports(Contact $contact)
    {
        return RentalFaultReport::withoutGlobalScopes()
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

    public function landlordFaultReports(Contact $contact)
    {
        return RentalFaultReport::withoutGlobalScopes()
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

    public function landlordInspections(Contact $contact)
    {
        return RentalInspection::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    public function landlordDocuments(Contact $contact)
    {
        return Document::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('documents.deleted_at')
            ->where('landlord_portal_visible', true)
            ->whereHas('contacts', fn ($q) => $q->where('contacts.id', $contact->id))
            ->orderByDesc('id')
            ->get();
    }

    // ── Job cards — rental-work-orders.md §14.29 ────────────────────────
    // A tenant sees cards on THEIR OWN lease(s); a landlord sees cards on THEIR
    // OWN property(ies). Global scopes are stripped (a ClientUser request has no
    // staff user), which also strips SoftDeletes — so archived cards are
    // excluded explicitly, and agency_id is pinned manually. A draft card (the
    // office's unfinished prep) is never shown to either party.

    private function clientJobCardQuery(Contact $contact)
    {
        return RentalJobCard::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereNull('deleted_at')
            ->whereNotIn('status', RentalJobCardClientViewService::CLIENT_HIDDEN_STATUSES);
    }

    public function tenantJobCards(Contact $contact)
    {
        return $this->clientJobCardQuery($contact)
            ->whereIn('lease_id', $this->tenantLeaseIds($contact))
            ->with('property')
            ->orderByDesc('id')
            ->get();
    }

    public function tenantJobCard(Contact $contact, int $jobCardId): ?RentalJobCard
    {
        return $this->clientJobCardQuery($contact)
            ->whereIn('lease_id', $this->tenantLeaseIds($contact))
            ->find($jobCardId);
    }

    public function landlordJobCards(Contact $contact)
    {
        return $this->clientJobCardQuery($contact)
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->with('property')
            ->orderByDesc('id')
            ->get();
    }

    public function landlordJobCard(Contact $contact, int $jobCardId): ?RentalJobCard
    {
        return $this->clientJobCardQuery($contact)
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->find($jobCardId);
    }
}
