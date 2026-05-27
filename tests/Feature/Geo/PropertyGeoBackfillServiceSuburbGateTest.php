<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Models\Agency;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Geocoding\GeocodeRateLimiter;
use App\Services\Geocoding\PropertyGeoBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies the gate at PropertyGeoBackfillService::backfillTrackedProperty()
 * opens for suburb-only tracked properties (street_number + street_name
 * both empty, but suburb populated). Pre-fix the gate required a non-empty
 * composed street address, which filtered out suburb-only TPs before they
 * ever reached the resolver.
 *
 * Integration-style (not mocked) — exercises the full resolver waterfall
 * with Http::fake() supplying the Google response. AddressResolverService
 * is final so Mockery can't intercept; integration tests are also more
 * faithful to the real code path the operator hits.
 */
final class PropertyGeoBackfillServiceSuburbGateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('geo.geocoding.enabled', true);
        Config::set('geo.geocoding.admin_override_enabled', false);
        Config::set('geo.geocoding.environment_daily_cap', 100);
        Config::set('geo.geocoding.user_daily_cap', 100);
        Config::set('services.nominatim.enabled', false);
        GeocodeRateLimiter::releaseRuntimeOverride();

        $this->agency = Agency::create([
            'name' => 'Suburb Gate Test Agency',
            'slug' => 'suburb-gate-test-' . uniqid(),
        ]);
    }

    public function test_gate_opens_for_suburb_only_tp_and_resolver_is_called(): void
    {
        $tp = TrackedProperty::create([
            'agency_id'     => $this->agency->id,
            'street_number' => '',
            'street_name'   => '',
            'suburb'        => 'Margate',
        ]);

        // Configure Google + fake a suburb-level success response.
        Config::set('services.google.geocoding_api_key', 'fake-test-key');
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status'  => 'OK',
                'results' => [[
                    'formatted_address'  => 'Margate, South Africa',
                    'geometry'           => [
                        'location'      => ['lat' => -30.8627, 'lng' => 30.3719],
                        'location_type' => 'APPROXIMATE',
                    ],
                    'address_components' => [
                        ['types' => ['locality'], 'long_name' => 'Margate'],
                    ],
                ]],
            ], 200),
        ]);

        $service = app(PropertyGeoBackfillService::class);
        $row = $service->backfillTrackedProperty($tp);

        $this->assertTrue($row['lat_lng_resolved'], 'gate must open + resolver must populate GPS');
        $this->assertSame('google', $row['source']);
        $this->assertSame('suburb', $row['confidence']);

        $tp->refresh();
        $this->assertEqualsWithDelta(-30.8627, (float) $tp->latitude, 0.0001);
        $this->assertEqualsWithDelta(30.3719, (float) $tp->longitude, 0.0001);
        $this->assertSame('google', $tp->geo_source);
        $this->assertSame('suburb', $tp->geo_confidence);
        $this->assertNotNull($tp->geo_resolved_at);
    }

    public function test_gate_stays_closed_when_street_and_suburb_both_empty(): void
    {
        $tp = TrackedProperty::create([
            'agency_id'     => $this->agency->id,
            'street_number' => '',
            'street_name'   => '',
            'suburb'        => '',
            'town'          => '',
        ]);

        // No Http::fake() configured — if the resolver were called by
        // mistake the test would hit a real network request and fail
        // (Http::preventStrayRequests below makes that explicit).
        Http::preventStrayRequests();

        $service = app(PropertyGeoBackfillService::class);
        $row = $service->backfillTrackedProperty($tp);

        $this->assertFalse($row['lat_lng_resolved'], 'gate must stay closed with no address data');
        $this->assertNull($row['source']);
        $this->assertNull($row['confidence']);

        $tp->refresh();
        $this->assertNull($tp->latitude);
        $this->assertNull($tp->longitude);
        $this->assertNull($tp->geo_source);
        $this->assertNull($tp->geo_resolved_at);
    }
}
