<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Geocoding\GeocodeRateLimiter;
use App\Services\Geocoding\PropertyGeoBackfillService;
use App\Services\Geocoding\PropertyReportCoordinates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Report-coordinates fallback + "never attempted cannot recur". Spec: .ai/specs/geocoding-spec.md
 * "Report coordinates" and "Failure is recorded".
 *
 * A property with no location takes it from its OWN agency's imported report (street number + street
 * name + suburb must all match), before any Google call; and a geocode that throws leaves
 * geo_source = 'unresolved' on the property instead of nothing.
 */
final class PropertyReportCoordinatesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private int $userId;
    private int $otherUserId;

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

        [$this->agency, $this->userId] = $this->agencyWithUser('Caprivi Reports');
        [$this->other, $this->otherUserId] = $this->agencyWithUser('Neighbour Agency');
    }

    private function agencyWithUser(string $name): array
    {
        $agency = Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . uniqid()]);
        $branch = Branch::withoutAgencyStamping(fn () => Branch::create([
            'agency_id' => $agency->id, 'name' => $name . ' Branch', 'code' => strtoupper(Str::random(4)), 'is_active' => true,
        ]));
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id]);

        return [$agency, $user->id];
    }

    private function report(Agency $agency, int $userId, string $subjectAddress, string $suburb, float $lat, float $lng): int
    {
        return (int) DB::table('market_reports')->insertGetId([
            'agency_id'           => $agency->id,
            'report_type_id'      => null,
            'uploaded_by_user_id' => $userId,
            'file_path'           => 'test/path.pdf',
            'file_name'           => 'r.pdf',
            'file_hash'           => hash('sha256', Str::random(20)),
            'report_date'         => now()->toDateString(),
            'subject_address'     => $subjectAddress,
            'source_suburb'       => $suburb,
            'subject_latitude'    => $lat,
            'subject_longitude'   => $lng,
            'is_demo'             => false,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    private function attrs(array $over = []): array
    {
        return array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->userId,
            'branch_id'     => User::find($this->userId)->branch_id,
            'external_id'   => 'RC-' . Str::random(8),
            'title'         => '5 Bedroom House in Estate',
            'address'       => 'Blue Valley Golf Estate, 1936 Mininkie Street',
            'street_number' => '1936',
            'street_name'   => 'Mininkie Street',
            'suburb'        => 'Blue Valley Golf Estate',
            'city'          => 'Centurion',
            'province'      => 'Gauteng',
            'property_type' => 'house',
            'status'        => 'active',
            'is_demo'       => false,
        ], $over);
    }

    private function quiet(array $over = []): Property
    {
        return Property::withoutEvents(fn () => Property::create($this->attrs($over)));
    }

    // ── the report-coordinates fallback ─────────────────────────────────

    public function test_a_property_with_no_location_takes_it_from_the_agencys_own_report_without_asking_google(): void
    {
        $this->report($this->agency, $this->userId, '1936 Mininkie Street, Blue Valley Golf Estate 0187, Centurion', 'Blue Valley Golf Estate', -25.9401234, 28.1122334);
        $p = $this->quiet();

        $row = (new PropertyGeoBackfillService())->backfillProperty($p);

        Http::assertNothingSent();
        $p->refresh();
        $this->assertEqualsWithDelta(-25.9401234, (float) $p->latitude, 0.0000001);
        $this->assertEqualsWithDelta(28.1122334, (float) $p->longitude, 0.0000001);
        $this->assertSame('market_report_subject', $p->geo_source);
        $this->assertSame('high', $p->geo_confidence);
        $this->assertTrue($row['lat_lng_resolved']);
        $this->assertSame('market_report_subject', $row['source']);
    }

    public function test_a_property_sitting_at_zero_zero_is_filled_too(): void
    {
        $this->report($this->agency, $this->userId, '1936 Mininkie Street, Blue Valley Golf Estate, Centurion', 'Blue Valley Golf Estate', -25.94, 28.11);
        $p = $this->quiet(['latitude' => 0, 'longitude' => 0, 'geo_source' => 'unresolved', 'geo_confidence' => 'failed']);

        $this->assertTrue((new PropertyReportCoordinates())->fillIfMissing($p));
        $this->assertEqualsWithDelta(-25.94, (float) $p->refresh()->latitude, 0.0001);
    }

    public function test_it_never_borrows_a_neighbours_pin_or_another_agencys_report_and_never_overwrites_a_real_pin(): void
    {
        $svc = new PropertyReportCoordinates();

        // Same street, DIFFERENT house number.
        $this->report($this->agency, $this->userId, '11 Mininkie Street, Blue Valley Golf Estate', 'Blue Valley Golf Estate', -25.9, 28.1);
        // The right address but ANOTHER agency's report.
        $this->report($this->other, $this->otherUserId, '1936 Mininkie Street, Blue Valley Golf Estate', 'Blue Valley Golf Estate', -25.8, 28.0);
        // Number is a substring of a longer one.
        $this->report($this->agency, $this->userId, '11936 Mininkie Street, Blue Valley Golf Estate', 'Blue Valley Golf Estate', -25.7, 27.9);

        $p = $this->quiet();
        $this->assertFalse($svc->fillIfMissing($p), 'no matching report of its own agency = nothing borrowed');
        $this->assertNull($p->refresh()->latitude);

        // Right house, wrong suburb.
        $this->report($this->agency, $this->userId, '1936 Mininkie Street, Somewhere Else, Durban', 'Somewhere Else', -29.8, 31.0);
        $this->assertFalse($svc->fillIfMissing($p));

        // A property with no street number never matches on the street alone.
        $this->report($this->agency, $this->userId, 'Mininkie Street, Blue Valley Golf Estate', 'Blue Valley Golf Estate', -25.6, 27.8);
        $noNumber = $this->quiet(['street_number' => null, 'address' => 'Mininkie Street']);
        $this->assertFalse($svc->fillIfMissing($noNumber));

        // A property that already has a real pin keeps it.
        $this->report($this->agency, $this->userId, '1936 Mininkie Street, Blue Valley Golf Estate', 'Blue Valley Golf Estate', -25.95, 28.12);
        $pinned = $this->quiet(['latitude' => -25.5, 'longitude' => 28.5, 'geo_source' => 'google', 'geo_confidence' => 'exact']);
        $this->assertFalse($svc->fillIfMissing($pinned));
        $this->assertEqualsWithDelta(-25.5, (float) $pinned->refresh()->latitude, 0.0001);
    }

    public function test_creating_a_property_picks_up_the_report_location_through_the_observer(): void
    {
        $this->report($this->agency, $this->userId, '1936 Mininkie Street, Blue Valley Golf Estate 0187, Centurion', 'Blue Valley Golf Estate', -25.9401234, 28.1122334);

        $p = Property::create($this->attrs());

        Http::assertNothingSent();
        $p->refresh();
        $this->assertEqualsWithDelta(-25.9401234, (float) $p->latitude, 0.0000001);
        $this->assertSame('market_report_subject', $p->geo_source);
    }

    // ── failure is recorded ─────────────────────────────────────────────

    public function test_an_address_nothing_can_resolve_is_marked_unresolved_not_left_blank(): void
    {
        // No report, no key: the resolver answers "failed" — the property must say so.
        $p = Property::create($this->attrs(['external_id' => 'RC-fail', 'street_number' => '9', 'address' => '9 Nowhere Street']));

        $p->refresh();
        $this->assertSame('unresolved', $p->geo_source);
        $this->assertSame('failed', $p->geo_confidence);
        $this->assertNotNull($p->geo_resolved_at);
    }

    public function test_when_the_geocode_itself_throws_the_property_is_still_marked_unresolved(): void
    {
        // A key is present, so the resolver asks the rate limiter — which blows up. The observer's
        // catch used to swallow this with a log line and leave geo_source NULL ("never attempted").
        Config::set('services.google.geocoding_api_key', 'fake-test-key');
        $this->app->bind(GeocodeRateLimiter::class, function () {
            throw new \RuntimeException('rate limiter unavailable');
        });

        $p = Property::create($this->attrs(['external_id' => 'RC-throw', 'street_number' => '10', 'address' => '10 Nowhere Street']));

        $p->refresh();
        $this->assertNull($p->latitude);
        $this->assertSame('unresolved', $p->geo_source, 'a thrown geocode is recorded, never silent');
        $this->assertSame('failed', $p->geo_confidence);
        $this->assertNotNull($p->geo_resolved_at);
    }

    public function test_recording_a_failure_never_blanks_a_property_that_has_a_real_pin(): void
    {
        $p = $this->quiet(['latitude' => -25.5, 'longitude' => 28.5, 'geo_source' => 'google', 'geo_confidence' => 'exact']);

        PropertyGeoBackfillService::recordFailure($p, 'test');

        $p->refresh();
        $this->assertSame('google', $p->geo_source);
        $this->assertEqualsWithDelta(-25.5, (float) $p->latitude, 0.0001);
    }
}
