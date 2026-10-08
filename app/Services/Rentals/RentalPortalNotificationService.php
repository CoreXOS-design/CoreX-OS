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
                Mail::to($landlord->email)->send(new RentalLandlordDecisionNeededMail($subject, $landlord->first_name ?? '', $landlord->email));
            }
        }
    }

    /** The steps the tenant is told about (Johan, 8 Oct 2026) - the appointment has its own mail; "work completed - please check" is the completion-check mail. */
    public const TENANT_STEPS = ['sent_to_owner', 'owner_approved', 'not_approved', 'completed'];

    /**
     * Tell the tenant where their fault now is - at sent to owner, owner approved / not approved, and work completed, each ONCE
     * (the last step told is remembered on the fault, so a work order completing and then the fault outcome being saved is one
     * mail, not two). Under the agency's existing "notify tenant on status change" setting. The mail carries the progress line's
     * own wording - for a declined fault the neutral "Not approved", never the owner's reason.
     */
    public function notifyTenantStatusChanged(RentalFaultReport $faultReport): void
    {
        if (!RentalPortalSetting::notifyTenantOnStatusChangeFor($faultReport->agency_id)) {
            return;
        }

        $lease = $faultReport->lease;
        if (!$lease) {
            return;
        }

        $fresh = RentalFaultReport::withoutGlobalScopes()->find($faultReport->id) ?? $faultReport;
        $key = app(\App\Services\Rentals\RentalFaultProgressService::class)->currentKey($fresh);
        if (!in_array($key, self::TENANT_STEPS, true) || $fresh->tenant_progress_notified === $key) {
            return;
        }

        foreach ($lease->tenantContacts() as $tenant) {
            if ($tenant->email) {
                Mail::to($tenant->email)->send(new RentalTenantStatusChangeMail($fresh, $tenant->first_name ?? ''));
            }
        }
        RentalFaultReport::withoutGlobalScopes()->whereKey($fresh->id)->update(['tenant_progress_notified' => $key]);
        $faultReport->setAttribute('tenant_progress_notified', $key);
    }
}
