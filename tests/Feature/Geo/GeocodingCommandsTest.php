<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Geocoding\GeocodingCache;
use App\Models\Property;
use App\Models\User;
use App\Services\Geocoding\GeocodeRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * geo:cache-purge --out-of-bbox and geocoding:backfill (NULL / 0,0 rows, --agency, --dry-run).
 * Spec: .ai/specs/geocoding-spec.md "Accepted area" + "Operator runbook".
 */
final class GeocodingCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $caprivi;
    private Agency $other;
    private int $caprivUser;
    private int $otherUser;

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

        [$this->caprivi, $this->caprivUser] = $this->agencyWithUser('Caprivi Test');
        [$this->other, $this->otherUser]    = $this->agencyWithUser('Other Test');
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

    private function property(Agency $agency, int $userId, array $over = []): Property
    {
        // Inserted without events so the create hook does not geocode: these rows stand for stock that
        // was never geocoded (or sits at the 0,0 sentinel).
        return Property::withoutEvents(fn () => Property::create(array_merge([
            'agency_id'     => $agency->id,
            'agent_id'      => $userId,
            'branch_id'     => User::find($userId)->branch_id,
            'external_id'   => 'GC-' . Str::random(8),
            'title'         => 'House',
            'address'       => 'Blue Valley Golf Estate, 1936 Mininkie Street',
            'street_number' => '1936',
            'street_name'   => 'Mininkie Street',
            'suburb'        => 'Blue Valley Golf Estate',
            'city'          => 'Centurion',
            'province'      => 'Gauteng',
            'property_type' => 'house',
            'status'        => 'active',
            'is_demo'       => false,
        ], $over)));
    }

    private function cacheRow(string $key, ?string $reason, string $confidence = 'failed'): void
    {
        GeocodingCache::create([
            'address_normalised' => $key,
            'address_raw'        => $key,
            'latitude'           => $reason === null ? -25.9 : null,
            'longitude'          => $reason === null ? 28.1 : null,
            'confidence'         => $confidence,
            'source'             => 'google',
            'failure_reason'     => $reason,
        ]);
    }

    private function fakeGoogleCenturion(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status'  => 'OK',
                'results' => [[
                    'formatted_address'  => '1936 Mininkie St, Centurion, South Africa',
                    'geometry'           => ['location' => ['lat' => -25.9417, 'lng' => 28.1119], 'location_type' => 'ROOFTOP'],
                    'address_components' => [['types' => ['country'], 'short_name' => 'ZA']],
                ]],
            ], 200),
        ]);
    }

    // ── geo:cache-purge --out-of-bbox ────────────────────────────────────

    public function test_purge_out_of_bbox_deletes_only_those_rows(): void
    {
        $this->cacheRow('a one', 'google:out_of_bbox');
        $this->cacheRow('a two', 'google:out_of_bbox');
        $this->cacheRow('a zero', 'google:ZERO_RESULTS');
        $this->cacheRow('a good one', null, 'exact');

        $this->artisan('geo:cache-purge', ['--out-of-bbox' => true, '--apply' => true])
            ->expectsOutputToContain('2 google:out_of_bbox row(s) deleted')
            ->assertSuccessful();

        $this->assertSame(0, GeocodingCache::where('failure_reason', 'google:out_of_bbox')->count());
        $this->assertSame(1, GeocodingCache::where('failure_reason', 'google:ZERO_RESULTS')->count(), 'other failures stay');
        $this->assertSame(1, GeocodingCache::whereNull('failure_reason')->count(), 'good rows stay');
    }

    public function test_purge_out_of_bbox_is_a_dry_run_unless_applied(): void
    {
        $this->cacheRow('a one', 'google:out_of_bbox');
        $this->cacheRow('a two', 'google:out_of_bbox');

        // No --apply: it only counts.
        $this->artisan('geo:cache-purge', ['--out-of-bbox' => true])
            ->expectsOutputToContain('2 google:out_of_bbox row(s) would be deleted')
            ->assertSuccessful();

        $this->assertSame(2, GeocodingCache::where('failure_reason', 'google:out_of_bbox')->count());

        // --apply together with --dry-run still deletes nothing.
        $this->artisan('geo:cache-purge', ['--out-of-bbox' => true, '--apply' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(2, GeocodingCache::where('failure_reason', 'google:out_of_bbox')->count());
    }

    public function test_the_plain_purge_still_only_removes_expired_rows(): void
    {
        $this->cacheRow('fresh failure', 'google:out_of_bbox');
        DB::table('geocoding_cache')->insert([
            'address_normalised' => 'old row', 'address_raw' => 'old row', 'confidence' => 'failed', 'source' => 'google',
            'failure_reason' => 'google:ZERO_RESULTS', 'expires_at' => now()->subDay(),
            'created_at' => now()->subWeek(), 'updated_at' => now()->subWeek(),
        ]);

        $this->artisan('geo:cache-purge')->expectsOutputToContain('1 expired row(s) deleted')->assertSuccessful();

        $this->assertSame(1, GeocodingCache::where('address_normalised', 'fresh failure')->count());
    }

    // ── geocoding:backfill ───────────────────────────────────────────────

    public function test_backfill_dry_run_counts_null_and_zero_zero_rows_and_writes_nothing(): void
    {
        $this->property($this->caprivi, $this->caprivUser);                                          // NULL
        $this->property($this->caprivi, $this->caprivUser, ['latitude' => 0, 'longitude' => 0]);      // sentinel
        $this->property($this->caprivi, $this->caprivUser, ['latitude' => -25.9, 'longitude' => 28.1]); // has a pin
        $this->property($this->other, $this->otherUser);                                              // other agency
        Http::fake();

        $this->artisan('geocoding:backfill', ['--type' => 'properties'])
            ->expectsOutputToContain('3 property row(s) would be processed')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(1, Property::withoutGlobalScopes()->whereNull('latitude')->where('agency_id', $this->caprivi->id)->count());
        $this->assertSame(0, Property::withoutGlobalScopes()->whereNotNull('geo_source')->count(), 'no row was touched');
    }

    public function test_backfill_for_one_agency_resolves_its_rows_and_leaves_the_others(): void
    {
        $nullRow = $this->property($this->caprivi, $this->caprivUser);
        $zeroRow = $this->property($this->caprivi, $this->caprivUser, ['street_number' => '77', 'address' => 'Blue Valley Golf Estate, 77 Mininkie Street', 'latitude' => 0, 'longitude' => 0]);
        $pinned  = $this->property($this->caprivi, $this->caprivUser, ['street_number' => '5', 'address' => '5 Mininkie Street', 'latitude' => -25.1234567, 'longitude' => 28.7654321, 'geo_source' => 'google', 'geo_confidence' => 'exact']);
        $foreign = $this->property($this->other, $this->otherUser);
        $this->fakeGoogleCenturion();

        $this->artisan('geocoding:backfill', ['--type' => 'properties', '--agency' => $this->caprivi->id, '--apply' => true])->assertSuccessful();

        foreach ([$nullRow, $zeroRow] as $p) {
            $p->refresh();
            $this->assertEqualsWithDelta(-25.9417, (float) $p->latitude, 0.0001, 'NULL and 0,0 rows both get a pin');
            $this->assertSame('google', $p->geo_source);
        }
        $pinned->refresh();
        $this->assertEqualsWithDelta(-25.1234567, (float) $pinned->latitude, 0.0000001, 'a row that already has a pin is not touched');
        $foreign->refresh();
        $this->assertNull($foreign->latitude, 'another agency\'s property is not touched');
        $this->assertNull($foreign->geo_source);
    }

    public function test_backfill_after_the_purge_recovers_a_gauteng_address_that_had_a_cached_failure(): void
    {
        $row = $this->property($this->caprivi, $this->caprivUser);
        // The poisoned cache entry the old KZN box left behind for this very address.
        $this->cacheRow(\App\Support\Geocoding\AddressNormaliser::normalise('1936 Mininkie Street', $row->suburb, $row->town), 'google:out_of_bbox');
        $this->fakeGoogleCenturion();

        $this->artisan('geocoding:backfill', ['--type' => 'properties', '--agency' => $this->caprivi->id, '--apply' => true])->assertSuccessful();
        $this->assertNull($row->refresh()->latitude, 'the cached failure is served until it is purged');

        $this->artisan('geo:cache-purge', ['--out-of-bbox' => true, '--apply' => true])->assertSuccessful();
        $this->artisan('geocoding:backfill', ['--type' => 'properties', '--agency' => $this->caprivi->id, '--apply' => true])->assertSuccessful();

        $row->refresh();
        $this->assertEqualsWithDelta(-25.9417, (float) $row->latitude, 0.0001);
        $this->assertSame('google', $row->geo_source);
    }
}
