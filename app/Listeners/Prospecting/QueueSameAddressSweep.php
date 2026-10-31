<?php

declare(strict_types=1);

namespace App\Listeners\Prospecting;

use App\Jobs\Prospecting\RunSameAddressSweepJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AT-520 step 4.2: a property file was created, enriched or given an address by ANY ingress (extension import, deeds capture, CMA import,
 * manual entry - they all go through the one door), so the agency's same-address sweep is due. Cheap by design (one cache key per agency);
 * failure-isolated - a failure here never blocks the save that raised the event.
 */
final class QueueSameAddressSweep
{
    public function handle(object $event): void
    {
        try {
            $agencyId = method_exists($event, 'agencyId') ? $event->agencyId() : null;
            if ($agencyId) {
                RunSameAddressSweepJob::schedule((int) $agencyId);
            }
        } catch (Throwable $e) {
            Log::warning('same-address sweep scheduling failed (swallowed)', ['message' => $e->getMessage()]);
        }
    }
}
