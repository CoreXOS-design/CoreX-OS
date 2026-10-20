<?php

declare(strict_types=1);

namespace App\Listeners\Rentals;

use App\Events\Rentals\RentalJobCardCrewCompleted;
use App\Models\Property;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Audit fix C-A3 (19 Oct 2026): the crew marked a job card's work complete (link, crew page or signed copy) - the agent who has to check it and sign it off is told
 * in-app (`rental_job_card.crew_completed`, its OWN type so no other card alert can use up its cooldown slot). Both lease agents, else the property's agent, else the
 * branch / office / agency admin - the same people as every other rentals alert (LeaseAgentService). Synchronous and failure-isolated: a problem here never breaks the
 * crew's completion. The crew's completion also opens the tenant check (OpenCompletionRound); that is a separate listener.
 */
class NotifyAgentOfCrewCompleted
{
    public function handle(RentalJobCardCrewCompleted $event): void
    {
        try {
            $card = $event->jobCard;
            $property = Property::withoutGlobalScopes()->find($card->property_id);
            if (! $property) {
                return;
            }
            $recipients = app(\App\Services\Rentals\LeaseAgentService::class)->recipientsForEvent($card->lease, $property, (int) $card->agency_id);
            $address = $property->buildDisplayAddress() ?: ('Property #' . $property->id);
            foreach ($recipients as $agent) {
                app(NotificationDispatcher::class)->fire($agent, 'rental_job_card.crew_completed', $card, [
                    'title' => \App\Support\RentalAlertTitle::withProperty('Crew marked the job complete', $property),
                    'body' => ($card->title ?: 'Job card') . ' at ' . $address . ': ' . $event->signedByName . ' says the work is done. Check it and sign it off.',
                    'action_url' => route('corex.rental-job-cards.show', $card->id),
                    'severity' => 'info',
                    'threshold_hit_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Crew completion: agent alert failed', ['job_card' => $event->jobCard->id ?? null, 'error' => $e->getMessage()]);
        }
    }
}
