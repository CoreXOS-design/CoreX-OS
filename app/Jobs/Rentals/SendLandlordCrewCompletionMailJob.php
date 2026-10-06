<?php

declare(strict_types=1);

namespace App\Jobs\Rentals;

use App\Mail\Rentals\RentalJobCardCrewCompletedLandlordMail;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\User;
use App\Services\Rentals\RentalMailDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-work-orders.md §14.27.1 Q11 / §14.28 — tell the landlord the
 * crew has marked the work completed. Queued so the crew's phone never waits
 * on SMTP; dispatched by SendLandlordCrewCompletionMail (the domain-event
 * listener — domain events carry readonly state and cannot themselves be
 * queued, so the listener is synchronous and hands plain ids to this job).
 *
 * Gated by the agency setting `notify_landlord_on_crew_completion` (default
 * ON); goes through the agency mailbox path (RentalMailDispatcher), sent AS
 * the property's responsible agent, falling back to the card's creator and
 * then to the shared CoreX mailer. Best-effort: a failed or impossible send is
 * recorded on the card's history and never breaks the crew's completion.
 */
class SendLandlordCrewCompletionMailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $jobCardId,
        public readonly string $signedByName,
    ) {
    }

    public function handle(RentalMailDispatcher $dispatcher): void
    {
        $card = RentalJobCard::withoutGlobalScopes()->withTrashed()->find($this->jobCardId);
        if (! $card) {
            return;
        }

        if (! RentalPortalSetting::notifyLandlordOnCrewCompletionFor($card->agency_id)) {
            return;
        }

        $property = $card->property()->withoutGlobalScopes()->withTrashed()->first();
        $landlord = $property?->landlordContact();
        if (! $landlord || ! $landlord->email) {
            $card->logUpdate('landlord_notified', null, 'Landlord not emailed — no landlord email on file');

            return;
        }

        $agent = ($property->agent_id ? User::withoutGlobalScopes()->find($property->agent_id) : null)
            ?? ($card->created_by_user_id ? User::withoutGlobalScopes()->find($card->created_by_user_id) : null);

        $tz = $card->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');

        try {
            $dispatcher->send($landlord->email, new RentalJobCardCrewCompletedLandlordMail(
                $card,
                (string) $landlord->full_name,
                $this->signedByName,
                now()->setTimezone($tz)->format('j M Y'),
                $agent,
            ));
            $card->logUpdate('landlord_notified', null, 'Landlord emailed: work completed');
        } catch (\Throwable $e) {
            Log::warning('Landlord crew-completion email failed', ['job_card_id' => $card->id, 'error' => $e->getMessage()]);
            $card->logUpdate('landlord_notified', null, 'Landlord email could not be sent');
        }
    }
}
