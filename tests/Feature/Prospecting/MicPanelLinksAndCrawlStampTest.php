<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Prospecting\CrawlRecords;
use App\Services\Prospecting\MicWork\RefreshStatusPanelProvider;
use App\Services\Prospecting\P24AreaPageLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * MIC panel 11 ("Market data last refreshed"), spec §11.2.2 / §11.4: the "Open in P24" / "Open in PP" links open the right suburb search
 * sorted NEWEST first, and an extension read of that very search stamps the crawl record (mic_crawls) for that suburb and portal, which
 * resets the panel's age. Fixture-driven: the tiles are the REAL ones the extension reads off a saved Property24 results page; nothing
 * touches a portal.
 */
final class MicPanelLinksAndCrawlStampTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;
    private int $suburbId;

    protected function setUp(): void
    {
        parent::setUp();
        // the suburb lookups are memoised per process; each test seeds its own suburb row
        \App\Observers\MicSuburbIdObserver::flush();
        \App\Services\Address\SuburbResolver::flush();
        $this->agency = Agency::create(['name' => 'Margate Agency', 'slug' => 'margate-agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id]);

        $country = DB::table('p24_countries')->insertGetId(['p24_id' => 1, 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now()]);
        $prov = DB::table('p24_provinces')->insertGetId(['p24_id' => 1, 'p24_country_id' => $country, 'name' => 'KwaZulu-Natal', 'created_at' => now(), 'updated_at' => now()]);
        $city = DB::table('p24_cities')->insertGetId(['p24_id' => 10, 'p24_province_id' => $prov, 'name' => 'Margate', 'created_at' => now(), 'updated_at' => now()]);
        $this->suburbId = (int) DB::table('p24_suburbs')->insertGetId(['p24_id' => 6359, 'p24_city_id' => $city, 'name' => 'Uvongo', 'slug' => 'uvongo', 'confirmed' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $ppProv = DB::table('pp_provinces')->insertGetId(['pp_province_id' => 1, 'name' => 'KwaZulu-Natal', 'created_at' => now(), 'updated_at' => now()]);
        $ppCity = DB::table('pp_cities')->insertGetId(['pp_city_id' => 1, 'pp_province_id' => $ppProv, 'name' => 'Margate', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pp_suburbs')->insert(['pp_suburb_id' => 6359, 'pp_city_id' => $ppCity, 'name' => 'Uvongo', 'normalised_name' => 'uvongo', 'created_at' => now(), 'updated_at' => now()]);

        // the agency already works Uvongo: one advert last seen 30 days ago, so the panel starts with an approximate, old read
        $old = now()->subDays(30);
        DB::table('prospecting_listings')->insert([
            'agency_id' => $this->agency->id, 'captured_by_user_id' => $this->user->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-100000001', 'suburb' => 'Uvongo',
            'p24_suburb_id' => $this->suburbId, 'is_active' => 1, 'first_seen_at' => $old, 'last_seen_at' => $old, 'created_at' => $old, 'updated_at' => $old,
        ]);
    }

    private function row(): array
    {
        $rows = app(RefreshStatusPanelProvider::class)->rows($this->agency->id, $this->user, []);
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    public function test_the_open_links_go_to_the_right_suburb_newest_first_and_the_crawl_record_accepts_them(): void
    {
        $r = $this->row();

        $this->assertSame('https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359?sp=so%3dNewest', $r['p24_url']);
        $this->assertSame('https://www.privateproperty.co.za/for-sale/advanced-search?si=6359&sorttype=Date&sortorder=Descending', $r['pp_url']);
        $this->assertSame('https://www.property24.com/margate/uvongo/property-trends/6359#corex-area=all&province=kwazulu-natal', $r['area_url']);

        // sorting is not a filter: a read of these exact addresses counts as a whole-suburb read; a price band would not
        $this->assertFalse(CrawlRecords::isFiltered('p24', $r['p24_url']));
        $this->assertFalse(CrawlRecords::isFiltered('pp', $r['pp_url']));
        $this->assertTrue(CrawlRecords::isFiltered('p24', $r['p24_url'] . '%26pf%3d1000000'));
        $this->assertSame('sale', CrawlRecords::listingTypeFromUrl($r['p24_url']));

        // the three area pages are the ones the extension reads
        $urls = P24AreaPageLinks::areaUrls('uvongo', 'margate', 'kwazulu-natal', 6359);
        $this->assertSame($r['area_url'], $urls['trends']);
        $this->assertStringStartsWith('https://www.property24.com/estate-agencies/uvongo/margate/kwazulu-natal/6359#corex-area=all', $urls['agencies']);
        $this->assertStringStartsWith('https://www.property24.com/property-values/uvongo/margate/kwazulu-natal/6359#corex-area=all', $urls['street_index']);
        $this->assertNull($r['area_read_at'], 'never read yet');
    }

    public function test_an_extension_read_of_the_p24_link_stamps_the_crawl_record_and_resets_the_age(): void
    {
        $before = $this->row();
        $this->assertTrue($before['p24_approx'], 'before any real crawl the read is only approximated from the newest advert');
        $this->assertGreaterThanOrEqual(30, $before['age_days']);
        $this->assertTrue($before['overdue']);

        $tiles = json_decode(file_get_contents(base_path('public/chrome-extension/portal-capture/tests/fixtures/p24-search/margate-search-owner-groups-reduced.tiles.json')), true);
        $this->assertCount(21, $tiles);

        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/prospecting/import', [
            'source' => 'p24',
            'search_context' => [
                'url' => $before['p24_url'], 'search_term' => 'Uvongo property for sale', 'total_results' => 21, 'pages_captured' => 1, 'capture_complete' => true,
                'crawl' => ['session_id' => '66666666-6666-4666-8666-666666666666', 'portal' => 'p24', 'suburb' => 'Uvongo', 'page' => 1, 'pages_total' => 1, 'complete' => true],
            ],
            'listings' => $tiles,
        ])->assertOk()->assertJsonPath('imported', 21);

        $crawl = DB::table('mic_crawls')->where('agency_id', $this->agency->id)->where('session_id', '66666666-6666-4666-8666-666666666666')->first();
        $this->assertNotNull($crawl, 'the read is on the crawl record');
        $this->assertSame('p24', $crawl->portal);
        $this->assertSame(1, (int) $crawl->is_complete);
        $this->assertSame(0, (int) $crawl->is_filtered);
        $this->assertSame('sale', $crawl->listing_type);
        $this->assertSame(21, (int) $crawl->adverts_seen);
        $this->assertSame($before['p24_url'], $crawl->search_url);
        $this->assertTrue(DB::table('mic_crawl_suburbs')->where('mic_crawl_id', $crawl->id)->where('agency_id', $this->agency->id)->where('portal', 'p24')->where('suburb_id', $this->suburbId)->exists(),
            'Uvongo is stamped on the crawl for portal p24');

        $after = $this->row();
        $this->assertFalse($after['p24_approx'], 'a real, complete, unfiltered crawl replaces the approximation');
        $this->assertSame(0, $after['age_days']);
        $this->assertSame(now()->toDateString(), substr((string) $after['p24_read_at'], 0, 10));
        $this->assertSame(1, DB::table('mic_crawl_suburbs as s')->join('mic_crawls as c', 'c.id', '=', 's.mic_crawl_id')->where('c.agency_id', $this->agency->id)
            ->where('s.suburb_id', $this->suburbId)->where('s.portal', 'pp')->count() + 1, 'nothing was stamped for PP by a P24 read');
        $this->assertTrue($after['overdue'], 'PP has still not been read, so the suburb stays on the overdue list');
    }

    public function test_an_extension_read_of_the_pp_link_stamps_pp_for_that_suburb(): void
    {
        $r = $this->row();
        $listing = fn (string $ref) => ['portal_ref' => $ref, 'portal_url' => 'https://www.privateproperty.co.za/for-sale/kwazulu-natal/margate/uvongo/' . $ref, 'suburb' => 'Uvongo',
            'price' => 1500000, 'bedrooms' => 3, 'property_type' => 'House', 'portal_status' => 'active', 'advertiser_type' => 'agency', 'agency_name' => 'Seeff'];

        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/prospecting/import', [
            'source' => 'pp',
            'search_context' => [
                'url' => $r['pp_url'], 'search_term' => 'Uvongo', 'total_results' => 3, 'pages_captured' => 1, 'capture_complete' => true,
                'crawl' => ['session_id' => '77777777-7777-4777-8777-777777777777', 'portal' => 'pp', 'suburb' => 'Uvongo', 'page' => 1, 'pages_total' => 1, 'complete' => true],
            ],
            'listings' => [$listing('PP-T5000001'), $listing('PP-T5000002'), $listing('PP-T5000003')],
        ])->assertOk();

        $crawl = DB::table('mic_crawls')->where('agency_id', $this->agency->id)->where('session_id', '77777777-7777-4777-8777-777777777777')->first();
        $this->assertSame(['pp', 1, 0, 3], [$crawl->portal, (int) $crawl->is_complete, (int) $crawl->is_filtered, (int) $crawl->adverts_seen]);
        $this->assertTrue(DB::table('mic_crawl_suburbs')->where('mic_crawl_id', $crawl->id)->where('portal', 'pp')->where('suburb_id', $this->suburbId)->exists());

        $after = $this->row();
        $this->assertFalse($after['pp_approx']);
        $this->assertSame(now()->toDateString(), substr((string) $after['pp_read_at'], 0, 10));
    }

    public function test_the_area_read_date_shows_on_the_row_once_the_pages_were_read(): void
    {
        Sanctum::actingAs($this->user);
        $golden = json_decode(file_get_contents(base_path('public/chrome-extension/portal-capture/tests/fixtures/p24-area/uvongo-property-trends-6359.payload.json')), true);
        $this->postJson('/api/v1/prospecting/area-pages', $golden)->assertOk();

        $r = $this->row();
        $this->assertSame(now()->toDateString(), $r['area_read_at']);
    }
}
