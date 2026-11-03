<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Geocoding\AddressResolverService;
use App\Services\Geocoding\GeocodeRateLimiter;
use App\Support\Geocoding\StreetAddressMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NUMBER-MATCH-FIX (27 Oct 2026). A report's subject coordinates are reused only when the street NUMBER, the
 * street NAME and the SUBURB all match. "14 Manikie St" was pinned exactly on the subject "11 Manikie St"
 * (presentation 231 / report 438 on QA1) and the comparable pool then dropped it as the subject itself.
 * Spec: geocoding-spec.md "Reusing a stored coordinate".
 */
final class ResolverNumberMatchTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT_LAT = -25.9417300;
    private const SUBJECT_LNG = 28.1119200;

    private int $agencyId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('geo.geocoding.enabled', true);
        Config::set('geo.geocoding.admin_override_enabled', false);
        Config::set('geo.geocoding.environment_daily_cap', 100);
        Config::set('geo.geocoding.user_daily_cap', 100);
        Config::set('services.google.geocoding_api_key', null);
        Config::set('geo.geocoding.google_api_key', null);
        Config::set('services.nominatim.enabled', false);
        GeocodeRateLimiter::releaseRuntimeOverride();
        Http::preventStrayRequests();

        $agency = Agency::create(['name' => 'Number Match Test', 'slug' => 'nm-' . uniqid()]);
        $this->agencyId = $agency->id;
        $branch = Branch::withoutAgencyStamping(fn () => Branch::create([
            'agency_id' => $agency->id, 'name' => 'B', 'code' => strtoupper(Str::random(4)), 'is_active' => true,
        ]));
        $this->userId = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id])->id;
    }

    private function seedReport(string $subjectAddress, ?string $suburb = 'Blue Valley Golf Estate'): void
    {
        DB::table('market_reports')->insert([
            'agency_id' => $this->agencyId, 'report_type_id' => null, 'uploaded_by_user_id' => $this->userId,
            'file_path' => 'x.pdf', 'file_name' => 'x.pdf', 'file_hash' => hash('sha256', Str::random(12)),
            'report_date' => now()->toDateString(), 'subject_address' => $subjectAddress, 'source_suburb' => $suburb,
            'subject_latitude' => self::SUBJECT_LAT, 'subject_longitude' => self::SUBJECT_LNG,
            'is_demo' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function resolve(string $address, ?string $suburb = 'Blue Valley Golf Estate')
    {
        return (new AddressResolverService())->resolve($address, $suburb);
    }

    public function test_the_same_street_with_a_different_number_never_inherits_the_subjects_pin(): void
    {
        $this->seedReport('11 Manikie Street, Blue Valley Golf Estate 0187, Centurion');

        $result = $this->resolve('14 Manikie Street');

        $this->assertFalse($result->hasGps(), '14 is not 11: no coordinates may be reused');
        $this->assertNotSame('market_report', $result->source);
    }

    public function test_a_different_number_goes_to_google_and_gets_its_own_coordinates(): void
    {
        $this->seedReport('11 Manikie Street, Blue Valley Golf Estate 0187, Centurion');
        Config::set('services.google.geocoding_api_key', 'fake-test-key');
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status'  => 'OK',
                'results' => [[
                    'formatted_address'  => '14 Manikie St, Centurion',
                    'geometry'           => ['location' => ['lat' => -25.9421111, 'lng' => 28.1125555], 'location_type' => 'ROOFTOP'],
                    'address_components' => [['types' => ['country'], 'short_name' => 'ZA']],
                ]],
            ], 200),
        ]);

        $result = $this->resolve('14 Manikie Street');

        Http::assertSentCount(1);
        $this->assertSame('google', $result->source);
        $this->assertEqualsWithDelta(-25.9421111, (float) $result->latitude, 0.0000001);
        $this->assertEqualsWithDelta(28.1125555, (float) $result->longitude, 0.0000001);
        $this->assertNotEquals(self::SUBJECT_LAT, (float) $result->latitude, 'not the subject\'s pin');
    }

    public function test_the_same_number_street_and_suburb_still_reuses_the_report_coordinates(): void
    {
        $this->seedReport('11 Manikie Street, Blue Valley Golf Estate 0187, Centurion');

        $result = $this->resolve('11 Manikie Street');

        $this->assertTrue($result->hasGps());
        $this->assertEqualsWithDelta(self::SUBJECT_LAT, (float) $result->latitude, 0.0000001);
        $this->assertSame('market_report', $result->source);
        $this->assertSame('exact', $result->confidence);

        // The complex-first form a property's address takes.
        $this->assertSame('market_report', $this->resolve('Blue Valley Golf Estate, 11 Manikie Street')->source);
    }

    public function test_the_suburb_must_match_too(): void
    {
        $this->seedReport('11 Manikie Street, Blue Valley Golf Estate 0187, Centurion');

        $this->assertFalse($this->resolve('11 Manikie Street', 'Somewhere Else')->hasGps(), 'same number + street, other suburb');
        $this->assertFalse($this->resolve('11 Manikie Street', null)->hasGps(), 'no suburb = the third leg cannot be confirmed');
    }

    public function test_an_address_or_a_stored_subject_without_a_number_is_never_reused(): void
    {
        $this->seedReport('11 Manikie Street, Blue Valley Golf Estate 0187, Centurion');
        $this->assertFalse($this->resolve('Manikie Street')->hasGps(), 'no number on the address being resolved');

        DB::table('market_reports')->delete();
        $this->seedReport('Manikie Street, Blue Valley Golf Estate');
        $this->assertFalse($this->resolve('11 Manikie Street')->hasGps(), 'no number on the stored subject');
    }

    public function test_a_number_that_is_only_part_of_a_longer_one_is_not_a_match(): void
    {
        $this->seedReport('111 Manikie Street, Blue Valley Golf Estate');
        $this->assertFalse($this->resolve('11 Manikie Street')->hasGps());
        $this->assertFalse($this->resolve('1 Manikie Street')->hasGps());

        DB::table('market_reports')->delete();
        $this->seedReport('11A Manikie Street, Blue Valley Golf Estate');
        $this->assertFalse($this->resolve('11 Manikie Street')->hasGps(), '11 is not 11A');
        $this->assertTrue($this->resolve('11A Manikie Street')->hasGps());
    }

    public function test_the_rule_itself_for_a_portal_capture_title_and_location(): void
    {
        $parsed = StreetAddressMatch::parse('14 Manikie Street');
        $this->assertSame(['number' => '14', 'street' => 'manikie street'], $parsed);

        // title + location text, the way the portal-capture branch passes them
        $this->assertTrue(StreetAddressMatch::matches('3 Bed House, 14 Manikie Street', $parsed, 'blue valley golf estate', 'Blue Valley Golf Estate, Centurion'));
        $this->assertFalse(StreetAddressMatch::matches('3 Bed House, 11 Manikie Street', $parsed, 'blue valley golf estate', 'Blue Valley Golf Estate, Centurion'));
        $this->assertFalse(StreetAddressMatch::matches('3 Bed House, 14 Manikie Street', $parsed, 'blue valley golf estate', 'Margate'));
        $this->assertNull(StreetAddressMatch::parse('Blue Valley Golf Estate'));
        $this->assertNull(StreetAddressMatch::parse(''));
    }
}
