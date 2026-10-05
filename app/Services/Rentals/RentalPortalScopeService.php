<?php

namespace App\Services\Rentals;

use App\Models\Contact;
use App\Models\Document;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalInventory;
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
 * A Contact passed in here is always the one already resolved for the
 * caller's own current agency (ClientPortalController::resolveContact()
 * equivalent) — never a client-supplied id.
 */
class RentalPortalScopeService
{
    public function tenantLeaseIds(Contact $contact): array
    {
        return LeaseTenant::query()->where('contact_id', $contact->id)->pluck('lease_id')->all();
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
            ->find($leaseId);
    }

    /** Every lease this tenant is a party to, newest first. */
    public function tenantLeases(Contact $contact)
    {
        return Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('id', $this->tenantLeaseIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    /** The property id(s) of every lease this tenant is a party to. */
    public function tenantPropertyIds(Contact $contact): array
    {
        return Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('id', $this->tenantLeaseIds($contact))
            ->pluck('property_id')
            ->unique()
            ->all();
    }

    public function tenantOwnsProperty(Contact $contact, int $propertyId): bool
    {
        return in_array($propertyId, $this->tenantPropertyIds($contact), true);
    }

    public function tenantFaultReport(Contact $contact, int $faultReportId): ?RentalFaultReport
    {
        $leaseIds = $this->tenantLeaseIds($contact);

        return RentalFaultReport::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('lease_id', $leaseIds)
            ->find($faultReportId);
    }

    public function tenantFaultReports(Contact $contact)
    {
        return RentalFaultReport::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('lease_id', $this->tenantLeaseIds($contact))
            ->orderByDesc('reported_at')
            ->get();
    }

    public function tenantWorkOrder(Contact $contact, int $workOrderId): ?RentalWorkOrder
    {
        return RentalWorkOrder::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('lease_id', $this->tenantLeaseIds($contact))
            ->find($workOrderId);
    }

    public function tenantInspections(Contact $contact)
    {
        return RentalInspection::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('lease_id', $this->tenantLeaseIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    public function tenantInventories(Contact $contact)
    {
        return RentalInventory::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
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
            ->find($propertyId);
    }

    public function landlordProperties(Contact $contact)
    {
        return \App\Models\Property::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('id', $this->landlordPropertyIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    /** Every lease (current + past) on properties this contact landlords. */
    public function landlordLeases(Contact $contact)
    {
        return Lease::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    public function landlordFaultReports(Contact $contact)
    {
        return RentalFaultReport::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->orderByDesc('reported_at')
            ->get();
    }

    public function landlordWorkOrder(Contact $contact, int $workOrderId): ?RentalWorkOrder
    {
        return RentalWorkOrder::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->find($workOrderId);
    }

    public function landlordWorkOrders(Contact $contact)
    {
        return RentalWorkOrder::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    public function landlordInspections(Contact $contact)
    {
        return RentalInspection::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->whereIn('property_id', $this->landlordPropertyIds($contact))
            ->orderByDesc('id')
            ->get();
    }

    public function landlordDocuments(Contact $contact)
    {
        return Document::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->where('landlord_portal_visible', true)
            ->whereHas('contacts', fn ($q) => $q->where('contacts.id', $contact->id))
            ->orderByDesc('id')
            ->get();
    }
}
