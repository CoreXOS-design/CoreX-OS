<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Geocoding\GeocodingCache;
use App\Models\Property;
use App\Models\User;
use App\Services\Geocoding\AddressResolverService;
use App\Services\Geocoding\GeocodeRateLimiter;
use App\Services\Geocoding\PropertyGeoBackfillService;
use App\Support\Geocoding\AddressNormaliser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GPS-BORROW-FIX (2026-06-18) — property 771 (Duke Road, Margate) rendered
 * ~1.2 km off because AddressResolverService's market-report branch matched on
 * a suburb-only OR-clause and borrowed a sibling report's (Acacia Road) subject
 * GPS. These tests lock the three-part fix:
 *   (ii) suburb alone never borrows a pin; an address-needle match is required.
 *   (i)  an address match beats a higher-id same-suburb sibling (precedence).
 *   guard a suspect pin self-heals, bypasses the poisoned cache, and a higher-
 *        confidence source overwrites a lower one without ever downgrading.
 */
final class SubjectGpsBorrowFixTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // No Google key + Nominatim disabled → the only resolution path under
        // test is the market-report branch (Branch 2). Stray HTTP would fail.
        Config::set('services.google.geocoding_api_key', null);
        Config::set('geo.geocoding.google_api_key', null);
        Config::set('services.nominatim.enabled', false);
        GeocodeRateLimiter::releaseRuntimeOverride();
        Http::preventStrayRequests();

        $this->agency = Agency::create([
            'name' => 'GPS Borrow Test',
            'slug' => 'gps-borrow-' . uniqid(),
        ]);
        // A real branch so the PropertyObserver resolves the agent's branch_id
        // instead of falling back to the non-existent id 1 (FK violation).
        $branch = Branch::create([
            'agency_id' => $this->agency->id,
            'name'      => 'GPS Borrow Branch',
            'code'      => 'GPSB',
            'is_active' => true,
        ]);
        $this->userId = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
        ])->id;
    }

    private function seedReport(string $subjectAddress, string $suburb, float $lat, float $lng): int
    {
        return (int) DB::table('market_reports')->insertGetId([
            'agency_id'           => $this->agency->id,
            'report_type_id'      => null,
            'uploaded_by_user_id' => $this->userId,
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

    public function test_suburb_alone_never_borrows_a_sibling_pin(): void
    {
        // Only a DIFFERENT-street report exists in the same suburb.
        $this->seedReport('ACACIA ROAD', 'margate', -30.8468120, 30.3764720);

        $result = (new AddressResolverService())->resolve('Duke Road', 'Margate');

        // No address match → must NOT borrow Acacia Road's coords. With no
        // Google key the resolver returns a clean failure, not a wrong pin.
        $this->assertFalse($result->hasGps(),
            'suburb-only match must not borrow a different property\'s GPS');
        $this->assertNull($result->latitude);
    }

    public function test_address_match_beats_higher_id_same_suburb_sibling(): void
    {
        // Correct report first (lower id), wrong sibling second (HIGHER id).
        // Pre-fix the suburb OR-clause + orderByDesc(id) returned the sibling.
        $this->seedReport('DUKE ROAD', 'margate', -30.8578220, 30.3742930);
        $this->seedReport('ACACIA ROAD', 'margate', -30.8468120, 30.3764720);

        $result = (new AddressResolverService())->resolve('Duke Road', 'Margate');

        $this->assertTrue($result->hasGps());
        $this->assertEqualsWithDelta(-30.8578220, $result->latitude, 0.00001,
            'must return the address-matched Duke Road report, not the higher-id sibling');
        $this->assertEqualsWithDelta(30.3742930, $result->longitude, 0.00001);
        $this->assertSame('market_report', $result->source);
        $this->assertSame('exact', $result->confidence);
    }

    public function test_suspect_pin_self_heals_bypassing_poisoned_cache_and_upgrading_confidence(): void
    {
        // The corrected source-of-truth report exists.
        $this->seedReport('DUKE ROAD', 'margate', -30.8578220, 30.3742930);

        // Poison the cache with the wrong (borrowed) coords for this address.
        $normalised = AddressNormaliser::normalise('Duke Road', 'Margate', null);
        GeocodingCache::create([
            'address_normalised' => $normalised,
            'address_raw'        => 'Duke Road',
            'latitude'           => -30.8468120,
            'longitude'          => 30.3764720,
            'confidence'         => 'suburb',
            'source'             => 'market_report',
            'last_attempted_at'  => now(),
        ]);

        // A property already carrying the wrong, SUSPECT pin.
        $property = Property::create([
            'agency_id'      => $this->agency->id,
            'agent_id'       => $this->userId,
            'external_id'    => 'GPS-' . Str::random(6),
            'title'          => 'Duke Road',
            'address'        => 'Duke Road',
            'suburb'         => 'Margate',
            'latitude'       => -30.8468120,
            'longitude'      => 30.3764720,
            'geo_source'     => 'suburb_centroid', // suspect → eligible to re-resolve
            'geo_confidence' => 'suburb',
            'property_type'  => 'house',
            'status'         => 'active',
            'is_demo'        => false,
        ]);

        (new PropertyGeoBackfillService())->backfillProperty($property);
        $property->refresh();

        // Re-resolved off the address-matched report, bypassing the poisoned
        // cache, and upgraded suburb -> exact.
        $this->assertEqualsWithDelta(-30.8578220, (float) $property->latitude, 0.00001,
            'suspect pin must self-heal to the correct Duke Road coords');
        $this->assertEqualsWithDelta(30.3742930, (float) $property->longitude, 0.00001);
        $this->assertSame('exact', $property->geo_confidence);
        $this->assertSame('market_report', $property->geo_source);
    }

    public function test_guard_never_downgrades_a_higher_confidence_pin(): void
    {
        // Existing EXACT pin; a forced re-resolve that can only find a coarser
        // (suburb) source must NOT overwrite it. No market-report match exists,
        // and Google is keyless, so the resolver yields a coarse/failed result.
        $property = Property::create([
            'agency_id'      => $this->agency->id,
            'agent_id'       => $this->userId,
            'external_id'    => 'GPS-' . Str::random(6),
            'title'          => 'Somewhere Else',
            'address'        => 'Nowhere Street',
            'suburb'         => 'Margate',
            'latitude'       => -30.8578220,
            'longitude'      => 30.3742930,
            'geo_source'     => 'market_report',
            'geo_confidence' => 'exact',
            'property_type'  => 'house',
            'status'         => 'active',
            'is_demo'        => false,
        ]);

        (new PropertyGeoBackfillService())->backfillProperty($property, force: true);
        $property->refresh();

        $this->assertEqualsWithDelta(-30.8578220, (float) $property->latitude, 0.00001,
            'a force re-resolve that finds nothing better must not blank or downgrade the exact pin');
        $this->assertEqualsWithDelta(30.3742930, (float) $property->longitude, 0.00001);
        $this->assertSame('exact', $property->geo_confidence);
    }
}
