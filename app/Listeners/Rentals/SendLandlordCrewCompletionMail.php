<?php

declare(strict_types=1);

namespace App\Listeners\Rentals;

use App\Events\Rentals\RentalJobCardCrewCompleted;
use App\Jobs\Rentals\SendLandlordCrewCompletionMailJob;
use App\Models\RentalJobCard;

/**
 * .ai/specs/rental-work-orders.md §14.27.1 Q11 / §14.28.4 — the landlord is told
 * when the crew marks a job card's work completed, by the crew link / crew
 * page OR from the uploaded signed copy (the work is done either way) — ONCE
 * per job card, on the FIRST crew completion. Any later crew completion (a
 * corrected signed copy superseding the first, or the other route arriving
 * second) is audited on the card but sends nothing: the landlord has already
 * been told the work is done.
 *
 * "Once" is enforced by claiming `rental_job_cards.landlord_crew_notice_at`
 * with ONE conditional UPDATE — atomic, so two completions racing cannot both
 * win. The claim is made whether or not an email will actually go out (the
 * agency setting may be off, or the landlord may have no address): the first
 * completion is when the landlord is told or not, and a later completion does
 * not reopen that.
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
        $card = $event->jobCard;

        $claimed = RentalJobCard::withoutGlobalScopes()
            ->whereKey($card->getKey())
            ->whereNull('landlord_crew_notice_at')
            ->update(['landlord_crew_notice_at' => now()]);

        if ($claimed === 0) {
            $card->logUpdate('landlord_notified', null, 'Landlord not emailed again — they were already told the crew completed this job');

            return;
        }

        SendLandlordCrewCompletionMailJob::dispatch((int) $card->getKey(), $event->signedByName);
    }
}
