<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Geocoding\GeocodeRateLimiter;
use App\Services\Geocoding\PropertyGeoBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the property map-pin fix (Phase B):
 *   - force=true overwrites stale GPS coords (previously the !hasGps gate
 *     locked wrong pins in permanently)
 *   - PropertyObserver re-resolves when an address field changes and
 *     lat/lng were NOT updated in the same save
 *   - Observer skips re-resolve when lat/lng was also dirty (frontend /
 *     drag handler / explicit set wins)
 *   - Geocode endpoint persists in saved-record mode (no payload)
 *   - Geocode endpoint does NOT persist in payload mode (in-flight edit)
 */
final class PropertyGeocodeFlowTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('geo.geocoding.enabled', true);
        Config::set('geo.geocoding.admin_override_enabled', false);
        Config::set('geo.geocoding.environment_daily_cap', 100);
        Config::set('geo.geocoding.user_daily_cap', 100);
        Config::set('services.nominatim.enabled', false);
        Config::set('services.google.geocoding_api_key', 'fake-test-key');
        GeocodeRateLimiter::releaseRuntimeOverride();

        $this->agency = Agency::create([
            'name' => 'Geocode Flow Test Agency',
            'slug' => 'geocode-flow-test-' . uniqid(),
        ]);

        $this->branch = Branch::create([
            'agency_id' => $this->agency->id,
            'name'      => 'Main',
        ]);

        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function makeProperty(array $attrs): Property
    {
        return Property::create(array_merge([
            'title'     => 'Test Property',
            'agency_id' => $this->agency->id,
            'agent_id'  => $this->user->id,
            'branch_id' => $this->branch->id,
        ], $attrs));
    }

    /**
     * Build a deterministic Google fake that returns the given pin for any
     * geocode request — the resolver waterfall calls Google with a built
     * address string we don't need to assert against here.
     */
    private function fakeGooglePin(float $lat, float $lng, string $confidence = 'ROOFTOP'): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status'  => 'OK',
                'results' => [[
                    'formatted_address'  => 'Faked, South Africa',
                    'geometry'           => [
                        'location'      => ['lat' => $lat, 'lng' => $lng],
                        'location_type' => $confidence,
                    ],
                    'address_components' => [],
                ]],
            ], 200),
        ]);
    }

    public function test_force_overwrites_existing_coords(): void
    {
        // Property with WRONG legacy coords + low-confidence source.
        $p = $this->makeProperty([
            'street_number' => '60',
            'street_name'   => 'Colin Drive',
            'suburb'        => 'Uvongo Beach',
            'town'          => 'Margate',
            'latitude'      => -30.86417,
            'longitude'     => 30.36861,
            'geo_source'    => 'suburb_centroid',
            'geo_confidence'=> 'low',
        ]);

        // Backend resolver returns the actual building coord.
        $this->fakeGooglePin(-30.830687, 30.398586);

        $svc = app(PropertyGeoBackfillService::class);
        $result = $svc->backfillProperty($p, batchId: null, force: true);

        $this->assertTrue($result['lat_lng_resolved']);
        $p->refresh();
        $this->assertEqualsWithDelta(-30.830687, (float) $p->latitude, 0.0001);
        $this->assertEqualsWithDelta(30.398586, (float) $p->longitude, 0.0001);
        $this->assertSame('google', $p->geo_source);
    }

    public function test_force_preserves_existing_coords_on_resolve_failure(): void
    {
        // Property with already-resolved good coords.
        $p = $this->makeProperty([
            'street_number' => '60',
            'street_name'   => 'Colin Drive',
            'suburb'        => 'Uvongo Beach',
            'latitude'      => -30.830687,
            'longitude'     => 30.398586,
            'geo_source'    => 'google',
            'geo_confidence'=> 'rooftop',
        ]);

        // Google returns a hard failure — transient quota error.
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status'  => 'OVER_QUERY_LIMIT',
                'results' => [],
            ], 200),
        ]);

        $svc = app(PropertyGeoBackfillService::class);
        $svc->backfillProperty($p, batchId: null, force: true);

        // Coords MUST be preserved — a transient resolver failure should
        // not blank out a previously-valid pin.
        $p->refresh();
        $this->assertEqualsWithDelta(-30.830687, (float) $p->latitude, 0.0001);
        $this->assertEqualsWithDelta(30.398586, (float) $p->longitude, 0.0001);
        $this->assertSame('google', $p->geo_source);
    }

    public function test_observer_re_resolves_on_address_change_without_gps_update(): void
    {
        $p = $this->makeProperty([
            'street_number' => '60',
            'street_name'   => 'Colin Drive',
            'suburb'        => 'Uvongo Beach',
            'latitude'      => -30.83,
            'longitude'     => 30.39,
            'geo_source'    => 'google',
        ]);

        $this->fakeGooglePin(-30.82, 30.40);

        // Address change, lat/lng NOT touched → observer should re-resolve.
        $p->street_number = '145';
        $p->save();

        $p->refresh();
        $this->assertEqualsWithDelta(-30.82, (float) $p->latitude, 0.0001);
        $this->assertEqualsWithDelta(30.40,  (float) $p->longitude, 0.0001);
    }

    public function test_observer_skips_re_resolve_when_gps_also_dirty(): void
    {
        $p = $this->makeProperty([
            'street_number' => '60',
            'street_name'   => 'Colin Drive',
            'suburb'        => 'Uvongo Beach',
            'latitude'      => -30.83,
            'longitude'     => 30.39,
            'geo_source'    => 'google',
        ]);

        // If the observer mistakenly re-resolved, this fake would be hit
        // and the coords would be overwritten with the faked values.
        $this->fakeGooglePin(-29.99, 29.99);

        // Address change AND lat/lng updated in same save (frontend resolved
        // ahead of submit, OR user dragged the marker). Observer must skip.
        $p->street_number = '145';
        $p->latitude  = -30.85;
        $p->longitude = 30.41;
        $p->save();

        $p->refresh();
        // Manual lat/lng wins — not the faked re-resolve coords.
        $this->assertEqualsWithDelta(-30.85, (float) $p->latitude, 0.0001);
        $this->assertEqualsWithDelta(30.41,  (float) $p->longitude, 0.0001);
    }

    public function test_geocode_endpoint_payload_mode_does_not_persist(): void
    {
        $p = $this->makeProperty([
            'street_number' => '60',
            'street_name'   => 'Colin Drive',
            'suburb'        => 'Uvongo Beach',
            'latitude'      => null,
            'longitude'     => null,
        ]);

        $this->fakeGooglePin(-30.830687, 30.398586);

        $resp = $this->actingAs($this->user)->postJson("/api/v1/properties/{$p->id}/geocode", [
            'street_number' => '145',
            'street_name'   => 'Marine Drive',
            'suburb'        => 'Uvongo',
        ]);

        $resp->assertOk();
        $resp->assertJsonPath('ok', true);
        $resp->assertJsonPath('persisted', false);
        $resp->assertJsonPath('latitude', -30.830687);
        $resp->assertJsonPath('longitude', 30.398586);

        // The property record itself must NOT have been touched.
        $p->refresh();
        $this->assertNull($p->latitude);
        $this->assertNull($p->longitude);
    }

    public function test_geocode_endpoint_saved_record_mode_persists(): void
    {
        $p = $this->makeProperty([
            'street_number' => '60',
            'street_name'   => 'Colin Drive',
            'suburb'        => 'Uvongo Beach',
            'latitude'      => null,
            'longitude'     => null,
        ]);

        $this->fakeGooglePin(-30.830687, 30.398586);

        $resp = $this->actingAs($this->user)->postJson("/api/v1/properties/{$p->id}/geocode", []);

        $resp->assertOk();
        $resp->assertJsonPath('ok', true);
        $resp->assertJsonPath('persisted', true);

        $p->refresh();
        $this->assertEqualsWithDelta(-30.830687, (float) $p->latitude, 0.0001);
        $this->assertEqualsWithDelta(30.398586,  (float) $p->longitude, 0.0001);
        $this->assertSame('google', $p->geo_source);
    }
}
