<?php

declare(strict_types=1);

namespace App\Listeners\Rentals;

use App\Events\Rentals\RentalCrewLinesSubmitted;
use App\Models\Property;
use App\Services\CommandCenter\NotificationDispatcher;

/**
 * .ai/specs/rental-work-orders.md §17.5.3 step 2 — the office is told ONCE per "Send to office" (never per line):
 * an in-app note (`rental_job_card.crew_lines_submitted`, registered by the F9 migration) to the property's responsible
 * agent. Skipped, not failed, when the property has no assigned agent — the same treatment RentalFaultReportService
 * gives its own notifications.
 *
 * Synchronous on purpose (like SendLandlordCrewCompletionMail): domain events carry readonly state.
 */
class NotifyAgentOfCrewLines
{
    public function handle(RentalCrewLinesSubmitted $event): void
    {
        $card = $event->jobCard;
        $property = Property::withoutGlobalScopes()->find($card->property_id);
        if (! $property) {
            return;
        }

        // AT-451 round 2: the same people who heard about the fault (both lease agents, else the property's agent, else the
        // branch / office / agency admin). The crew is not staff, so nobody is excluded.
        $recipients = app(\App\Services\Rentals\LeaseAgentService::class)
            ->recipientsForEvent($card->lease, $property, (int) $card->agency_id);

        $pricing = $event->priceRequestId !== null;

        // AT-465 - the alert's subject is THIS send (the crew report), not the card: the dispatcher's 6-hour "same alert about the same
        // subject" cooldown would otherwise swallow the office's alert for the crew's second batch. A card with no report (an older
        // send, or a caller that predates reports) keeps the card as the subject.
        $report = $event->crewReportId ? \App\Models\RentalJobCardCrewReport::withoutGlobalScopes()->find($event->crewReportId) : null;
        $subject = $report ?? $card;
        $found = $report && trim((string) $report->findings) !== ''
            ? ' Crew says: ' . \Illuminate\Support\Str::limit(trim((string) preg_replace('/\s+/u', ' ', (string) $report->findings)), 120)
            : '';

        foreach ($recipients as $agent) {
            app(NotificationDispatcher::class)->fire(
                $agent,
                'rental_job_card.crew_lines_submitted',
                $subject,
                [
                    'title' => \App\Support\RentalAlertTitle::withProperty($pricing ? 'Crew priced the job' : 'Crew sent parts and labour', $property),
                    'body' => $card->title . ': ' . $event->count . ' line' . ($event->count === 1 ? '' : 's') . ' waiting for you to accept and price.' . $found,
                    'action_url' => route('corex.rental-job-cards.show', $card->id),
                    'severity' => 'info',
                    // A discrete event — each send is a new fact, so each one notifies.
                    'threshold_hit_at' => now(),
                ]
            );
        }
    }
}
