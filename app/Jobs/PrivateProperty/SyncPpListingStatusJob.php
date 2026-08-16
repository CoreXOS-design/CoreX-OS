<?php

namespace App\Jobs\PrivateProperty;

use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AT-68 (WS2) — push a property's lifecycle status to Private Property when it changes.
 *
 * THE WIRE THAT DID NOT EXIST. PropertyObserver fanned a status change out to P24
 * only — it contained zero PrivateProperty references. So when a property went
 * under offer, P24 updated within seconds and PP received nothing at all: the
 * listing kept advertising as plainly "For Sale" until an agent happened to hit
 * "Refresh to portal" by hand.
 *
 * Queued, not inline: PP is SOAP over the public internet (~1–2s per call, and
 * this makes two — push, then read back to verify). An agent saving a property
 * must never wait on a portal, and a portal outage must never fail their save.
 */
class SyncPpListingStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Back off across a portal wobble rather than burning the retries in 3 seconds. */
    public array $backoff = [30, 120];

    public function __construct(public int $propertyId)
    {
    }

    public function handle(PrivatePropertySyndicationService $syndication): void
    {
        $property = Property::withoutGlobalScopes()->find($this->propertyId);

        if (! $property) {
            return; // deleted between dispatch and run — nothing to sync
        }

        // Re-check the guards at RUN time, not just at dispatch time: syndication
        // may have been turned off, or the listing removed from PP, while this job
        // sat in the queue.
        if (! $property->pp_syndication_enabled || ! $property->pp_ref) {
            return;
        }

        $result = $syndication->syncStatus($property);

        if (! ($result['success'] ?? false)) {
            // syncStatus() has already recorded the honest state on the property
            // (pp_syndication_status='error' + pp_last_error). Log and let the
            // queue retry — a portal that accepted-but-did-not-apply may well
            // apply on a second attempt.
            Log::warning("PP status sync unsuccessful for property #{$property->id}", [
                'message' => $result['message'] ?? null,
                'desired' => $result['desired'] ?? null,
                'actual'  => $result['actual'] ?? null,
            ]);

            $this->release(60);
        }
    }
}
