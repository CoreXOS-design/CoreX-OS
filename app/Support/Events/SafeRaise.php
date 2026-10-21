<?php

declare(strict_types=1);

namespace App\Support\Events;

use Illuminate\Support\Facades\Log;

/**
 * Raise a domain event from inside a user action without ever breaking that action.
 *
 * Road to Sold events (road-to-sold.md section 6.2) are observations of something that has
 * ALREADY happened (an outcome saved, a document filed). If recording the observation fails -
 * a listener throws, the event log table is unavailable - the agent's save must still stand.
 * The failure is logged and swallowed; it is never re-thrown.
 *
 * The event is built lazily (inside the try) so a failure to BUILD it - a vanished row, a
 * missing relation - is absorbed the same way.
 */
final class SafeRaise
{
    /** @param callable():object $make builds the event */
    public static function dispatch(string $what, callable $make): void
    {
        try {
            event($make());
        } catch (\Throwable $e) {
            Log::warning("Domain event {$what} could not be raised (action proceeds)", [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
