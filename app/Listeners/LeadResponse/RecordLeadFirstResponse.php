<?php

declare(strict_types=1);

namespace App\Listeners\LeadResponse;

use App\Events\Contact\ContactContactedByAgent;
use App\Services\LeadResponse\LeadResponseRecorder;

/** Stamps the contact's open portal enquiries with the first genuine response (set once, never overwritten). */
final class RecordLeadFirstResponse
{
    public function __construct(private readonly LeadResponseRecorder $recorder) {}

    public function handle(ContactContactedByAgent $event): void
    {
        $this->recorder->record(
            (int) $event->contact->id,
            $event->at ?? now(),
            $event->actorUserId,
            $event->channel,
        );
    }
}
