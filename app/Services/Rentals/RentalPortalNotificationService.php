<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\RentalLandlordDecisionNeededMail;
use App\Mail\Rentals\RentalTenantStatusChangeMail;
use App\Models\RentalFaultReport;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrder;
use Illuminate\Support\Facades\Mail;

/**
 * .ai/specs/rental-portal-access.md §6 — AT-445. Two NEW notifications
 * this stage adds (landlord/tenant are portal identities, not CoreX staff
 * Users, so the existing NotificationDispatcher — keyed off App\Models\
 * User — can't address them). Each is its own agency-level toggle
 * (RentalPortalSetting), default on. "Email on new fault to agent" is
 * deliberately NOT here — that is already live, generic infrastructure
 * (RentalFaultReportService::notifyCreated() -> NotificationDispatcher).
 */
class RentalPortalNotificationService
{
    public function notifyLandlordDecisionNeeded(RentalFaultReport|RentalWorkOrder $subject): void
    {
        $agencyId = $subject->agency_id;
        if (!RentalPortalSetting::notifyLandlordOnDecisionNeededFor($agencyId)) {
            return;
        }

        $lease = $subject instanceof RentalFaultReport ? $subject->lease : $subject->lease;
        $landlords = $lease ? $lease->landlordContacts() : collect();
        if ($landlords->isEmpty() && $subject->property) {
            $landlords = $subject->property->contactsForRole('landlord')->merge($subject->property->contactsForRole('lessor'))->unique('id');
        }

        foreach ($landlords as $landlord) {
            if ($landlord->email) {
                Mail::to($landlord->email)->send(new RentalLandlordDecisionNeededMail($subject, $landlord->first_name ?? ''));
            }
        }
    }

    public function notifyTenantStatusChanged(RentalFaultReport $faultReport): void
    {
        if (!RentalPortalSetting::notifyTenantOnStatusChangeFor($faultReport->agency_id)) {
            return;
        }

        $lease = $faultReport->lease;
        if (!$lease) {
            return;
        }

        foreach ($lease->tenantContacts() as $tenant) {
            if ($tenant->email) {
                Mail::to($tenant->email)->send(new RentalTenantStatusChangeMail($faultReport, $tenant->first_name ?? ''));
            }
        }
    }
}
