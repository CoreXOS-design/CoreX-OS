<?php

declare(strict_types=1);

namespace App\Listeners\Agent;

use App\Events\AbstractDomainEvent;
use Illuminate\Support\Facades\Log;

class LogAgentEvent
{
    public function handle(AbstractDomainEvent $event): void
    {
        Log::info('event fired', [
            'pillar' => 'Agent',
            'event' => get_class($event),
            'event_id' => $event->eventId,
            'agency_id' => $event->agencyId(),
            'subject' => $event->subject(),
            'context' => $event->context(),
        ]);
    }
}
