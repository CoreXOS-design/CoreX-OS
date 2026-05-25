<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Models\Geocoding\GeocodingCache;
use App\Services\Geocoding\GeocodeCache;
use App\Support\Geocoding\AddressNormaliser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 11a Part F — G8-G10. TTL + hit tracking on GeocodeCache.
 */
final class GeocodeCacheTest extends TestCase
{
    use RefreshDatabase;

    /** G8 — get() must return null for a row past its expires_at. */
    public function test_g8_get_returns_null_for_expired_row(): void
    {
        $address = '12 Beach Road, Margate';
        $normalised = AddressNormaliser::normalise($address);

        GeocodingCache::create([
            'address_normalised' => $normalised,
            'address_raw'        => $address,
            'latitude'           => -30.8654,
            'longitude'          => 30.3712,
            'confidence'         => 'exact',
            'source'             => 'google',
            'expires_at'         => CarbonImmutable::now()->subDay(), // already expired
        ]);

        $cache = app(GeocodeCache::class);
        $this->assertNull($cache->get($address), 'expired entry should read as miss');
    }

    /** G9 — purgeExpired deletes only rows whose expires_at is in the past. */
    public function test_g9_purge_expired_deletes_only_past_expiry_rows(): void
    {
        // Past — should be deleted.
        GeocodingCache::create([
            'address_normalised' => 'past entry',
            'address_raw'        => 'past entry',
            'confidence'         => 'failed',
            'source'             => 'cache',
            'expires_at'         => CarbonImmutable::now()->subDays(2),
        ]);
        // Future — must survive.
        GeocodingCache::create([
            'address_normalised' => 'future entry',
            'address_raw'        => 'future entry',
            'confidence'         => 'exact',
            'source'             => 'google',
            'latitude'           => -30.0,
            'longitude'          => 30.0,
            'expires_at'         => CarbonImmutable::now()->addDays(30),
        ]);
        // Null expires_at — must survive (treated as no-TTL legacy row).
        GeocodingCache::create([
            'address_normalised' => 'legacy no-ttl',
            'address_raw'        => 'legacy no-ttl',
            'confidence'         => 'exact',
            'source'             => 'google',
            'latitude'           => -29.0,
            'longitude'          => 31.0,
            'expires_at'         => null,
        ]);

        $deleted = app(GeocodeCache::class)->purgeExpired();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('geocoding_cache', ['address_normalised' => 'past entry']);
        $this->assertDatabaseHas('geocoding_cache', ['address_normalised' => 'future entry']);
        $this->assertDatabaseHas('geocoding_cache', ['address_normalised' => 'legacy no-ttl']);
    }

    /** G10 — get() must bump hit_count without touching updated_at. */
    public function test_g10_get_increments_hit_count_without_touching_updated_at(): void
    {
        $address = '4 Tucker Avenue, Uvongo';
        $normalised = AddressNormaliser::normalise($address);

        $row = GeocodingCache::create([
            'address_normalised' => $normalised,
            'address_raw'        => $address,
            'latitude'           => -30.8492,
            'longitude'          => 30.3925,
            'confidence'         => 'exact',
            'source'             => 'google',
            'hit_count'          => 0,
            'expires_at'         => CarbonImmutable::now()->addDays(90),
        ]);
        $originalUpdatedAt = $row->updated_at;

        // Make sure we cross a clock-tick boundary.
        sleep(1);

        $cache = app(GeocodeCache::class);
        $first  = $cache->get($address);
        $second = $cache->get($address);

        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $persisted = DB::table('geocoding_cache')->where('id', $row->id)->first();
        $this->assertSame(2, (int) $persisted->hit_count, 'hit_count should reflect both reads');
        $this->assertNotNull($persisted->last_hit_at, 'last_hit_at should be stamped on read');
        $this->assertSame(
            (string) $originalUpdatedAt,
            (string) $persisted->updated_at,
            'updated_at must NOT change on a read-side hit-record',
        );
    }
}
