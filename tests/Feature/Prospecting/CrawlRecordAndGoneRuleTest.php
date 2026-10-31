<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\MicCrawl;
use App\Models\ProspectingListing;
use App\Models\ProspectingSearch;
use App\Models\User;
use App\Services\Prospecting\CrawlRecords;
use App\Services\Prospecting\SuburbReconcileService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-520 step 4.3 (mic-work-tab.md 11.3): the crawl record per run, the Gone rule (`gone` / `probably_sold`), "reappearing clears it", the filtered-search
 * guard, the "complete crawl within N days" helper, and the stuck-withdrawn back-fill.
 */
final class CrawlRecordAndGoneRuleTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;
    private User $u;
    private int $suburbUvongo;
    private int $suburbMargate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $br = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->u = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'agent']);
        $this->suburbUvongo = 9001;
        $this->suburbMargate = 9002;
    }

    private function search(array $attrs = [], ?Agency $agency = null): ProspectingSearch
    {
        $s = new ProspectingSearch();
        $s->forceFill(array_merge([
            'agency_id' => ($agency ?? $this->a)->id, 'user_id' => $this->u->id, 'portal_source' => 'p24',
            'search_url' => 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/9001', 'search_description' => 'Property for Sale in Uvongo',
            'total_results' => 10, 'pages_captured' => 1, 'listing_count' => 3, 'captured_at' => now(),
            'crawl_session_id' => (string) \Illuminate\Support\Str::uuid(), 'crawl_portal' => 'p24', 'crawl_page' => 1, 'crawl_pages_total' => 1,
        ], $attrs))->save();

        return $s->fresh();
    }

    private function advert(string $ref, array $attrs = [], ?Agency $agency = null): ProspectingListing
    {
        $l = new ProspectingListing();
        $l->forceFill(array_merge([
            'agency_id' => ($agency ?? $this->a)->id, 'captured_by_user_id' => $this->u->id, 'portal_source' => 'p24', 'portal_ref' => $ref,
            'portal_url' => 'https://www.property24.com/x/' . $ref, 'address' => null, 'suburb' => 'Uvongo', 'p24_suburb_id' => $this->suburbUvongo,
            'price' => 1_500_000, 'first_seen_at' => now()->subMonths(2), 'last_seen_at' => now()->subDays(20), 'is_active' => true, 'portal_status' => 'active', 'listing_type' => 'sale',
        ], $attrs))->save();
        // the save-time suburb resolver (MIC 5.1) would overwrite a made-up id: set the id the test means, straight in the table
        DB::table('prospecting_listings')->where('id', $l->id)->update(['p24_suburb_id' => array_key_exists('p24_suburb_id', $attrs) ? $attrs['p24_suburb_id'] : $this->suburbUvongo]);

        return $l->fresh();
    }

    private function seenBy(ProspectingListing $l, ProspectingSearch $s): void
    {
        DB::table('prospecting_listings')->where('id', $l->id)->update(['last_seen_at' => now(), 'last_search_id' => $s->id]);
    }

    private function advertState(ProspectingListing $l): array
    {
        $r = DB::table('prospecting_listings')->where('id', $l->id)->first();

        return [$r->portal_status, (bool) $r->is_active, $r->off_market_at !== null];
    }

    public function test_filtered_searches_are_recognised(): void
    {
        $this->assertFalse(CrawlRecords::isFiltered('p24', 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/9001'));
        $this->assertFalse(CrawlRecords::isFiltered('p24', 'https://www.property24.com/for-sale/alias/hibiscus-coast/102/kwazulu-natal/2?sp=so%3dPriceLow'));
        $this->assertTrue(CrawlRecords::isFiltered('p24', 'https://www.property24.com/for-sale/margate/kwazulu-natal/814?sp=pf%3d1500000%26pt%3d2000000'));
        $this->assertTrue(CrawlRecords::isFiltered('p24', 'https://www.property24.com/houses-for-sale/southport/port-shepstone/kwazulu-natal/6344?sp=bd%3d3%26so%3dPriceLow'));
        $this->assertFalse(CrawlRecords::isFiltered('pp', 'https://www.privateproperty.co.za/for-sale/advanced-search?si=1334,1338&sorttype=Default&sortorder=Ascending'));
        $this->assertTrue(CrawlRecords::isFiltered('pp', 'https://www.privateproperty.co.za/for-sale/advanced-search?si=1334&ps=ExcludeSoldOrUnderOffer'));
        $this->assertTrue(CrawlRecords::isFiltered('pp', 'https://www.privateproperty.co.za/for-sale/advanced-search?si=1334&pf=500000'));
        $this->assertTrue(CrawlRecords::isFiltered('p24', ''));
        $this->assertSame('rent', CrawlRecords::listingTypeFromUrl('https://www.property24.com/to-rent/uvongo/margate/kwazulu-natal/9001'));
        $this->assertSame('sale', CrawlRecords::listingTypeFromUrl('https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/9001'));
    }

    public function test_a_saved_search_batch_keeps_one_crawl_record_per_session(): void
    {
        $s = $this->search();
        $l = $this->advert('P24-1');
        $this->seenBy($l, $s);
        $s->forceFill(['crawl_page' => 1])->save();

        $crawl = MicCrawl::query()->where('agency_id', $this->a->id)->first();
        $this->assertNotNull($crawl);
        $this->assertSame($s->crawl_session_id, $crawl->session_id);
        $this->assertSame($this->u->id, (int) $crawl->user_id);
        $this->assertSame('p24', $crawl->portal);
        $this->assertSame(1, $crawl->adverts_seen);
        $this->assertFalse($crawl->is_complete);
        $this->assertFalse($crawl->is_filtered);
        $this->assertTrue($crawl->started_at->lt(now()->subMinutes(4)), 'a crawl starts a few minutes before its first batch');
        $this->assertSame(1, MicCrawl::query()->count(), 'a second save of the same session updates the same row');
    }

    public function test_the_gone_rule_labels_what_a_complete_crawl_did_not_see_and_leaves_everything_else_alone(): void
    {
        $s = $this->search();
        $seen = $this->advert('P24-SEEN');
        $this->seenBy($seen, $s);
        $gone = $this->advert('P24-GONE');
        $underOffer = $this->advert('P24-UO', ['is_active' => false, 'portal_status' => 'under_offer', 'off_market_at' => now()->subDays(5)]);
        $sold = $this->advert('P24-SOLD', ['is_active' => false, 'portal_status' => 'sold', 'off_market_at' => now()->subDays(9)]);
        $otherSuburb = $this->advert('P24-MARGATE', ['suburb' => 'Margate', 'p24_suburb_id' => $this->suburbMargate]);
        $otherPortal = $this->advert('PP-T1', ['portal_source' => 'pp']);
        $rent = $this->advert('P24-RENT', ['listing_type' => 'rent']);
        $otherAgency = $this->advert('P24-GONE', [], $this->b);

        $r = app(SuburbReconcileService::class)->reconcile($this->a->id, 'p24', $s);

        $this->assertSame(2, $r['retired']);
        $this->assertSame(['gone', false, true], $this->advertState($gone));
        $this->assertSame(['probably_sold', false, true], $this->advertState($underOffer));
        $this->assertSame(['active', true, false], $this->advertState($seen));
        $this->assertSame('sold', $this->advertState($sold)[0], 'sold is never relabelled');
        foreach ([$otherSuburb, $otherPortal, $rent, $otherAgency] as $untouched) {
            $this->assertSame(['active', true, false], $this->advertState($untouched));
        }
        $crawl = MicCrawl::query()->where('agency_id', $this->a->id)->first();
        $this->assertTrue($crawl->is_complete);
        $this->assertSame(1, $crawl->gone_count);
        $this->assertSame(1, $crawl->probably_sold_count);
        $this->assertSame([$this->suburbUvongo], DB::table('mic_crawl_suburbs')->where('mic_crawl_id', $crawl->id)->pluck('suburb_id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_a_filtered_search_or_one_that_saw_nothing_retires_nothing(): void
    {
        $filtered = $this->search(['search_url' => 'https://www.property24.com/for-sale/margate/kwazulu-natal/814?sp=pf%3d1500000%26pt%3d2000000']);
        $seen = $this->advert('P24-SEEN');
        $this->seenBy($seen, $filtered);
        $outside = $this->advert('P24-OUTSIDE-THE-PRICE-BAND');

        $r = app(SuburbReconcileService::class)->reconcile($this->a->id, 'p24', $filtered);
        $this->assertSame(0, $r['retired']);
        $this->assertStringContainsString('filtered', (string) $r['skipped_reason']);
        $this->assertSame(['active', true, false], $this->advertState($outside));

        $empty = $this->search();
        $r = app(SuburbReconcileService::class)->reconcile($this->a->id, 'p24', $empty);
        $this->assertSame(0, $r['retired']);
        $this->assertSame(['active', true, false], $this->advertState($outside));
    }

    public function test_a_dry_run_changes_nothing_and_does_not_record_a_complete_crawl(): void
    {
        $s = $this->search();
        $this->seenBy($this->advert('P24-SEEN'), $s);
        $gone = $this->advert('P24-GONE');

        $r = app(SuburbReconcileService::class)->reconcile($this->a->id, 'p24', $s, true);

        $this->assertSame(1, $r['retired']);
        $this->assertSame(['active', true, false], $this->advertState($gone));
        $this->assertFalse((bool) MicCrawl::query()->where('agency_id', $this->a->id)->value('is_complete'));
    }

    public function test_reappearing_clears_the_label_but_an_explicit_status_in_the_same_save_wins(): void
    {
        $gone = $this->advert('P24-GONE', ['is_active' => false, 'portal_status' => 'gone', 'off_market_at' => now()->subDays(3)]);
        $stuck = $this->advert('PP-STUCK', ['portal_source' => 'pp', 'is_active' => false, 'portal_status' => 'withdrawn', 'off_market_at' => now()->subDays(3)]);

        // the importer re-sights both and (no status sent) sets them active
        foreach ([$gone, $stuck] as $l) {
            $l = ProspectingListing::query()->find($l->id);
            $l->last_seen_at = now();
            $l->is_active = true;
            $l->save();
        }
        $this->assertSame(['active', true, false], $this->advertState($gone));
        $this->assertSame(['active', true, false], $this->advertState($stuck));

        // the portal itself says withdrawn: the importer sets is_active = false and the label stays
        $w = $this->advert('P24-W', ['is_active' => false, 'portal_status' => 'withdrawn', 'off_market_at' => now()->subDays(3)]);
        $w = ProspectingListing::query()->find($w->id);
        $w->last_seen_at = now();
        $w->save();
        $this->assertSame('withdrawn', $this->advertState($w)[0]);
    }

    public function test_the_complete_crawl_within_n_days_helper(): void
    {
        $s = $this->search();
        $this->seenBy($this->advert('P24-SEEN'), $s);
        app(SuburbReconcileService::class)->reconcile($this->a->id, 'p24', $s);
        $crawl = MicCrawl::query()->where('agency_id', $this->a->id)->first();
        $crawl->forceFill(['finished_at' => Carbon::parse('2026-10-20 12:00:00')])->save();
        $h = app(CrawlRecords::class);

        $this->assertNotNull($h->completeCrawlWithin($this->a->id, 'p24', $this->suburbUvongo, Carbon::parse('2026-10-22'), 7));
        $this->assertNull($h->completeCrawlWithin($this->a->id, 'p24', $this->suburbUvongo, Carbon::parse('2026-10-30'), 7), 'older than the window');
        $this->assertNull($h->completeCrawlWithin($this->a->id, 'p24', $this->suburbUvongo, Carbon::parse('2026-10-19'), 7), 'finished after the date');
        $this->assertNull($h->completeCrawlWithin($this->a->id, 'p24', $this->suburbMargate, Carbon::parse('2026-10-22'), 7), 'another suburb');
        $this->assertNull($h->completeCrawlWithin($this->a->id, 'pp', $this->suburbUvongo, Carbon::parse('2026-10-22'), 7), 'another portal');
        $this->assertNull($h->completeCrawlWithin($this->b->id, 'p24', $this->suburbUvongo, Carbon::parse('2026-10-22'), 7), 'another agency');
        $this->assertSame(1, $h->lastCrawlBySuburb($this->a->id)->count());
        $this->assertSame(0, $h->lastCrawlBySuburb($this->b->id)->count());
    }

    public function test_the_stuck_withdrawn_back_fill_is_journalled_and_dry_run_writes_nothing(): void
    {
        $stuck = $this->advert('PP-STUCK', ['portal_source' => 'pp', 'is_active' => true, 'portal_status' => 'withdrawn', 'off_market_at' => now()->subDays(3)]);
        $retired = $this->advert('PP-OFF', ['portal_source' => 'pp', 'is_active' => false, 'portal_status' => 'withdrawn', 'off_market_at' => now()->subDays(3)]);
        $dir = sys_get_temp_dir() . '/stuck-' . uniqid();

        Artisan::call('mic:fix-stuck-withdrawn', ['--dry-run' => true, '--journal-dir' => $dir]);
        $this->assertSame('withdrawn', $this->advertState($stuck)[0]);

        Artisan::call('mic:fix-stuck-withdrawn', ['--apply' => true, '--journal-dir' => $dir]);
        $this->assertSame(['active', true, false], $this->advertState($stuck));
        $this->assertSame('withdrawn', $this->advertState($retired)[0], 'an advert that really is off the market is left alone');
        $journal = glob($dir . '/mic-fix-stuck-withdrawn-journal-*.jsonl');
        $this->assertCount(1, $journal);
        $this->assertStringContainsString('"id":' . $stuck->id, (string) file_get_contents($journal[0]));
    }
}
