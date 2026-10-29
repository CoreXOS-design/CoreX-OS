<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\User;
use App\Services\RoadToSold\Contracts\AreaProvider;
use App\Services\RoadToSold\Contracts\FollowUpSource;
use App\Services\RoadToSold\Contracts\SuggestionGuard;
use App\Services\RoadToSold\Contracts\WorkCalendar;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\Support\BuilderSettings;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\RoadToSold\Concerns\NullSuggestionGuard;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * AT-454 - how long ONE agent's full list build takes against a realistic pool, now the scan is uncapped.
 * Heavy (seeds ~34,000 adverts), so it only runs on request:   RTS_TIMING=1 scripts/lane-test.sh <this file>
 * Runs on the tests-only database inside the test transaction - never QA1. Prints seconds + query counts to stderr
 * and asserts the 5-second budget for a single agent on a real day (the all-blocked worst case just has to stay under 30 s).
 */
final class BuildTimingTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const LISTINGS = 34000;
    private const SUBURBS = 140;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('RTS_TIMING')) {
            $this->markTestSkipped('Timing run: set RTS_TIMING=1.');
        }
        $this->setUpAgency();
        Cache::flush();
    }

    private function seedPool(): void
    {
        $a = $this->agency->id;
        $now = now()->toDateTimeString();
        mt_srand(7);

        // tracked properties + listings (1:1), spread over suburbs, a third with an address, ages 0-400 days
        $tpIds = [];
        foreach (array_chunk(range(1, self::LISTINGS), 1000) as $chunk) {
            $rows = [];
            foreach ($chunk as $i) {
                $sub = 'Suburb ' . ($i % self::SUBURBS);
                $rows[] = ['agency_id' => $a, 'external_id' => (string) Str::uuid(), 'suburb' => $sub, 'suburb_normalised' => Str::lower($sub), 'status' => 'active', 'source_chain' => '[]', 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('tracked_properties')->insert($rows);
        }
        $tpIds = DB::table('tracked_properties')->where('agency_id', $a)->orderBy('id')->pluck('id')->all();

        foreach (array_chunk(range(0, self::LISTINGS - 1), 1000) as $chunk) {
            $rows = [];
            foreach ($chunk as $i) {
                $age = mt_rand(0, 400);
                $sub = 'Suburb ' . (($i + 1) % self::SUBURBS);
                $rows[] = [
                    'agency_id' => $a, 'tracked_property_id' => $tpIds[$i], 'captured_by_user_id' => $this->agent->id,
                    'portal_source' => 'p24', 'portal_ref' => 'P24-' . (700000 + $i), 'portal_url' => 'https://example.test/' . $i,
                    'address' => $i % 3 === 0 ? ($i . ' Test Road') : null, 'normalized_address' => $i % 3 === 0 ? (Str::lower($sub) . '|' . $i . ' test road') : null,
                    'suburb' => $sub, 'price' => 1_000_000 + $i * 10, 'is_active' => 1, 'listing_type' => 'sale',
                    'first_seen_at' => now()->subDays($age)->toDateTimeString(), 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('prospecting_listings')->insert($rows);
        }
        $listingIds = DB::table('prospecting_listings')->where('agency_id', $a)->orderBy('id')->pluck('id')->all();

        // 20 other agents with 8,000 CoreX properties; 900 of them tied to adverts by reference (the unlinked case)
        $others = [];
        for ($i = 0; $i < 20; $i++) {
            $others[] = $this->makeAgent()->id;
        }
        foreach (array_chunk(range(0, 7999), 1000) as $chunk) {
            $rows = [];
            foreach ($chunk as $i) {
                $rows[] = [
                    'agency_id' => $a, 'branch_id' => $this->branch->id, 'agent_id' => $others[$i % 20], 'external_id' => (string) Str::uuid(),
                    'title' => 'P', 'address' => $i . ' Other Road', 'suburb' => 'Suburb ' . ($i % self::SUBURBS), 'status' => ['withdrawn', 'sold', 'expired', 'active'][$i % 4],
                    'listing_type' => 'sale', 'p24_ref' => $i < 900 ? (string) (700000 + $i * 7) : null, 'created_at' => $now, 'updated_at' => now()->subDays(40)->toDateTimeString(),
                ];
            }
            DB::table('properties')->insert($rows);
        }

        // buyer demand for the agent's own buyers + the agency's: 300 buyers, 25,000 strong/perfect matches
        $buyers = [];
        for ($i = 0; $i < 300; $i++) {
            $buyers[] = $this->buyer($i < 100 ? $this->agent->id : $others[$i % 20])->id;
        }
        foreach (array_chunk(range(0, 24999), 1000) as $chunk) {
            $rows = [];
            foreach ($chunk as $i) {
                $rows[] = ['prospecting_listing_id' => $listingIds[($i * 3) % self::LISTINGS], 'contact_id' => $buyers[$i % 300], 'agency_id' => $a, 'score' => 80 + $i % 20, 'tier' => $i % 5 === 0 ? 'perfect' : 'strong', 'source' => 'manual', 'matched_at' => $now, 'last_recompute_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('prospecting_buyer_matches')->insertOrIgnore($rows);
        }
        // price drops on 3,000 adverts, owners on 1,500 tracked properties
        foreach (array_chunk(range(0, 2999), 1000) as $chunk) {
            DB::table('prospecting_price_history')->insert(array_map(fn ($i) => ['prospecting_listing_id' => $listingIds[$i * 11], 'old_price' => 2_000_000, 'new_price' => 1_800_000, 'changed_at' => now()->subDays($i % 25)->toDateTimeString(), 'created_at' => $now, 'updated_at' => $now], $chunk));
        }
        $contact = DB::table('contacts')->where('agency_id', $a)->value('id');
        foreach (array_chunk(range(0, 1499), 500) as $chunk) {
            DB::table('tracked_property_owners')->insert(array_map(fn ($i) => ['agency_id' => $a, 'tracked_property_id' => $tpIds[$i * 20], 'contact_id' => $contact, 'name' => 'Owner', 'created_at' => $now, 'updated_at' => $now], $chunk));
        }
        // hot pockets: 3,000 own leads over the suburbs
        $props = [];
        for ($i = 0; $i < self::SUBURBS; $i++) {
            $props[] = $this->property($others[0], ['suburb' => 'Suburb ' . $i]);
        }
        $leads = [];
        for ($i = 0; $i < 3000; $i++) {
            $leads[] = ['agency_id' => $a, 'portal' => 'p24', 'lead_type' => 'Email', 'listing_id' => $props[$i % self::SUBURBS], 'name' => 'E', 'received_at' => now()->subDays($i % 100)->toDateTimeString(), 'lead_source_raw' => '{}', 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($leads, 1000) as $c) {
            DB::table('portal_leads')->insert($c);
        }
        // the agent's own stock: 40 expired, 30 live and quiet
        for ($i = 0; $i < 40; $i++) {
            $this->property($this->agent->id, ['status' => 'expired', 'expiry_date' => '2026-09-01']);
        }
        for ($i = 0; $i < 30; $i++) {
            $this->property($this->agent->id, ['status' => 'active', 'listed_date' => now()->subDays(100)->toDateString(), 'created_at' => now()->subDays(105)]);
        }
    }

    private function builder(int $target, ?SuggestionGuard $guard = null): ListBuilderService
    {
        $calendar = new class($target) implements WorkCalendar {
            public function __construct(private int $t) {}
            public function targetFor(User $u, CarbonInterface $d, string $track): int { return $this->t; }
            public function isWorkday(User $u, CarbonInterface $d, string $track): bool { return true; }
            public function nextWorkday(User $u, CarbonInterface $from, string $track): ?Carbon { return Carbon::instance($from)->addDay(); }
        };
        $areas = new class implements AreaProvider {
            public function suburbIdsFor(User $u, string $t): array { return []; }
            public function suburbNamesNormalisedFor(User $u, string $t): array { return ['suburb 3', 'suburb 4']; }
            public function suburbNamesLowerTrimFor(User $u, string $t): array { return ['suburb 3', 'suburb 4']; }
            public function hasAreas(User $u, string $t): bool { return true; }
        };
        $none = new class implements FollowUpSource {
            public function dueOn(User $u, CarbonInterface $d, string $t): array { return []; }
        };

        return new ListBuilderService(calendar: $calendar, areas: $areas, guard: $guard ?? new NullSuggestionGuard(), followUps: $none, settingsResolver: fn () => new BuilderSettings());
    }

    private function time(string $label, callable $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $t = microtime(true);
        $run();
        $secs = microtime(true) - $t;
        $log = DB::getQueryLog();
        DB::disableQueryLog();
        $slow = collect($log)->sortByDesc('time')->first();
        fwrite(STDERR, sprintf("\nTIMING %-34s %6.2fs  %4d queries  slowest query %.0f ms\n", $label, $secs, count($log), $slow['time'] ?? 0));

        foreach (collect($log)->groupBy(fn ($q) => substr(preg_replace('/\s+/', ' ', $q['query']), 0, 70))->map->count()->sortDesc()->take(4) as $shape => $n) {
            fwrite(STDERR, sprintf("    %5d x %s\n", $n, $shape));
        }
        foreach (collect($log)->sortByDesc('time')->take(2) as $q) {
            fwrite(STDERR, sprintf("    %6.0f ms  %s\n", $q['time'], substr(preg_replace('/\s+/', ' ', $q['query']), 0, 230)));
        }

        return [$secs, count($log)];
    }

    public function test_one_agents_full_build_stays_inside_five_seconds(): void
    {
        $seedStart = microtime(true);
        $this->seedPool();
        fwrite(STDERR, sprintf("\nTIMING seeded %d adverts in %.1fs\n", self::LISTINGS, microtime(true) - $seedStart));

        // 1. the usual morning: target 5, the agent's buyers fill it
        [$s1] = $this->time('normal day, target 5', fn () => $this->builder(5)->buildFor($this->agent, '2026-10-14'));

        // 2. a long day with every source switched on: target 60
        DB::table('rts_list_items')->delete();
        Cache::flush();
        [$s2] = $this->time('big day, target 60', fn () => $this->builder(60)->buildFor($this->agent, '2026-10-14'));

        // 3. worst case: every property is held or blocked, so every selection exhausts the whole pool
        DB::table('rts_list_items')->delete();
        Cache::flush();
        $blockAll = new class implements SuggestionGuard {
            public function blockedFor(User $u, array $ids, string $t, ?CarbonInterface $on = null): array { return $ids; }
            public function ownHeld(User $u, array $ids, string $t): array { return []; }
        };
        [$s3] = $this->time('worst case: nothing eligible', fn () => $this->builder(5, $blockAll)->buildFor($this->agent, '2026-10-14'));

        // 4. "add to my day": 50 more from one selection
        [$s4] = $this->time('add 50 from long on market', fn () => $this->builder(5)->addToDay($this->agent, 'long_on_market', 50, '2026-10-14'));

        // the budget applies to real days; the "nothing eligible" case is reported, not asserted (it exhausts all seven sources)
        foreach ([$s1, $s2, $s4] as $secs) {
            $this->assertLessThan(5.0, $secs);
        }
        $this->assertLessThan(30.0, $s3);
    }
}
