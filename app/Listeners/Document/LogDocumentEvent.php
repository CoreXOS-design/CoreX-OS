<?php

declare(strict_types=1);

namespace App\Listeners\Document;

use App\Events\AbstractDomainEvent;
use Illuminate\Support\Facades\Log;

class LogDocumentEvent
{
    public function handle(AbstractDomainEvent $event): void
    {
        Log::info('event fired', [
            'pillar' => 'Document',
            'event' => get_class($event),
            'event_id' => $event->eventId,
            'agency_id' => $event->agencyId(),
            'subject' => $event->subject(),
            'context' => $event->context(),
        ]);
    }
}
