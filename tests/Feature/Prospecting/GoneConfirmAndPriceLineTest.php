<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\MicCrawl;
use App\Models\ProspectingListing;
use App\Models\ProspectingSearch;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\MicWork\MicWorkList;
use App\Services\Prospecting\SuburbReconcileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-520 (conductor, 1 Nov 22:42, 76 Colin Street): (1) an advert is only set gone when a COMPLETE read of its OWN suburb missed it, on two crawls running (agency setting
 * gone_confirm_crawls, default 2), and a sighting resets the count; adverts wrongly set gone can be put back (journalled). (2) A price line never shows a cut once the
 * price has risen back to or past what it was before that cut.
 */
final class GoneConfirmAndPriceLineTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private User $u;
    private int $uvongo = 9101;
    private int $margate = 9102;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $br = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->u = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'agent']);
        DB::table('p24_suburbs')->insert([
            ['id' => $this->uvongo, 'name' => 'Uvongo', 'slug' => 'uvongo', 'p24_id' => 6359, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->margate, 'name' => 'Margate', 'slug' => 'margate', 'p24_id' => 814, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function search(): ProspectingSearch
    {
        $s = new ProspectingSearch();
        $s->forceFill(['agency_id' => $this->a->id, 'user_id' => $this->u->id, 'portal_source' => 'p24', 'search_url' => 'https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359',
            'search_description' => 'Uvongo', 'total_results' => 10, 'pages_captured' => 1, 'listing_count' => 3, 'captured_at' => now(), 'crawl_session_id' => (string) \Illuminate\Support\Str::uuid(),
            'crawl_portal' => 'p24', 'crawl_page' => 1, 'crawl_pages_total' => 1])->save();

        return $s->fresh();
    }

    private function advert(string $ref, int $suburb, array $attrs = []): ProspectingListing
    {
        $l = new ProspectingListing();
        $l->forceFill(array_merge(['agency_id' => $this->a->id, 'captured_by_user_id' => $this->u->id, 'portal_source' => 'p24', 'portal_ref' => $ref, 'portal_url' => 'https://www.property24.com/x/' . $ref,
            'suburb' => $suburb === $this->uvongo ? 'Uvongo' : 'Margate', 'price' => 1_500_000, 'first_seen_at' => now()->subMonths(2), 'last_seen_at' => now()->subDays(20), 'is_active' => true,
            'portal_status' => 'active', 'listing_type' => 'sale'], $attrs))->save();
        DB::table('prospecting_listings')->where('id', $l->id)->update(['p24_suburb_id' => $suburb]);

        return $l->fresh();
    }

    private function seen(ProspectingListing $l, ProspectingSearch $s): void
    {
        DB::table('prospecting_listings')->where('id', $l->id)->update(['last_seen_at' => now(), 'last_search_id' => $s->id]);
    }

    private function state(ProspectingListing $l): array
    {
        $r = DB::table('prospecting_listings')->where('id', $l->id)->first();

        return [$r->portal_status, (bool) $r->is_active, (int) $r->missed_crawls];
    }

    public function test_one_complete_crawl_that_misses_an_advert_only_counts_it_two_in_a_row_set_it_gone_and_a_sighting_resets(): void
    {
        $missed = $this->advert('P24-1', $this->uvongo);
        $s1 = $this->search();
        $this->seen($this->advert('P24-SEEN', $this->uvongo), $s1);
        $r1 = app(SuburbReconcileService::class)->reconcile($this->a->id, 'p24', $s1);
        $this->assertSame(0, $r1['retired'], 'first miss: still on the market');
        $this->assertSame(['active', true, 1], $this->state($missed));

        // the same crawl handled twice does not count twice
        $crawl = MicCrawl::query()->where('agency_id', $this->a->id)->first();
        app(\App\Services\Prospecting\GoneRule::class)->apply($crawl);
        $this->assertSame(['active', true, 1], $this->state($missed));

        $s2 = $this->search();
        $this->seen(ProspectingListing::where('portal_ref', 'P24-SEEN')->first(), $s2);
        $r2 = app(SuburbReconcileService::class)->reconcile($this->a->id, 'p24', $s2);
        $this->assertSame(1, $r2['retired'], 'second complete crawl in a row missed it: now gone');
        $this->assertSame('gone', $this->state($missed)[0]);

        // a sighting in between wipes the count
        $kept = $this->advert('P24-2', $this->uvongo);
        $s3 = $this->search();
        $this->seen($this->advert('P24-SEEN3', $this->uvongo), $s3);
        app(SuburbReconcileService::class)->reconcile($this->a->id, 'p24', $s3);
        $this->assertSame(1, $this->state($kept)[2]);
        $kept = ProspectingListing::find($kept->id);
        $kept->last_seen_at = now();
        $kept->save();
        $this->assertSame(0, (int) DB::table('prospecting_listings')->where('id', $kept->id)->value('missed_crawls'), 'seen again: the miss count is cleared');
    }

    public function test_a_neighbouring_suburb_read_on_a_uvongo_search_is_not_a_covered_suburb(): void
    {
        $uvongoMissed = $this->advert('P24-UV', $this->uvongo);
        $margateOther = $this->advert('P24-MG', $this->margate);
        \App\Models\MicAgencySetting::saveValues($this->a->id, ['gone_confirm_crawls' => 1]);
        $s = $this->search();
        $this->seen($this->advert('P24-SEEN-UV', $this->uvongo), $s);
        $this->seen($this->advert('P24-SEEN-MG', $this->margate), $s);   // a Margate advert shown among the Uvongo results

        $r = app(SuburbReconcileService::class)->reconcile($this->a->id, 'p24', $s);

        $this->assertSame(1, $r['retired']);
        $this->assertSame('gone', $this->state($uvongoMissed)[0], "Uvongo was read completely: its unseen advert is gone");
        $this->assertSame('active', $this->state($margateOther)[0], 'Margate was only glimpsed: its unseen advert is untouched');
        $this->assertSame(1, (int) MicCrawl::query()->first()->suburbs()->where('suburb_id', $this->margate)->count(), 'the crawl still records that it glimpsed Margate');
    }

    public function test_adverts_wrongly_set_gone_can_be_put_back_from_what_was_seen_live_and_the_journal_undoes_it(): void
    {
        $gone = $this->advert('P24-G', $this->uvongo, ['is_active' => false, 'portal_status' => 'gone', 'off_market_at' => now()->subDay(), 'last_seen_at' => now()->subDays(20)]);
        $ps = $this->advert('P24-PS', $this->uvongo, ['is_active' => false, 'portal_status' => 'probably_sold', 'off_market_at' => now()->subDay()]);
        $sold = $this->advert('P24-SOLD', $this->uvongo, ['is_active' => false, 'portal_status' => 'sold']);
        $dir = sys_get_temp_dir() . '/restore-gone-' . uniqid();
        mkdir($dir);
        $refs = $dir . '/refs.tsv';
        file_put_contents($refs, "P24-G\t2026-11-01 22:50:00\nP24-PS\t2026-11-01 22:50:00\nP24-SOLD\t2026-11-01 22:50:00\n");

        $this->artisan('mic:restore-gone', ['--agency' => $this->a->id, '--refs-file' => $refs, '--report-dir' => $dir])->assertSuccessful();
        $this->assertSame('gone', $this->state($gone)[0], 'dry run');

        $this->artisan('mic:restore-gone', ['--agency' => $this->a->id, '--refs-file' => $refs, '--apply' => true, '--report-dir' => $dir])->assertSuccessful();
        $this->assertSame(['active', true, 0], $this->state($gone));
        $this->assertSame('under_offer', $this->state($ps)[0], 'probably sold goes back to under offer');
        $this->assertSame('sold', $this->state($sold)[0], 'sold is never touched');
        $this->assertSame('2026-11-01 22:50:00', (string) DB::table('prospecting_listings')->where('id', $gone->id)->value('last_seen_at'), 'seen live: no more "not seen since"');
        $this->assertNull(DB::table('prospecting_listings')->where('id', $gone->id)->value('off_market_at'));

        $journal = glob($dir . '/restore-gone-agency' . $this->a->id . '-journal-*.jsonl')[0];
        $this->artisan('mic:restore-gone', ['--undo' => $journal])->assertSuccessful();
        $this->assertSame('gone', $this->state($gone)[0]);
        $this->assertSame('probably_sold', $this->state($ps)[0]);
    }

    private function colinStreet(array $history, int $priceNow, string $ref = 'P24-115914865'): array
    {
        $tp = TrackedProperty::queryWithoutAgencyScope()->create(['agency_id' => $this->a->id, 'status' => 'active', 'first_seen_at' => now(), 'street_number' => '76', 'street_name' => 'Colin Street', 'suburb' => 'Uvongo']);
        $l = $this->advert($ref, $this->uvongo, ['tracked_property_id' => $tp->id, 'price' => $priceNow]);
        foreach ($history as [$daysAgo, $old, $new]) {
            DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $l->id, 'old_price' => $old, 'new_price' => $new, 'changed_at' => now()->subDays($daysAgo), 'created_at' => now(), 'updated_at' => now()]);
        }

        return app(MicWorkList::class)->forFiles([$tp->id], $this->a->id)[0];
    }

    public function test_76_colin_street_a_cut_then_a_rise_past_the_old_price_shows_no_cut(): void
    {
        // 1,399,000 -> 1,350,000 (cut of 49,000, 10 Aug) -> 1,420,000 (a rise, 31 Oct): the card read "R1,420,000, old R1,399,000, cut R49,000"
        $r = $this->colinStreet([[84, 1_399_000, 1_350_000], [1, 1_350_000, 1_420_000]], 1_420_000);
        $this->assertFalse($r['is_reduced']);
        $this->assertNull($r['old_price']);
        $this->assertNull($r['cut_amount']);
        $this->assertNull($r['cut_date']);
    }

    public function test_a_standing_cut_shows_the_price_before_it_and_the_amount_off_the_price_now(): void
    {
        $r = $this->colinStreet([[40, 1_500_000, 1_300_000]], 1_300_000);
        $this->assertTrue($r['is_reduced']);
        $this->assertSame(1_500_000, $r['old_price']);
        $this->assertSame(200_000, $r['cut_amount'], 'old - new');

        // a cut, a small rise that stays under the old price: still reduced, and the amount is old - price now
        $tp2 = $this->colinStreet([[40, 1_500_000, 1_300_000], [10, 1_300_000, 1_400_000]], 1_400_000, 'P24-115914866');
        $this->assertTrue($tp2['is_reduced']);
        $this->assertSame(1_500_000, $tp2['old_price']);
        $this->assertSame(100_000, $tp2['cut_amount']);
    }
}
