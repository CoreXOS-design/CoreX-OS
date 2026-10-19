<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\RentalOwnerWorkReportedMail;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Audit fix C-C4 (19 Oct 2026) - ONE rule for when the owner hears that the work has been REPORTED finished, whichever route reported it.
 *
 * The two specs disagreed: rental-work-orders.md 14.27 Q11 emailed the landlord the moment the CREW marked the work completed (default ON), while
 * rentals-faults-work-orders.md 22.17 item 6 (Johan, 14 Oct - the later ruling) says a contractor's "Work finished" tells the owner NOTHING until the agent
 * has checked and closed the job. One decision method now serves both routes: {@see tellOwnerWhenReportedFinished()}, driven by the agency's own setting
 * `notify_landlord_on_crew_completion`, whose DEFAULT is now OFF (the later ruling is the default). Off = the owner hears at the agent's close (the final
 * statement) on every route; on = the owner is emailed when the crew or the contractor reports it finished.
 */
class OwnerFinishNotice
{
    public static function tellOwnerWhenReportedFinished(?int $agencyId): bool
    {
        return RentalPortalSetting::notifyLandlordOnCrewCompletionFor($agencyId);
    }

    /** The contractor's "Work finished" - emails the owner(s) only when the agency's rule says to; either way the work order's history says what happened. */
    public function contractorReportedFinished(RentalWorkOrder $wo, string $contractorName): void
    {
        try {
            if (! self::tellOwnerWhenReportedFinished($wo->agency_id)) {
                return;
            }
            $svc = app(RentalWorkOrderService::class);
            $owners = $svc->ownerRecipients($wo);
            if ($owners->isEmpty()) {
                $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => 'note', 'note' => 'Owner not emailed that the work was reported finished - no owner email on file']);

                return;
            }
            $address = $wo->property?->buildDisplayAddress() ?: ('Property #' . $wo->property_id);
            foreach ($owners as $owner) {
                Mail::to($owner->email)->send(new RentalOwnerWorkReportedMail(
                    trim((string) ($owner->first_name ?? '')),
                    'The work at ' . $address . ' has been reported finished',
                    $contractorName . ' has reported the work on "' . $wo->title . '" finished. Your agent is checking it and will close the job.'
                ));
            }
            $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => 'note', 'note' => 'Owner emailed: the work was reported finished by ' . $contractorName]);
        } catch (\Throwable $e) {
            Log::warning('Owner finish notice failed', ['work_order' => $wo->id, 'error' => $e->getMessage()]);
        }
    }
}
