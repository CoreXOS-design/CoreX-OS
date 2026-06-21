<?php

declare(strict_types=1);

namespace App\Listeners\Mandate;

use App\Events\Mandate\MandateExpired;
use App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob;
use App\Models\Property;
use Illuminate\Support\Facades\Log;

/**
 * On mandate expiry, take the property OFF every advertising channel (Property24,
 * Private Property, agency website[s]). CoreX has no legal right to advertise a
 * property whose mandate has lapsed (PPRA / Property Practitioners Act 22 of 2019).
 *
 * Kept synchronous and light — it only dispatches DesyndicatePropertyFromPortalsJob
 * (removeFromWebsite: true — an expired mandate must come off the website too),
 * which does the portal I/O on the queue (non-blocking for the 01:00
 * mandates:expire cron, with retries). The job — not this listener — is queued,
 * because MandateExpired's readonly AbstractDomainEvent properties cannot be
 * restored by SerializesModels on a queued listener.
 *
 * Wiring: registered by Laravel's automatic listener discovery (it binds
 * handle()'s type-hinted event). Do NOT add an explicit Event::listen() for this
 * in AppServiceProvider — that double-registers it and it fires twice. See the
 * note next to the Mandate entries in AppServiceProvider::boot().
 *
 * Audit: .ai/audits/mandate-expiry-desyndication-2026-06-20.md
 */
final class DesyndicateExpiredMandate
{
    public function handle(MandateExpired $event): void
    {
        $property = $event->mandate;
        if (! $property instanceof Property) {
            // The only current dispatcher (ExpireMandates) passes a Property.
            // Anything else has no listing to de-advertise — record and bail.
            Log::warning('DesyndicateExpiredMandate: event subject is not a Property', [
                'event_id' => $event->eventId,
                'subject'  => $event->subject(),
            ]);
            return;
        }

        DesyndicatePropertyFromPortalsJob::dispatch($property, removeFromWebsite: true);
    }
}
