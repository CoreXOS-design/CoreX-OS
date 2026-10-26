<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Models\Agency;
use App\Models\Geocoding\GeocodingCache;
use App\Services\Geocoding\AddressResolverService;
use App\Services\Geocoding\GeocodeRateLimiter;
use App\Support\Geocoding\GeocodingArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The accepted area of AddressResolverService::callGoogle(). Spec: .ai/specs/geocoding-spec.md "Accepted area".
 *
 * It used to be a hard-coded KZN rectangle that threw away every Gauteng / Cape Town result (a Centurion
 * estate never got a pin — 26 Oct 2026). Now:
 *   - a result Google places in the agency's country is ALWAYS kept, whichever province it is in;
 *   - a result outside the country is a definitive failure, cached as google:out_of_bbox;
 *   - the property's province only biases Google (bounds=), it never rejects anything;
 *   - the country is the agency's setting (agencies.geocode_country), default ZA.
 *
 * Also locks the partial_match downgrade (unchanged).
 */
final class AddressResolverAcceptedAreaTest extends TestCase
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
        Config::set('services.google.geocoding_api_key', 'fake-test-key');
        Config::set('services.nominatim.enabled', false);
        GeocodeRateLimiter::releaseRuntimeOverride();
    }

    private function fakeGoogle(float $lat, float $lng, string $formatted, ?string $country = null, string $type = 'APPROXIMATE', bool $partial = false): void
    {
        $components = $country === null ? [] : [['types' => ['country', 'political'], 'short_name' => $country, 'long_name' => $country]];
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status'  => 'OK',
                'results' => [[
                    'partial_match'      => $partial,
                    'formatted_address'  => $formatted,
                    'geometry'           => ['location' => ['lat' => $lat, 'lng' => $lng], 'location_type' => $type],
                    'address_components' => $components,
                ]],
            ], 200),
        ]);
    }

    /** @dataProvider insideSouthAfricaProvider */
    public function test_a_result_inside_south_africa_is_kept_in_every_province(float $lat, float $lng, string $label, bool $withCountry): void
    {
        $this->fakeGoogle($lat, $lng, $label, $withCountry ? 'ZA' : null);

        $result = app(AddressResolverService::class)->resolve('test address', 'SomeSuburb', null);

        $this->assertSame('google', $result->source, "$label: source");
        $this->assertEqualsWithDelta($lat, (float) $result->latitude, 0.0001, "$label: latitude kept");
        $this->assertEqualsWithDelta($lng, (float) $result->longitude, 0.0001, "$label: longitude kept");
        $this->assertSame(1, GeocodingCache::where('address_normalised', 'test address somesuburb')->count(), "$label: cached");
    }

    public static function insideSouthAfricaProvider(): array
    {
        $cases = [
            'Centurion (Blue Valley Golf Estate)' => [-25.9417, 28.1119],
            'Cape Town'                           => [-33.9249, 18.4241],
            'Durban'                              => [-29.8587, 31.0218],
            'Johannesburg'                        => [-26.2041, 28.0473],
            'Margate (KZN South Coast)'           => [-30.8627, 30.3719],
            'Polokwane'                           => [-23.9045, 29.4689],
            'Upington (Northern Cape)'            => [-28.4478, 21.2561],
        ];
        $out = [];
        foreach ($cases as $label => [$lat, $lng]) {
            $out[$label . ' — Google names the country'] = [$lat, $lng, $label, true];
            $out[$label . ' — no country in the answer'] = [$lat, $lng, $label, false];
        }

        return $out;
    }

    /** @dataProvider outsideSouthAfricaProvider */
    public function test_a_result_outside_south_africa_is_rejected_and_cached_as_a_definitive_failure(float $lat, float $lng, string $label, ?string $country): void
    {
        $this->fakeGoogle($lat, $lng, $label, $country);

        $result = app(AddressResolverService::class)->resolve('test address', 'SomeSuburb', null);

        $this->assertSame('failed', $result->confidence, "$label: must fail");
        $this->assertSame('google', $result->source);
        $this->assertSame('google:out_of_bbox', $result->failureReason);

        $row = GeocodingCache::where('address_normalised', 'test address somesuburb')->first();
        $this->assertNotNull($row, "$label: the definitive failure is cached");
        $this->assertSame('google:out_of_bbox', $row->failure_reason);
    }

    public static function outsideSouthAfricaProvider(): array
    {
        return [
            'Windhoek — Google says Namibia'      => [-22.5609, 17.0658, 'Windhoek, Namibia', 'NA'],
            'Windhoek — no country in the answer' => [-22.5609, 17.0658, 'Windhoek', null],
            'Maputo — Google says Mozambique'     => [-25.9692, 32.5732, 'Maputo, Mozambique', 'MZ'],
            'New Zealand outlier'                 => [-37.0766, 174.9500, 'Auckland, New Zealand', null],
        ];
    }

    public function test_the_google_request_asks_for_the_country_and_biases_to_the_whole_country_without_a_province(): void
    {
        $this->fakeGoogle(-25.9417, 28.1119, 'Centurion', 'ZA');

        app(AddressResolverService::class)->resolve('test address', 'Centurion', null);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return ($q['components'] ?? null) === 'country:ZA'
                && ($q['region'] ?? null) === 'za'
                && ($q['bounds'] ?? null) === '-34.95,16.3|-22,33'
                && !str_contains((string) ($q['address'] ?? ''), 'South Africa');
        });
    }

    public function test_the_property_province_biases_the_search_but_never_limits_it(): void
    {
        // A Gauteng address biased to Gauteng …
        $this->fakeGoogle(-25.9417, 28.1119, 'Centurion', 'ZA');
        app(AddressResolverService::class)->resolve('test address', 'Centurion', null, province: 'Gauteng');
        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return ($q['bounds'] ?? null) === '-26.9,27.2|-25.1,29';
        });

        // … and "KwaZulu Natal" (no hyphen, as the data is spelled) finds its box too.
        $this->assertSame('-32,28.5|-27,33', GeocodingArea::forCountry('ZA')->boundsHint('KwaZulu Natal'));
        $this->assertSame('-32,28.5|-27,33', GeocodingArea::forCountry('ZA')->boundsHint('KwaZulu-Natal'));
        // An unknown / blank province falls back to the whole country.
        $this->assertSame('-34.95,16.3|-22,33', GeocodingArea::forCountry('ZA')->boundsHint('Atlantis'));
        $this->assertSame('-34.95,16.3|-22,33', GeocodingArea::forCountry('ZA')->boundsHint(null));

        // A Cape Town result for a property that says Gauteng is still kept (inside South Africa).
        Cache::flush();
        $this->fakeGoogle(-33.9249, 18.4241, 'Cape Town', 'ZA');
        $result = app(AddressResolverService::class)->resolve('another address', 'Somewhere', null, province: 'Gauteng');
        $this->assertTrue($result->hasGps(), 'a province is a hint, never a reason to reject');
    }

    public function test_the_accepted_country_is_the_agencys_setting_with_za_as_the_default(): void
    {
        $plain = Agency::create(['name' => 'Plain Agency', 'slug' => 'plain-' . uniqid()]);
        $this->assertSame('ZA', GeocodingArea::forAgency($plain->id)->country, 'no setting = the config default');
        $this->assertSame('ZA', GeocodingArea::forAgency(null)->country);

        Config::set('geo.geocoding.countries.NA', [
            'google_component' => 'NA',
            'areas'            => [[-29.0, 11.5, -16.9, 25.3]],
            'provinces'        => [],
        ]);
        $nam = Agency::create(['name' => 'Windhoek Homes', 'slug' => 'wh-' . uniqid(), 'geocode_country' => 'na']);
        $area = GeocodingArea::forAgency($nam->id);
        $this->assertSame('NA', $area->country);
        $this->assertSame('country:NA', $area->googleComponent());
        $this->assertTrue($area->accepts(-22.5609, 17.0658, null), 'Windhoek is inside this agency\'s own country');
        $this->assertFalse($area->accepts(-25.9417, 28.1119, 'ZA'), 'and a South African result is outside it');

        // An agency set to a country the system does not know falls back to the default instead of breaking.
        $odd = Agency::create(['name' => 'Odd', 'slug' => 'odd-' . uniqid(), 'geocode_country' => 'XX']);
        $this->assertSame('ZA', GeocodingArea::forAgency($odd->id)->country);
    }

    public function test_partial_match_downgrades_rooftop_confidence_to_suburb(): void
    {
        $this->fakeGoogle(-30.8627, 30.3719, 'Margate, KZN', null, 'ROOFTOP', partial: true);

        $result = app(AddressResolverService::class)->resolve('test address', 'Margate', null);

        $this->assertSame('suburb', $result->confidence, 'partial_match=true must downgrade ROOFTOP precision to suburb');
        $this->assertSame('google', $result->source);
    }

    public function test_no_partial_match_preserves_exact_confidence(): void
    {
        $this->fakeGoogle(-30.8627, 30.3719, 'Margate, KZN', null, 'ROOFTOP');

        $result = app(AddressResolverService::class)->resolve('test address', 'Margate', null);

        $this->assertSame('exact', $result->confidence, 'absent partial_match must NOT downgrade — ROOFTOP stays as exact');
    }
}
