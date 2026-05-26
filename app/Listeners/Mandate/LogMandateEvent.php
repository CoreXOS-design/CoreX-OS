<?php

declare(strict_types=1);

namespace App\Listeners\Mandate;

use App\Events\AbstractDomainEvent;
use Illuminate\Support\Facades\Log;

class LogMandateEvent
{
    public function handle(AbstractDomainEvent $event): void
    {
        Log::info('event fired', [
            'pillar' => 'Mandate',
            'event' => get_class($event),
            'event_id' => $event->eventId,
            'agency_id' => $event->agencyId(),
            'subject' => $event->subject(),
            'context' => $event->context(),
        ]);
    }
}
