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
    /**
     * Tell the owner a decision is waiting. $reminderNumber 0 = the first mail, 1 / 2 = the first / second reminder (audit C-A1).
     *
     * Audit C-A1: this used to send NOTHING, silently, when the agency's setting was off or no owner had an email. It now says so: the return value tells the caller
     * and, for a fault, the fault's history carries a line the agent can see ("Owner could not be told - ...").
     *
     * @return array{sent: int, reason: ?string, failed?: int} reason = why nobody was emailed (null when at least one owner was); `failed` = owners whose mail could not be sent while another's was.
     *         Throws when owners have an address but EVERY send failed (audit 2026-10-20 M2: never report a reminder as sent when it was not).
     */
    public function notifyLandlordDecisionNeeded(RentalFaultReport|RentalWorkOrder $subject, int $reminderNumber = 0): array
    {
        $agencyId = $subject->agency_id;
        // AT-508: the owner's phone is told whatever the email setting says - a decision is mandatory news (config/rental-push.php).
        \App\Events\Rentals\RentalOwnerDecisionNeeded::dispatch($subject, $reminderNumber);
        $say = function (string $reason) use ($subject, $reminderNumber): array {
            if ($subject instanceof RentalFaultReport) {
                $subject->recordHistory(\App\Models\RentalFaultReportUpdate::TYPE_OWNER_NOT_TOLD, null, ($reminderNumber > 0 ? 'Owner reminder not sent' : 'Owner could not be told') . ' - ' . $reason);
            }

            return ['sent' => 0, 'failed' => 0, 'reason' => $reason];
        };
        if (!RentalPortalSetting::notifyLandlordOnDecisionNeededFor($agencyId)) {
            return $say('the agency has switched off the email that tells the owner a decision is needed. Phone them.');
        }

        $lease = $subject->lease;
        $landlords = $lease ? $lease->landlordContacts() : collect();
        if ($landlords->isEmpty() && $subject->property) {
            $landlords = $subject->property->contactsForRole('landlord')->merge($subject->property->contactsForRole('lessor'))->unique('id');
        }

        $sent = 0;
        $failed = 0;
        $lastError = null;
        foreach ($landlords as $landlord) {
            if ($landlord->email) {
                // one owner's bad address / transport error must not stop the next owner being told (and must not make a retry email the first one again)
                try {
                    Mail::to($landlord->email)->send(new RentalLandlordDecisionNeededMail($subject, $landlord->first_name ?? '', $landlord->email, $landlord, $reminderNumber));
                    $sent++;
                } catch (\Throwable $e) {
                    $failed++;
                    $lastError = $e;
                }
            }
        }
        if ($sent === 0 && $lastError !== null) {
            throw $lastError;   // nobody could be told: the caller decides (the chase takes its step back and retries)
        }
        if ($sent === 0) {
            return $say($landlords->isEmpty() ? 'no owner is on file for this property. Phone them.' : 'no owner has an email address on file. Phone them.');
        }

        return ['sent' => $sent, 'failed' => $failed, 'reason' => null];
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
        // AT-508: the tenant's phone hears each step once, independent of the email setting below (the push listener de-dupes per fault+step).
        $stepNow = app(\App\Services\Rentals\RentalFaultProgressService::class)->currentKey(RentalFaultReport::withoutGlobalScopes()->find($faultReport->id) ?? $faultReport);
        if (in_array($stepNow, self::TENANT_STEPS, true)) {
            \App\Events\Rentals\RentalTenantFaultStatusChanged::dispatch($faultReport, $stepNow);
        }
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
                Mail::to($tenant->email)->send(new RentalTenantStatusChangeMail($fresh, $tenant->first_name ?? '', $tenant));
            }
        }
        RentalFaultReport::withoutGlobalScopes()->whereKey($fresh->id)->update(['tenant_progress_notified' => $key]);
        $faultReport->setAttribute('tenant_progress_notified', $key);
    }
}
