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

    // ── geocoding:backfill --clear-subject-borrowed-pins ─────────────────

    private const PIN_LAT = -25.9417300;
    private const PIN_LNG = 28.1119200;

    private function report(Agency $agency, int $userId, string $subject): int
    {
        return (int) DB::table('market_reports')->insertGetId([
            'agency_id' => $agency->id, 'report_type_id' => null, 'uploaded_by_user_id' => $userId,
            'file_path' => 'x.pdf', 'file_name' => 'x.pdf', 'file_hash' => hash('sha256', Str::random(12)),
            'report_date' => now()->toDateString(), 'subject_address' => $subject, 'source_suburb' => 'Blue Valley Golf Estate',
            'subject_latitude' => self::PIN_LAT, 'subject_longitude' => self::PIN_LNG,
            'is_demo' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function compRow(int $reportId, Agency $agency, string $address, ?float $lat, ?float $lng, string $type = 'comp'): int
    {
        return (int) DB::table('market_report_comp_rows')->insertGetId([
            'market_report_id' => $reportId, 'agency_id' => $agency->id, 'row_index' => 0, 'row_type' => $type,
            'address' => $address, 'suburb_normalised' => 'blue valley golf estate',
            'latitude' => $lat, 'longitude' => $lng, 'is_demo' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function pinOf(int $compRowId): ?float
    {
        $v = DB::table('market_report_comp_rows')->where('id', $compRowId)->value('latitude');

        return $v === null ? null : (float) $v;
    }

    /** @return array{borrowed: int, borrowedTwo: int, subjectSelf: int, ownPin: int, noNumber: int, subjectRow: int, foreign: int} */
    private function seedBorrowedScenario(): array
    {
        $rep = $this->report($this->caprivi, $this->caprivUser, '11 Manikie Street, Blue Valley Golf Estate 0187, Centurion');
        $ids = [
            'borrowed'    => $this->compRow($rep, $this->caprivi, '14 Manikie Street', self::PIN_LAT, self::PIN_LNG),
            'borrowedTwo' => $this->compRow($rep, $this->caprivi, 'Claverhouse Close, 36 Manikie Street', self::PIN_LAT, self::PIN_LNG),
            'subjectSelf' => $this->compRow($rep, $this->caprivi, '11 Manikie Street', self::PIN_LAT, self::PIN_LNG),
            'ownPin'      => $this->compRow($rep, $this->caprivi, '20 Manikie Street', -25.9500000, 28.1200000),
            'noNumber'    => $this->compRow($rep, $this->caprivi, 'Manikie Street', self::PIN_LAT, self::PIN_LNG),
            'subjectRow'  => $this->compRow($rep, $this->caprivi, '3 Other Street', self::PIN_LAT, self::PIN_LNG, 'subject'),
        ];
        $otherRep = $this->report($this->other, $this->otherUser, '11 Manikie Street, Blue Valley Golf Estate');
        $ids['foreign'] = $this->compRow($otherRep, $this->other, '15 Manikie Street', self::PIN_LAT, self::PIN_LNG);

        // The cached copy of the borrowed answer, and a genuine Google answer for another row's address.
        GeocodingCache::create([
            'address_normalised' => \App\Support\Geocoding\AddressNormaliser::normalise('14 Manikie Street', 'blue valley golf estate', null),
            'address_raw' => '14 Manikie Street', 'latitude' => self::PIN_LAT, 'longitude' => self::PIN_LNG,
            'confidence' => 'exact', 'source' => 'market_report',
        ]);
        GeocodingCache::create([
            'address_normalised' => \App\Support\Geocoding\AddressNormaliser::normalise('Claverhouse Close, 36 Manikie Street', 'blue valley golf estate', null),
            'address_raw' => 'Claverhouse Close, 36 Manikie Street', 'latitude' => -25.9444444, 'longitude' => 28.1133333,
            'confidence' => 'exact', 'source' => 'google',
        ]);

        return $ids;
    }

    public function test_clear_borrowed_pins_is_a_dry_run_by_default_and_changes_nothing(): void
    {
        $ids = $this->seedBorrowedScenario();
        Http::fake();

        $this->artisan('geocoding:backfill', ['--clear-subject-borrowed-pins' => true])
            ->expectsOutputToContain('3 comp row(s) in 2 report(s)')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        Http::assertNothingSent();
        foreach ($ids as $name => $id) {
            if ($name !== 'ownPin') {
                $this->assertEqualsWithDelta(self::PIN_LAT, $this->pinOf($id), 0.0000001, "$name untouched by a dry run");
            }
        }
        $this->assertSame(2, GeocodingCache::count());
    }

    public function test_apply_clears_only_rows_whose_house_number_differs_and_their_borrowed_cache_copy(): void
    {
        $ids = $this->seedBorrowedScenario();
        Http::fake();

        $this->artisan('geocoding:backfill', ['--clear-subject-borrowed-pins' => true, '--apply' => true])
            ->expectsOutputToContain('Cleared 3 comp-row pin(s) and deleted 1 cached copy(ies)')
            ->assertSuccessful();

        Http::assertNothingSent(); // nothing is geocoded: the rows re-pin on the next generate / backfill
        $this->assertNull($this->pinOf($ids['borrowed']));
        $this->assertNull($this->pinOf($ids['borrowedTwo']));
        $this->assertNull($this->pinOf($ids['foreign']), 'another agency\'s borrowed row too when no --agency is given');
        $this->assertEqualsWithDelta(self::PIN_LAT, $this->pinOf($ids['subjectSelf']), 0.0000001, 'same number = the subject itself');
        $this->assertEqualsWithDelta(-25.95, $this->pinOf($ids['ownPin']), 0.0000001, 'its own pin is kept');
        $this->assertEqualsWithDelta(self::PIN_LAT, $this->pinOf($ids['noNumber']), 0.0000001, 'cannot tell without a number: left alone');
        $this->assertEqualsWithDelta(self::PIN_LAT, $this->pinOf($ids['subjectRow']), 0.0000001, 'the subject row is never touched');

        // The borrowed cache copy is gone; the genuine Google answer for another address stays.
        $this->assertSame(0, GeocodingCache::where('source', 'market_report')->count());
        $this->assertSame(1, GeocodingCache::where('source', 'google')->count());
    }

    public function test_clear_borrowed_pins_can_be_limited_to_one_agency(): void
    {
        $ids = $this->seedBorrowedScenario();

        $this->artisan('geocoding:backfill', ['--clear-subject-borrowed-pins' => true, '--apply' => true, '--agency' => $this->caprivi->id])->assertSuccessful();

        $this->assertNull($this->pinOf($ids['borrowed']));
        $this->assertEqualsWithDelta(self::PIN_LAT, $this->pinOf($ids['foreign']), 0.0000001, 'the other agency is not touched');
    }

    public function test_the_dry_run_counts_a_shared_cached_copy_once_so_it_matches_what_apply_deletes(): void
    {
        $rep = $this->report($this->caprivi, $this->caprivUser, '11 Manikie Street, Blue Valley Golf Estate');
        // Three comp rows with the same address (the same house sold three times) share ONE cached answer.
        foreach ([1, 2, 3] as $i) {
            $this->compRow($rep, $this->caprivi, '14 Manikie Street', self::PIN_LAT, self::PIN_LNG);
        }
        GeocodingCache::create([
            'address_normalised' => \App\Support\Geocoding\AddressNormaliser::normalise('14 Manikie Street', 'blue valley golf estate', null),
            'address_raw' => '14 Manikie Street', 'latitude' => self::PIN_LAT, 'longitude' => self::PIN_LNG,
            'confidence' => 'exact', 'source' => 'market_report',
        ]);

        $this->artisan('geocoding:backfill', ['--clear-subject-borrowed-pins' => true])
            ->expectsOutputToContain('3 comp row(s) in 1 report(s)')
            ->expectsOutputToContain('1 cached copy(ies) would be deleted')
            ->assertSuccessful();

        $this->artisan('geocoding:backfill', ['--clear-subject-borrowed-pins' => true, '--apply' => true])
            ->expectsOutputToContain('Cleared 3 comp-row pin(s) and deleted 1 cached copy(ies)')
            ->assertSuccessful();
    }
}
