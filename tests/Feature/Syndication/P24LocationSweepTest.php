<?php

namespace Tests\Feature\Syndication;

use App\Console\Commands\SyncP24Locations;
use App\Models\P24City;
use App\Models\P24Province;
use App\Models\P24Suburb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Tests\TestCase;

/**
 * AT-106 — the daily stamp-and-sweep keeps the P24 location tree from drifting
 * stale. After a successful full walk, anything P24 did NOT re-stamp this run
 * (p24_verified_at older than runStart) is soft-deleted across all three tiers
 * — UNLESS the run looks partial, in which case the sanity floor leaves the
 * tree intact so an API blip can never wipe it.
 */
class P24LocationSweepTest extends TestCase
{
    use RefreshDatabase;

    private P24Province $province;
    private P24City $freshCity;
    private P24City $staleCity;
    private P24Suburb $freshSuburb;
    private P24Suburb $staleSuburb;
    private Carbon $runStart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runStart = now()->subMinute();
        $seen  = now();              // > runStart -> survives
        $stale = now()->subDays(2);  // < runStart -> swept

        $this->province = P24Province::create([
            'p24_id' => 4, 'p24_country_id' => 1, 'name' => 'KwaZulu Natal', 'p24_verified_at' => $seen,
        ]);
        $this->freshCity = P24City::create([
            'p24_id' => 169, 'p24_province_id' => $this->province->id, 'name' => 'Durban', 'p24_verified_at' => $seen,
        ]);
        $this->staleCity = P24City::create([
            'p24_id' => 785, 'p24_province_id' => $this->province->id, 'name' => 'Durban North', 'p24_verified_at' => $stale,
        ]);
        $this->freshSuburb = P24Suburb::create([
            'name' => 'Glenwood', 'slug' => 'glenwood', 'p24_id' => 5969,
            'p24_city_id' => $this->freshCity->id, 'p24_verified_at' => $seen,
        ]);
        $this->staleSuburb = P24Suburb::create([
            'name' => 'Gone', 'slug' => 'gone', 'p24_id' => 99999,
            'p24_city_id' => $this->staleCity->id, 'p24_verified_at' => $stale,
        ]);
    }

    /** Invoke the private sweep with a chosen "seen this run" progress payload. */
    private function sweep(int $provSeen, int $citySeen, int $subSeen): void
    {
        $cmd = new SyncP24Locations();
        $ref = new ReflectionClass($cmd);

        $rs = $ref->getProperty('runStart');
        $rs->setAccessible(true);
        $rs->setValue($cmd, $this->runStart);

        $pg = $ref->getProperty('progress');
        $pg->setAccessible(true);
        $pg->setValue($cmd, ['provinces_done' => $provSeen, 'cities_done' => $citySeen, 'suburbs_done' => $subSeen]);

        $m = $ref->getMethod('pruneStale');
        $m->setAccessible(true);
        $m->invoke($cmd);
    }

    public function test_healthy_run_sweeps_only_unstamped_rows(): void
    {
        // A full, healthy P24 response clears the absolute sanity floor
        // (≥ 5 / 50 / 3000). The sweep itself keys off p24_verified_at, not these.
        $this->sweep(9, 100, 5000);

        $this->assertNull(P24Suburb::find($this->staleSuburb->id), 'stale suburb must be swept');
        $this->assertNotNull(P24Suburb::find($this->freshSuburb->id), 'fresh suburb must survive');
        $this->assertNull(P24City::find($this->staleCity->id), 'stale city must be swept');
        $this->assertNotNull(P24City::find($this->freshCity->id), 'fresh city must survive');
        $this->assertNotNull(P24Province::find($this->province->id), 'fresh province must survive');

        // Soft-deleted, not hard-deleted (non-negotiable #1).
        $this->assertNotNull(P24Suburb::withTrashed()->find($this->staleSuburb->id)->deleted_at);
    }

    public function test_sanity_floor_skips_sweep_on_a_partial_run(): void
    {
        // A truncated P24 reply: only 2000 suburbs returned (< 3000 floor).
        // The tree is left fully intact so an API blip can never wipe it.
        $this->sweep(9, 100, 2000);

        $this->assertNotNull(P24Suburb::find($this->staleSuburb->id), 'partial run must NOT sweep');
        $this->assertNotNull(P24City::find($this->staleCity->id), 'partial run must NOT sweep');
    }

    /** A transient blip (no status_code) is retried, not fatal. */
    public function test_fetch_retry_recovers_from_a_transient_failure(): void
    {
        $calls = 0;
        $fn = function () use (&$calls) {
            $calls++;
            return $calls < 3
                ? ['success' => false, 'message' => 'Connection failed: cURL error 28']
                : ['success' => true, 'data' => ['ok']];
        };

        $resp = $this->invokeFetchRetry($fn, 'suburbs');

        $this->assertTrue($resp['success']);
        $this->assertSame(3, $calls, 'should have retried twice then succeeded');
    }

    /** A definitive 4xx (e.g. "City does not exist") is NOT retried — fails fast. */
    public function test_fetch_retry_does_not_retry_a_definitive_4xx(): void
    {
        $calls = 0;
        $fn = function () use (&$calls) {
            $calls++;
            return ['success' => false, 'status_code' => 400, 'message' => "City with Id '785' does not exist"];
        };

        $this->expectException(\RuntimeException::class);
        try {
            $this->invokeFetchRetry($fn, 'suburbs');
        } finally {
            $this->assertSame(1, $calls, 'a 4xx must not be retried');
        }
    }

    private function invokeFetchRetry(callable $fn, string $what)
    {
        $cmd = new SyncP24Locations();
        $ref = new ReflectionClass($cmd);

        $pg = $ref->getProperty('progress');
        $pg->setAccessible(true);
        $pg->setValue($cmd, []);

        $delay = $ref->getProperty('retryBaseDelay');
        $delay->setAccessible(true);
        $delay->setValue($cmd, 0); // no real sleeps in the test

        $m = $ref->getMethod('fetchRetry');
        $m->setAccessible(true);
        return $m->invoke($cmd, $fn, $what);
    }
}
