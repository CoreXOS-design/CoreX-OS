<?php

declare(strict_types=1);

namespace App\Listeners\Rentals;

use App\Events\Rentals\RentalJobCardCrewCompleted;
use App\Jobs\Rentals\SendLandlordCrewCompletionMailJob;

/**
 * .ai/specs/rental-work-orders.md §14.27.1 Q11 / §14.28 — the landlord is told
 * when the crew marks a job card's work completed, by the crew link OR from
 * the uploaded signed copy (the work is done either way).
 *
 * Synchronous on purpose: domain events carry readonly state and cannot be
 * serialised onto a queue, so this listener only hands plain ids to
 * SendLandlordCrewCompletionMailJob, which does the (queued) work and checks
 * the `notify_landlord_on_crew_completion` setting.
 */
class SendLandlordCrewCompletionMail
{
    public function handle(RentalJobCardCrewCompleted $event): void
    {
        SendLandlordCrewCompletionMailJob::dispatch((int) $event->jobCard->getKey(), $event->signedByName);
    }
}
