<?php

declare(strict_types=1);

namespace App\Listeners\Prospecting;

use App\Events\Prospecting\BedroomSegmentConfigured;
use App\Events\Prospecting\PriceBandConfigured;
use App\Events\Prospecting\PropertyTypeConfigured;
use App\Events\Prospecting\SuburbMappingChanged;
use App\Events\Prospecting\TownConfigured;
use App\Services\Prospecting\ProspectingConfigurationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clears the ProspectingConfigurationService's per-request cache for an agency
 * whenever any of its configuration tables changes (towns / suburbs / property
 * types / bedroom segments / price bands).
 *
 * Without this, a controller that writes a new town and then re-reads the
 * configuration in the same request would see stale data because the service
 * cached the pre-write state.
 *
 * Sync listener. Per-event runtime is one array unset — well under the spec's
 * 50ms budget per E10.
 *
 * The listener is registered against all 5 events in AppServiceProvider::boot().
 * The shared ProspectingConfigurationService is registered as a singleton in
 * AppServiceProvider::register() so the cache cleared here is the same cache
 * the controller writes to and reads from later in the request.
 *
 * Spec: .ai/specs/prospecting-setup-spec.md S7, Section 8.
 */
final class InvalidateProspectingConfigurationCache
{
    public function __construct(
        private readonly ProspectingConfigurationService $service,
    ) {}

    public function handle(
        TownConfigured|SuburbMappingChanged|PropertyTypeConfigured|BedroomSegmentConfigured|PriceBandConfigured $event
    ): void {
        try {
            $this->service->clearCache($event->agencyId);
        } catch (Throwable $e) {
            // A cache-invalidation failure must not break the user's write.
            // Stale-cache risk is preferable to a 500. Log and move on.
            Log::warning('ProspectingConfigurationService cache invalidation failed', [
                'event_class' => $event::class,
                'agency_id'   => $event->agencyId,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
