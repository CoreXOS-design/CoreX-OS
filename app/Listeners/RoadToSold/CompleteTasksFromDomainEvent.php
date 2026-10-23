<?php

declare(strict_types=1);

namespace App\Listeners\RoadToSold;

use App\Events\Contracts\DomainEvent;
use App\Services\RoadToSold\RecipeCatalogue;
use App\Services\RoadToSold\TaskCompletionService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AT-454 phase 1 - the ONE generic listener that turns domain events into Road to Sold task completions
 * (road-to-sold.md section 5.1). It subscribes to the DomainEvent interface - the same mechanism as the audit logger
 * (RecordDomainEvent) - and looks up which active tasks of the event's agency that event completes. Task definitions are
 * per-agency DATA, not code.
 *
 * Safety (copied from the instant-point listener): it never throws to the caller - a failure here is logged and swallowed, the
 * user's save always stands - and an event with a null actor and no credited agent is simply skipped.
 * Registration is mandatory because listener auto-discovery is off (AppServiceProvider).
 */
class CompleteTasksFromDomainEvent
{
    public function __construct(private readonly TaskCompletionService $completions)
    {
    }

    public function handle(DomainEvent $event): void
    {
        // Cheap exit for the great majority of events: only the events a task can be bound to are looked at.
        if (! isset(RecipeCatalogue::EVENTS[$event::class])) {
            return;
        }

        try {
            $this->completions->completeFromEvent($event);
        } catch (Throwable $e) {
            Log::warning('Road to Sold task completion swallowed an exception', [
                'event'   => $event::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
