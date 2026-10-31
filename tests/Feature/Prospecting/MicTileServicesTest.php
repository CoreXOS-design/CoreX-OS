<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\MicWork\GoneTileProvider;
use App\Services\Prospecting\MicWork\MicWorkTiles;
use App\Services\Prospecting\MicWork\NeighboursPanelProvider;
use App\Services\Prospecting\MicWork\RefreshStatusPanelProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-520 step 4.6 (mic-work-tab.md 11.3.F, 11.9): tile 3 (under offer / sold / gone), tile 10 (neighbours of a sale) and tile 11 (market data last
 * refreshed) - agency-bound, filtered, and registered for the Work tab through the tags.
 */
final class MicTileServicesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;
    private User $u;
    private int $n = 118000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $br = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->u = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'agent']);
    }

    private function file(array $attrs = [], ?Agency $agency = null): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(array_merge(['agency_id' => ($agency ?? $this->a)->id, 'status' => 'active', 'first_seen_at' => now(),
            'street_name' => 'Beach Road', 'suburb' => 'Uvongo'], $attrs));
    }

    private function advert(TrackedProperty $tp, string $status, int $daysAgo, array $attrs = []): ProspectingListing
    {
        $this->n++;
        $l = new ProspectingListing();
        $l->forceFill(array_merge(['agency_id' => $tp->agency_id, 'captured_by_user_id' => $this->u->id, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-' . $this->n,
            'portal_url' => 'https://www.property24.com/x/' . $this->n, 'suburb' => 'Uvongo', 'price' => 1_500_000, 'property_type' => 'House', 'first_seen_at' => now()->subDays(200),
            'last_seen_at' => now(), 'is_active' => $status === 'active', 'portal_status' => $status, 'listing_type' => 'sale', 'portal_status_changed_at' => now()->subDays($daysAgo),
            'off_market_at' => $status === 'active' ? null : now()->subDays($daysAgo)], $attrs))->save();

        return $l->fresh();
    }

    // ---- tile 3 ------------------------------------------------------------------------------------------------------

    public function test_tile_3_counts_under_offer_sold_and_gone_in_the_window_for_this_agency_only(): void
    {
        foreach ([['under_offer', 3], ['sold', 10], ['gone', 5], ['probably_sold', 2]] as [$st, $d]) {
            $this->advert($this->file(), $st, $d);
        }
        $this->advert($this->file(), 'sold', 90);                                          // too old for the 30-day window
        $this->advert($this->file(), 'active', 1);                                         // on the market
        $this->advert($this->file(), 'sold', 3, ['listing_type' => 'rent']);               // a rental
        $this->advert($this->file([], $this->b), 'sold', 3);                               // another agency

        $p = new GoneTileProvider();
        $s = $p->summary($this->a->id, $this->u);

        $this->assertSame(3, $p->tile());
        $this->assertSame(4, $s['count']);
        $this->assertStringContainsString('last 30 days', $s['reason']);
        $this->assertStringContainsString('1 under offer', (string) $s['note']);
        $this->assertStringContainsString('1 probably sold', (string) $s['note']);
        $this->assertCount(4, $p->propertyIds($this->a->id, $this->u)->get(), 'a click filters the list to exactly those files');
        $this->assertSame(1, $p->summary($this->b->id, $this->u)['count']);
    }

    // ---- tile 10 -----------------------------------------------------------------------------------------------------

    public function test_tile_10_lists_triggers_newest_first_inside_the_time_filter(): void
    {
        $this->file(['street_number' => '5', 'deeds_registered_date' => now()->subDays(2)->toDateString(), 'last_known_sold_price' => 2_100_000, 'capture_kind' => 'deeds_capture']);
        $this->advert($this->file(['street_number' => '7']), 'sold', 40);
        $this->advert($this->file(['street_number' => '9']), 'under_offer', 5);
        $this->advert($this->file(['street_number' => '11']), 'sold', 400);                // far outside 6 months
        $this->file(['street_number' => '13', 'deeds_registered_date' => now()->subDays(2)->toDateString(), 'suburb' => 'Margate'], $this->b);
        $p = new NeighboursPanelProvider();

        $thisMonth = $p->rows($this->a->id, $this->u, ['months' => 0]);
        $all = $p->rows($this->a->id, $this->u, ['months' => 6]);

        $this->assertContains('registered', array_column($all, 'type'));
        $this->assertSame(['registered', 'sold', 'under_offer'], collect($all)->pluck('type')->sort()->values()->all());
        $this->assertCount(3, $all);
        $this->assertGreaterThanOrEqual(1, count($thisMonth));
        $this->assertSame('5 Beach Road, Uvongo', collect($all)->firstWhere('type', 'registered')['address']);
        $this->assertSame(2_100_000, collect($all)->firstWhere('type', 'registered')['price']);
        $this->assertSame('registered', $all[0]['type'], 'deeds registrations come first, then portal sold / under offer');
        $this->assertSame(['under_offer', 'sold'], collect($all)->whereIn('type', ['sold', 'under_offer'])->pluck('type')->values()->all(), 'newest first inside the portal group (5 days ago, then 40)');
        $this->assertTrue(collect($all)->every(fn ($r) => $r['has_address']));
        $this->assertSame(['sold'], collect($p->rows($this->a->id, $this->u, ['months' => 6, 'type' => 'sold']))->pluck('type')->all());
        $this->assertSame([], $p->rows($this->a->id, $this->u, ['months' => 6, 'suburb' => 'Margate']), "another agency's Margate trigger is not ours");
    }

    public function test_tile_10_neighbours_are_five_each_side_plus_across_and_the_owner_only_comes_from_our_own_deeds(): void
    {
        $trigger = $this->file(['street_number' => '20', 'deeds_registered_date' => now()->toDateString(), 'capture_kind' => 'deeds_capture']);
        foreach ([2, 4, 6, 8, 10, 12, 14, 16, 18, 22, 24, 26, 28, 30, 32, 34, 36, 15, 17, 19, 21, 23, 25, 27, 99] as $n) {
            $this->file(['street_number' => (string) $n]);
        }
        $deeded = TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $this->a->id)->where('street_number', '22')->first();
        $contactId = DB::table('contacts')->insertGetId(['agency_id' => $this->a->id, 'branch_id' => $this->u->branch_id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi', 'created_at' => now(), 'updated_at' => now()]);
        $deeded->forceFill(['owner_contact_id' => $contactId, 'deeds_captured_at' => now()])->save();
        $other = $this->file(['street_number' => '26']);                                    // owner known but NOT from a deeds capture of ours
        $other->forceFill(['owner_contact_id' => $contactId])->save();
        $this->advert($deeded, 'active', 0);

        $rows = (new NeighboursPanelProvider())->around($this->a->id, $this->u, 'registered:' . $trigger->id);
        $by = collect($rows)->groupBy('side');

        $this->assertCount(5, $by['before']);
        $this->assertCount(5, $by['after']);
        $this->assertCount(5, $by['across']);
        $this->assertSame([10, 12, 14, 16, 18], $by['before']->pluck('number')->all(), 'the five nearest on the same side before it');
        $this->assertSame([22, 24, 26, 28, 30], $by['after']->pluck('number')->all());
        $this->assertSame([15, 17, 19, 21, 23], $by['across']->pluck('number')->all(), 'across: the five nearest opposite-side numbers (listed by number)');
        $row22 = collect($rows)->firstWhere('number', 22);
        $this->assertSame('Thandi Nkosi', $row22['owner']);
        $this->assertTrue($row22['on_market']);
        $this->assertSame($deeded->id, $row22['property_id']);
        $this->assertNull(collect($rows)->firstWhere('number', 26)['owner'], 'an owner not from our own deeds capture is never shown');
        $this->assertSame([], (new NeighboursPanelProvider())->around($this->b->id, $this->u, 'registered:' . $trigger->id), "another agency cannot open our trigger");
    }

    public function test_tile_10_a_flat_in_a_complex_lists_the_other_units(): void
    {
        $cx = DB::table('property_complexes')->insertGetId(['agency_id' => $this->a->id, 'suburb_id' => 1, 'property_type' => 'apartment', 'land_size_m2' => 5000, 'units_count' => 3, 'created_at' => now(), 'updated_at' => now()]);
        $trigger = $this->file(['street_name' => null, 'deeds_registered_date' => now()->toDateString()]);
        $u4 = $this->file(['street_name' => null, 'unit_number' => '4']);
        $u9 = $this->file(['street_name' => null, 'unit_number' => '9']);
        DB::table('tracked_properties')->whereIn('id', [$trigger->id, $u4->id, $u9->id])->update(['complex_id' => $cx]);

        $rows = (new NeighboursPanelProvider())->around($this->a->id, $this->u, 'registered:' . $trigger->id);

        $this->assertCount(2, $rows);
        $this->assertSame('complex', $rows[0]['side']);
    }

    // ---- tile 11 -----------------------------------------------------------------------------------------------------

    private function suburb(string $name, int $p24Id, int $cityId): int
    {
        return (int) DB::table('p24_suburbs')->insertGetId(['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'p24_id' => $p24Id, 'p24_city_id' => $cityId, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function crawl(int $suburbId, string $portal, int $daysAgo, bool $complete = true): void
    {
        $id = DB::table('mic_crawls')->insertGetId(['agency_id' => $this->a->id, 'user_id' => $this->u->id, 'session_id' => uniqid('s'), 'portal' => $portal, 'listing_type' => 'sale',
            'started_at' => now()->subDays($daysAgo)->subHour(), 'finished_at' => now()->subDays($daysAgo), 'pages_read' => 5, 'pages_total' => 5, 'adverts_seen' => 50, 'is_complete' => $complete,
            'is_filtered' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mic_crawl_suburbs')->insert(['mic_crawl_id' => $id, 'agency_id' => $this->a->id, 'portal' => $portal, 'suburb_id' => $suburbId, 'suburb_name' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_tile_11_shows_when_each_portal_was_last_read_completely_and_what_is_overdue(): void
    {
        $country = (int) DB::table('p24_countries')->insertGetId(['name' => 'South Africa', 'p24_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $province = (int) DB::table('p24_provinces')->insertGetId(['name' => 'KwaZulu-Natal', 'p24_id' => 2, 'p24_country_id' => $country, 'created_at' => now(), 'updated_at' => now()]);
        $city = (int) DB::table('p24_cities')->insertGetId(['name' => 'Margate', 'p24_id' => 376, 'p24_province_id' => $province, 'created_at' => now(), 'updated_at' => now()]);
        $fresh = $this->suburb('Uvongo', 6359, $city);
        $stale = $this->suburb('Ramsgate', 6371, $city);
        $never = $this->suburb('Margate Beach', 6372, $city);
        foreach ([$fresh, $stale, $never] as $s) {
            $this->advert($this->file(), 'active', 0);
            DB::table('prospecting_listings')->orderByDesc('id')->limit(1)->update(['p24_suburb_id' => $s]);
        }
        $this->crawl($fresh, 'p24', 1);
        $this->crawl($fresh, 'pp', 2);
        $this->crawl($stale, 'p24', 20);
        $this->crawl($stale, 'pp', 3);
        $this->crawl($never, 'p24', 1, false);                                              // not complete: does not count

        $rows = collect((new RefreshStatusPanelProvider())->rows($this->a->id, $this->u, ['scope' => 'all']))->keyBy('suburb');

        $this->assertFalse($rows['Uvongo']['overdue']);
        $this->assertSame(2, $rows['Uvongo']['age_days']);
        $this->assertTrue($rows['Ramsgate']['overdue'], 'the older read is 20 days old');
        $this->assertSame(20, $rows['Ramsgate']['age_days']);
        $this->assertTrue($rows['Margate Beach']['overdue'], 'PP was never read');
        $this->assertTrue($rows['Margate Beach']['p24_approx'], 'an incomplete crawl does not count; the newest advert sighting stands in, flagged approximate');
        $this->assertNull($rows['Margate Beach']['pp_read_at']);
        $this->assertSame('https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359?sp=so%3dNewest', $rows['Uvongo']['p24_url'], 'newest first (P24AreaPageLinks)');
        $this->assertSame('Ramsgate', array_values($rows->all())[0]['suburb'] ?? null, 'the oldest read comes first');
        $this->assertSame([], (new RefreshStatusPanelProvider())->rows($this->b->id, $this->u, ['scope' => 'all']), "another agency's suburbs are not ours");
    }

    public function test_the_providers_are_registered_for_the_work_tab(): void
    {
        $tiles = app(MicWorkTiles::class);

        $this->assertInstanceOf(GoneTileProvider::class, collect(app()->tagged('mic.work.tiles'))->first(fn ($p) => $p->tile() === 3));
        $panels = collect(app()->tagged('mic.work.panels'))->map(fn ($p) => $p->tile())->sort()->values()->all();
        $this->assertSame([10, 11], $panels);
        $this->assertNotNull($tiles);
    }
    public function test_tile_10_puts_triggers_without_an_address_last_and_never_dates_a_status_we_did_not_watch(): void
    {
        $withAddress = $this->file(['street_number' => '7']);
        $this->advert($withAddress, 'sold', 10);
        $hidden = $this->file(['street_number' => null, 'street_name' => null]);
        $this->advert($hidden, 'sold', 2);
        // first noticed already sold (the status "changed" the moment we first saw the advert): no known date, not a dated trigger
        $unwatched = $this->file(['street_number' => '9']);
        $this->advert($unwatched, 'sold', 0, ['first_seen_at' => now()->subHours(2), 'portal_status_changed_at' => now()->subHour()]);

        $rows = (new NeighboursPanelProvider())->rows($this->a->id, $this->u, ['months' => 6]);

        $this->assertSame(['sold:' . $withAddress->id, 'sold:' . $hidden->id], array_column($rows, 'id'), 'the unwatched change is not listed; the address-less trigger comes last');
        $this->assertTrue($rows[0]['has_address']);
        $this->assertFalse($rows[1]['has_address']);
    }

    public function test_tile_11_uses_the_newest_advert_sighting_as_an_approximate_read_until_a_real_crawl_exists(): void
    {
        $country = (int) DB::table('p24_countries')->insertGetId(['name' => 'South Africa', 'p24_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $province = (int) DB::table('p24_provinces')->insertGetId(['name' => 'KwaZulu-Natal', 'p24_id' => 2, 'p24_country_id' => $country, 'created_at' => now(), 'updated_at' => now()]);
        $city = (int) DB::table('p24_cities')->insertGetId(['name' => 'Margate', 'p24_id' => 376, 'p24_province_id' => $province, 'created_at' => now(), 'updated_at' => now()]);
        $uvongo = $this->suburb('Uvongo', 6359, $city);
        $ramsgate = $this->suburb('Ramsgate', 6371, $city);
        foreach ([$uvongo, $ramsgate] as $sid) {
            $l = $this->advert($this->file(), 'active', 0);
            DB::table('prospecting_listings')->where('id', $l->id)->update(['p24_suburb_id' => $sid, 'last_seen_at' => now()->subDays($sid === $uvongo ? 2 : 12)]);
        }
        $this->crawl($ramsgate, 'p24', 1);                                                 // a real, complete crawl for Ramsgate on P24 only

        $rows = collect((new RefreshStatusPanelProvider())->rows($this->a->id, $this->u, ['scope' => 'all']))->keyBy('suburb');

        $this->assertTrue($rows['Uvongo']['p24_approx']);
        $this->assertSame(2, $rows['Uvongo']['age_days']);
        $this->assertSame('approx (before crawl tracking)', $rows['Uvongo']['approx_label']);
        $this->assertNull($rows['Uvongo']['pp_read_at'], 'no PP sighting at all');
        $this->assertFalse($rows['Ramsgate']['p24_approx'], 'a real crawl replaces the approximation');
        $this->assertSame(1, (int) \Illuminate\Support\Carbon::parse($rows['Ramsgate']['p24_read_at'])->diffInDays(now()));
    }
}
