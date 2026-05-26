<?php

declare(strict_types=1);

namespace App\Listeners\Contact;

use App\Events\AbstractDomainEvent;
use Illuminate\Support\Facades\Log;

class LogContactEvent
{
    public function handle(AbstractDomainEvent $event): void
    {
        Log::info('event fired', [
            'pillar' => 'Contact',
            'event' => get_class($event),
            'event_id' => $event->eventId,
            'agency_id' => $event->agencyId(),
            'subject' => $event->subject(),
            'context' => $event->context(),
        ]);
    }
}
