<?php

declare(strict_types=1);

namespace App\Listeners\Deal;

use App\Events\AbstractDomainEvent;
use Illuminate\Support\Facades\Log;

/**
 * Pillar paper-trail listener. Fires on every Deal\* event and writes a
 * structured Log::info line for operator-visible diagnostics. The wildcard
 * domain_event_log captures full payload; this is the lightweight tail-able
 * trail per CLAUDE.md Non-Negotiable #9.
 */
class LogDealEvent
{
    public function handle(AbstractDomainEvent $event): void
    {
        Log::info('event fired', [
            'pillar' => 'Deal',
            'event' => get_class($event),
            'event_id' => $event->eventId,
            'agency_id' => $event->agencyId(),
            'subject' => $event->subject(),
            'context' => $event->context(),
        ]);
    }
}
