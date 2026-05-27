<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Models\Geocoding\GeocodingCache;
use App\Services\Geocoding\AddressResolverService;
use App\Services\Geocoding\GeocodeRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Verifies the no-cache-on-skipped-resolver contract in
 * AddressResolverService::resolve(). Pre-fix the resolver wrote a
 * 'no source matched' row whenever every upstream branch failed OR was
 * skipped. Post-fix the row is only written when at least one external
 * resolver actually ran — so a future call (once Google / Nominatim
 * comes online) re-runs the waterfall instead of being short-circuited
 * by a stale failure row.
 */
final class AddressResolverNoExternalAttemptedCachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('geo.geocoding.enabled', true);
        Config::set('geo.geocoding.admin_override_enabled', false);
        Config::set('geo.geocoding.environment_daily_cap', 100);
        Config::set('geo.geocoding.user_daily_cap', 100);
        GeocodeRateLimiter::releaseRuntimeOverride();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_no_cache_when_both_external_resolvers_skipped(): void
    {
        // Google: no key. Nominatim: disabled. Both branches skip cleanly.
        Config::set('services.google.geocoding_api_key', null);
        Config::set('geo.geocoding.google_api_key', null);
        Config::set('services.nominatim.enabled', false);

        $service = app(AddressResolverService::class);
        $result  = $service->resolve('', 'PALM BEACH', null);

        $this->assertSame('failed', $result->confidence);
        $this->assertSame('cache', $result->source);
        $this->assertSame('no source matched', $result->failureReason);

        // Critical: no row written. The next attempt — once a real
        // resolver comes online — must re-run the waterfall.
        $this->assertSame(
            0,
            GeocodingCache::where('address_normalised', 'palm beach')->count(),
            'no_external_attempted must not write to geocoding_cache',
        );
    }

    public function test_no_cache_when_google_rate_limited_and_nominatim_disabled(): void
    {
        Config::set('services.google.geocoding_api_key', 'fake-test-key');
        Config::set('services.nominatim.enabled', false);

        // Burn the rate-limit quota so canGeocode() returns false on the
        // resolver's call. The rate-limiter is cache-backed (per
        // GeocodeRateLimiterTest setup); record N + 1 calls.
        Config::set('geo.geocoding.environment_daily_cap', 1);
        $limiter = app(GeocodeRateLimiter::class);
        $limiter->recordCall();
        $this->assertFalse($limiter->canGeocode(), 'precondition: limiter should be blocked');

        // No Http::fake() calls expected — Google branch must skip via the
        // canGeocode() gate, not invoke the HTTP layer.
        Http::preventStrayRequests();

        $service = app(AddressResolverService::class);
        $result  = $service->resolve('', 'PALM BEACH', null);

        $this->assertSame('failed', $result->confidence);
        $this->assertSame('cache', $result->source);
        $this->assertSame(
            0,
            GeocodingCache::where('address_normalised', 'palm beach')->count(),
            'rate-limited Google must not pollute cache',
        );
    }

    public function test_caches_failure_when_google_attempted_and_returns_zero_results(): void
    {
        Config::set('services.google.geocoding_api_key', 'fake-test-key');
        Config::set('services.nominatim.enabled', false);
        Config::set('geo.geocoding.environment_daily_cap', 100);

        // Google ran and returned a definitive ZERO_RESULTS — that's a
        // legitimate failure that SHOULD be cached so the next caller
        // doesn't waste quota on the same upstream definitive no.
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []], 200),
        ]);

        $service = app(AddressResolverService::class);
        $result  = $service->resolve('xyznonexistent address', 'NOWHERE', null);

        $this->assertSame('failed', $result->confidence);
        // Cache row must exist post-call.
        $row = GeocodingCache::where('address_normalised', 'xyznonexistent address nowhere')->first();
        $this->assertNotNull($row, 'attempted-and-failed must write to cache');
        $this->assertSame('failed', $row->confidence);
    }
}
