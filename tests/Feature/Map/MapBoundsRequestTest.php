<?php

declare(strict_types=1);

namespace Tests\Feature\Map;

use App\Services\Map\MapBoundsRequest;
use Tests\TestCase;

/**
 * Per-layer cap behaviour of MapBoundsRequest::perLayerLimitFor().
 *
 * tracked_properties gets a 3x bump on top of the zoom-aware base, floored
 * at 1000 / ceilinged at 1500. Other layers use the zoom-aware base
 * untouched.
 */
final class MapBoundsRequestTest extends TestCase
{
    public function test_non_tracked_layers_use_zoom_aware_base(): void
    {
        $req = $this->bounds(north: -30.5, south: -30.6, east: 30.4, west: 30.3); // ~0.1° suburb zoom
        $layerCount = 6;
        $base = $req->zoomAwarePerLayerLimit($layerCount);

        $this->assertSame($base, $req->perLayerLimitFor('hfc_listings', $layerCount));
        $this->assertSame($base, $req->perLayerLimitFor('sold_comps', $layerCount));
        $this->assertSame($base, $req->perLayerLimitFor('active_listings', $layerCount));
        $this->assertSame($base, $req->perLayerLimitFor('mic_subjects', $layerCount));
        $this->assertSame($base, $req->perLayerLimitFor('scheme_owners', $layerCount));
    }

    public function test_tracked_properties_at_suburb_zoom_returns_1000_floor(): void
    {
        // Suburb zoom (span < 0.05°) — zoom-aware base = effectiveLimit/layerCount = 2000/6 = 333.
        // Tripled = 999. Floored at 1000.
        $req = $this->bounds(north: -30.50, south: -30.52, east: 30.40, west: 30.38);
        $this->assertSame(1000, $req->perLayerLimitFor('tracked_properties', 6));
    }

    public function test_tracked_properties_at_town_zoom_returns_1500_ceiling(): void
    {
        // Town zoom (0.05° <= span < 0.5°) — zoom-aware base = min(2000/6, 500) = 333.
        // Tripled = 999. Floor 1000 wins.
        // Use a larger box to test the ceiling — if base * 3 > 1500, ceiling clamps.
        // effectiveLimit 9000 + layerCount 1 = base 9000, town-zoom min(9000, 500) = 500,
        // tripled = 1500 → ceiling exactly.
        $req = new MapBoundsRequest(
            north: -30.5, south: -30.6, east: 30.4, west: 30.3,  // ~0.1° town zoom
            layers: ['tracked_properties'], viewMode: 'agent', agencyId: 1,
            limit: 9000,
        );
        $this->assertSame(1500, $req->perLayerLimitFor('tracked_properties', 1));
    }

    public function test_tracked_properties_at_country_zoom_still_returns_1000_floor(): void
    {
        // Region zoom (span >= 0.5°) — zoom-aware base = min(2000/6, 200) = 200.
        // Tripled = 600. Floor 1000 wins so region-zoom queries still get
        // useful coverage of the prospecting pool.
        $req = $this->bounds(north: -27.0, south: -32.0, east: 33.0, west: 28.0); // ~5° country zoom
        $this->assertSame(1000, $req->perLayerLimitFor('tracked_properties', 6));
    }

    public function test_unknown_layer_keys_get_zoom_aware_base(): void
    {
        // Defensive: if a future layer is added but doesn't have its own
        // override, fall back to the zoom-aware base.
        $req = $this->bounds(north: -30.5, south: -30.6, east: 30.4, west: 30.3);
        $base = $req->zoomAwarePerLayerLimit(6);
        $this->assertSame($base, $req->perLayerLimitFor('hypothetical_future_layer', 6));
    }

    private function bounds(float $north, float $south, float $east, float $west): MapBoundsRequest
    {
        return new MapBoundsRequest(
            north: $north, south: $south, east: $east, west: $west,
            layers: ['hfc_listings', 'sold_comps', 'active_listings', 'mic_subjects', 'scheme_owners', 'tracked_properties'],
            viewMode: 'agent',
            agencyId: 1,
        );
    }
}
