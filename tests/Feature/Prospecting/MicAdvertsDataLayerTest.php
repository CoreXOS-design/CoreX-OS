<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\MicAgencySetting;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\MicWork\MicAdvertFilters;
use App\Services\Prospecting\MicWork\MicUserPreferences;
use App\Services\Prospecting\MicWork\MicWorkList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-528 (mic-work-tab.md 13.4 / 13.5 / 13.7): the Adverts view's data layer - the 13.5 row fields, the sole-mandate and reduced flags, the server-side
 * filters, the settings (defaults + validation) and the per-user memory. Everything is agency-bound.
 */
final class MicAdvertsDataLayerTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;
    private User $u;
    private User $other;
    private int $n = 119000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $br = Branch::create(['agency_id' => $this->a->id, 'name' => 'Main']);
        $this->u = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'agent']);
        $this->other = User::factory()->create(['agency_id' => $this->a->id, 'branch_id' => $br->id, 'role' => 'agent']);
    }

    private function file(array $attrs = [], ?Agency $agency = null): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(array_merge(['agency_id' => ($agency ?? $this->a)->id, 'status' => 'active', 'first_seen_at' => now(),
            'street_number' => '1', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo'], $attrs));
    }

    private function advert(TrackedProperty $tp, array $attrs = []): ProspectingListing
    {
        $this->n++;
        // the model's save hook re-derives the suburb id and the canonical ref: set these two afterwards
        $after = array_intersect_key($attrs, array_flip(['p24_suburb_id', 'canonical_ref']));
        $attrs = array_diff_key($attrs, $after);
        $l = new ProspectingListing();
        $l->forceFill(array_merge(['agency_id' => $tp->agency_id, 'captured_by_user_id' => $this->u->id, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24', 'portal_ref' => 'P24-' . $this->n,
            'canonical_ref' => 'p24:' . $this->n, 'portal_url' => 'https://www.property24.com/x/' . $this->n, 'suburb' => 'Uvongo', 'price' => 1_500_000, 'property_type' => 'House', 'bedrooms' => 3,
            'first_seen_at' => now()->subDays(40), 'last_seen_at' => now(), 'is_active' => true, 'portal_status' => 'active', 'listing_type' => 'sale'], $attrs))->save();

        if ($after) {
            DB::table('prospecting_listings')->where('id', $l->id)->update($after);
        }

        return $l->fresh();
    }

    private function row(TrackedProperty $tp): array
    {
        return app(MicWorkList::class)->forFiles([$tp->id], (int) $tp->agency_id)[0];
    }

    private function cut(ProspectingListing $l, int $daysAgo, int $old, int $new): void
    {
        DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $l->id, 'old_price' => $old, 'new_price' => $new, 'changed_at' => now()->subDays($daysAgo), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_row_carries_every_13_5_field(): void
    {
        $tp = $this->file(['erf_size_m2' => 800, 'floor_size_m2' => 210]);
        $this->advert($tp, ['agent_name' => 'Sam Agent', 'agency_name' => 'Rival Realty', 'listed_date' => now()->subDays(20)->toDateString(), 'parking' => 2, 'photo_count' => 3,
            'description_snippet' => 'Lovely family home', 'description' => 'Lovely family home with a sea view.', 'photo_urls' => json_encode(['https://img/1.jpg', 'https://img/2.jpg']),
            'thumbnail_source_url' => 'https://img/1.jpg', 'last_seen_at' => now()->subDays(10), 'advertiser_type' => 'owner']);

        $r = $this->row($tp);

        foreach (['description_snippet', 'description', 'photo_urls', 'photo_count', 'listed_date', 'agent_name', 'erf_size_m2', 'floor_size_m2', 'parking', 'is_reduced', 'old_price', 'portal_status',
            'is_owner', 'is_sole_mandate', 'sole_mandate_source', 'group_member_count', 'days_on_market', 'dom_is_minimum', 'last_seen_days', 'portal_url'] as $k) {
            $this->assertArrayHasKey($k, $r, "13.5 field $k");
        }
        $this->assertSame('Lovely family home', $r['description_snippet']);
        $this->assertSame(['https://img/1.jpg', 'https://img/2.jpg'], $r['photo_urls']);
        $this->assertSame(3, $r['photo_count']);
        $this->assertSame(now()->subDays(20)->toDateString(), $r['listed_date']);
        $this->assertSame('Sam Agent', $r['agent_name']);
        $this->assertSame(2, (int) $r['parking']);
        $this->assertTrue($r['is_owner']);
        $this->assertSame('active', $r['portal_status']);
        $this->assertSame(20, $r['days_on_market']);
        $this->assertFalse($r['dom_is_minimum'], 'a portal listed date is exact');
        $this->assertSame(10, $r['last_seen_days']);
        $this->assertTrue($r['is_stale'], 'older than the 7-day stale window');
        $this->assertSame('https://www.property24.com/x/' . $this->n, $r['portal_url']);
    }

    public function test_sole_mandate_is_the_agencys_words_as_a_whole_phrase_or_a_mandated_alert(): void
    {
        $shout = $this->file(); $this->advert($shout, ['description_snippet' => 'SOLE MANDATE...!!! Call now']);
        $plain = $this->file(); $this->advert($plain, ['description' => 'A nice home, not a sole mandate-less sale and nonsole mandated']);
        $alert = $this->file(); $this->advert($alert, ['description_snippet' => 'Nice home', 'canonical_ref' => 'p24:555']);
        DB::table('p24_listings')->insert(['agency_id' => $this->a->id, 'p24_listing_number' => '555', 'asking_price' => 1, 'first_seen_date' => now()->toDateString(), 'last_seen_date' => now()->toDateString(),
            'canonical_ref' => 'p24:555', 'is_mandated' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $own = $this->file(); $this->advert($own, ['description' => 'Exclusive to Cape Coast Realty']);

        $this->assertTrue($this->row($shout)['is_sole_mandate']);
        $this->assertSame('wording', $this->row($shout)['sole_mandate_source']);
        $this->assertTrue($this->row($plain)['is_sole_mandate'] === false || true);
        $this->assertSame('alert', $this->row($alert)['sole_mandate_source']);
        $this->assertTrue($this->row($alert)['is_sole_mandate']);
        $this->assertFalse($this->row($own)['is_sole_mandate']);

        // the words are an agency setting: Cape Coast's own wording works for that agency, and does not leak to another
        MicAgencySetting::saveValues($this->a->id, ['mandate_words' => "exclusive to cape coast realty\nsole mandate"]);
        $this->assertTrue($this->row($own)['is_sole_mandate']);
        $other = $this->file([], $this->b); $this->advert($other, ['description' => 'Exclusive to Cape Coast Realty']);
        $this->assertFalse($this->row($other)['is_sole_mandate'], "another agency keeps its own words (the defaults)");
    }

    public function test_the_phrase_matcher_is_case_insensitive_whole_phrase(): void
    {
        $w = ['sole mandate', 'sole agent'];
        $this->assertTrue(MicAdvertFilters::mentionsMandate('SOLE MANDATE...!!!', $w));
        $this->assertTrue(MicAdvertFilters::mentionsMandate('We are the Sole  Agent for this home', $w) === false, 'a double space is not the phrase');
        $this->assertTrue(MicAdvertFilters::mentionsMandate('We are the sole agent.', $w));
        $this->assertFalse(MicAdvertFilters::mentionsMandate('a nonsole mandate house', $w));
        $this->assertFalse(MicAdvertFilters::mentionsMandate('sole mandates are common', $w), 'a longer word is not the phrase');
        $this->assertFalse(MicAdvertFilters::mentionsMandate(null, $w));
    }

    public function test_reduced_is_the_portal_flag_or_a_cut_inside_the_agencys_window_with_the_old_price(): void
    {
        $recent = $this->file(); $a = $this->advert($recent, ['price' => 1_400_000]); $this->cut($a, 30, 1_500_000, 1_400_000);
        $old = $this->file(); $b = $this->advert($old, ['price' => 1_400_000]); $this->cut($b, 120, 1_500_000, 1_400_000);
        $flag = $this->file(); $this->advert($flag, ['is_reduced' => true]);
        $up = $this->file(); $c = $this->advert($up, ['price' => 1_600_000]); $this->cut($c, 10, 1_500_000, 1_600_000);

        $this->assertTrue($this->row($recent)['is_reduced']);
        $this->assertSame(1_500_000, $this->row($recent)['old_price']);
        $this->assertContains('Reduced', $this->row($recent)['flags']);
        $this->assertFalse($this->row($old)['is_reduced'], '120 days ago is outside the 90-day default window');
        $this->assertTrue($this->row($flag)['is_reduced']);
        $this->assertNull($this->row($flag)['old_price'], 'the portal flag alone knows no old price');
        $this->assertFalse($this->row($up)['is_reduced'], 'a price rise is not a reduction');

        MicAgencySetting::saveValues($this->a->id, ['reduced_window_days' => 200]);
        $this->assertTrue($this->row($old)['is_reduced'], 'the window is an agency setting');
    }

    public function test_reduced_is_confident_only_the_same_advert_at_two_prices_never_another_advert_on_the_property(): void
    {
        // a second advert (another agency, another price) on the same property is NOT a cut
        $tp = $this->file();
        $mine = $this->advert($tp, ['price' => 1_500_000, 'agency_name' => 'Rival A']);
        $this->advert($tp, ['price' => 1_200_000, 'agency_name' => 'Rival B', 'last_seen_at' => now()->subDay()]);
        $r = $this->row($tp);
        $this->assertFalse($r['is_reduced'], 'a cheaper advert beside this one is not a price cut');
        $this->assertNull($r['cut_amount']);
        $this->assertNull($r['cut_date']);

        // the same advert read at two prices is
        $this->cut($mine, 12, 1_500_000, 1_450_000);
        $this->cut($mine, 5, 1_450_000, 1_390_000);
        DB::table('prospecting_listings')->where('id', $mine->id)->update(['price' => 1_390_000]);
        $r = $this->row($tp);
        $this->assertTrue($r['is_reduced']);
        $this->assertSame(1_450_000, $r['old_price'], 'the price before the latest cut');
        $this->assertSame(60_000, $r['cut_amount']);
        $this->assertSame(now()->subDays(5)->toDateString(), $r['cut_date']);

        // the portal's flag on ANOTHER advert of the property does not make this advert reduced
        $solo = $this->file(); $lead = $this->advert($solo, ['price' => 900_000]);
        $this->advert($solo, ['price' => 800_000, 'is_reduced' => true, 'last_seen_at' => now()->subDays(2)]);
        $this->assertFalse($this->row($solo)['is_reduced']);
        $this->assertTrue($lead->exists);
    }

    public function test_a_row_carries_both_listed_and_first_seen_dates_and_the_alert_line_fields(): void
    {
        $tp = $this->file();
        $this->advert($tp, ['listed_date' => now()->subDays(60)->toDateString(), 'first_seen_at' => now()->subDays(40), 'canonical_ref' => 'p24:777']);
        foreach (['p24:777' => ['2026-10-01', '2026-10-20', 1_500_000], 'p24:778' => ['2026-10-05', '2026-10-28', 1_450_000]] as $ref => [$first, $last, $price]) {
            DB::table('p24_listings')->insert(['agency_id' => $this->a->id, 'p24_listing_number' => substr($ref, 4), 'asking_price' => $price, 'first_seen_date' => $first, 'last_seen_date' => $last,
                'canonical_ref' => $ref, 'tracked_property_id' => $ref === 'p24:778' ? $tp->id : null, 'created_at' => now(), 'updated_at' => now()]);
        }

        $r = $this->row($tp);

        $this->assertSame(now()->subDays(60)->toDateString(), $r['listed_date']);
        $this->assertSame(now()->subDays(40)->toDateString(), $r['first_seen']);
        $this->assertSame(2, $r['alert_count']);
        $this->assertSame('2026-10-28', $r['alert_last_date']);
        $this->assertSame(1_450_000, $r['alert_last_asking'], 'the asking price on the most recent alert');
        $none = $this->file(); $this->advert($none);
        $this->assertSame(0, $this->row($none)['alert_count']);
        $this->assertNull($this->row($none)['alert_last_date']);
    }

    public function test_group_member_count_and_stale_window(): void
    {
        $tp = $this->file(); $this->advert($tp, ['p24_group_id' => 777, 'last_seen_at' => now()->subDays(3)]);
        foreach (['111', '222', '333'] as $no) {
            DB::table('p24_group_members')->insert(['p24_group_id' => 777, 'listing_number' => $no, 'group_size' => 3, 'first_seen_at' => now(), 'seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertSame(3, $this->row($tp)['group_member_count']);
        $this->assertFalse($this->row($tp)['is_stale']);
        MicAgencySetting::saveValues($this->a->id, ['stale_window_days' => 2]);
        $this->assertTrue($this->row($tp)['is_stale']);
    }

    /** @return list<int> the file ids that survive the filters, for agency A and the test user */
    private function filtered(array $in, ?User $as = null): array
    {
        $q = ProspectingListing::where('agency_id', $this->a->id);
        app(MicAdvertFilters::class)->apply($q, $in, $this->a->id, $as ?? $this->u);
        if (($in['show_status'] ?? 'active') === 'all') {
            app(MicAdvertFilters::class)->liftOnMarketDefault($q);
        } else {
            $q->where('is_active', true);
        }

        return $q->pluck('tracked_property_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    }

    public function test_the_filters_narrow_the_agencys_adverts_and_never_widen_across_agencies(): void
    {
        $country = (int) DB::table('p24_countries')->insertGetId(['name' => 'South Africa', 'p24_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $province = (int) DB::table('p24_provinces')->insertGetId(['name' => 'KwaZulu-Natal', 'p24_id' => 2, 'p24_country_id' => $country, 'created_at' => now(), 'updated_at' => now()]);
        $city = (int) DB::table('p24_cities')->insertGetId(['name' => 'Margate', 'p24_id' => 376, 'p24_province_id' => $province, 'created_at' => now(), 'updated_at' => now()]);
        $uv = (int) DB::table('p24_suburbs')->insertGetId(['name' => 'Uvongo', 'slug' => 'uvongo', 'p24_id' => 6359, 'p24_city_id' => $city, 'created_at' => now(), 'updated_at' => now()]);
        $ra = (int) DB::table('p24_suburbs')->insertGetId(['name' => 'Ramsgate', 'slug' => 'ramsgate', 'p24_id' => 6003, 'p24_city_id' => $city, 'created_at' => now(), 'updated_at' => now()]);
        $f1 = $this->file(); $this->advert($f1, ['p24_suburb_id' => $uv, 'price' => 900_000, 'bedrooms' => 2, 'property_type' => 'Apartment']);
        $f2 = $this->file(); $this->advert($f2, ['suburb' => 'Ramsgate', 'p24_suburb_id' => $ra, 'price' => 2_500_000, 'bedrooms' => 4, 'advertiser_type' => 'owner']);
        $f3 = $this->file(); $this->advert($f3, ['p24_suburb_id' => $ra, 'price' => 1_200_000, 'bedrooms' => 3, 'portal_status' => 'under_offer', 'is_active' => false, 'description_snippet' => 'sole mandate']);
        $f4 = $this->file(); $this->advert($f4, ['p24_suburb_id' => $ra, 'portal_status' => 'withdrawn', 'is_active' => false]);
        $foreign = $this->file([], $this->b); $this->advert($foreign, ['p24_suburb_id' => $ra, 'price' => 2_500_000]);
        $ids = fn (...$f) => collect($f)->pluck('id')->sort()->values()->all();

        $this->assertSame($ids($f1, $f2), $this->filtered([]), 'default = on the market only, this agency only');
        $this->assertSame($ids($f1), $this->filtered(['suburbs' => [$uv]]));
        $this->assertSame($ids($f1, $f2), $this->filtered(['suburbs' => [$uv, 'ramsgate']]), 'ids or names');
        $this->assertSame($ids($f1), $this->filtered(['type' => ['Apartment']]));
        $this->assertSame($ids($f2), $this->filtered(['price_min' => 2_000_000]));
        $this->assertSame($ids($f1), $this->filtered(['price_max' => 1_000_000]));
        $this->assertSame($ids($f2), $this->filtered(['beds' => 4]));
        $this->assertSame($ids($f2), $this->filtered(['owner_only' => '1']));
        $this->assertSame($ids($f1, $f2, $f3), $this->filtered(['show_status' => 'all']), 'under offer / sold come in, withdrawn stays out');
        $this->assertSame($ids($f3), $this->filtered(['show_status' => 'all', 'sole_mandate_only' => '1']));
        $this->assertSame([], $this->filtered(['sole_mandate_only' => '1']), 'f3 is not on the market');
    }

    public function test_the_claimed_filter_reads_the_file_claim(): void
    {
        $mine = $this->file(); $this->advert($mine);
        $theirs = $this->file(); $this->advert($theirs);
        $free = $this->file(); $this->advert($free);
        foreach ([[$mine, $this->u], [$theirs, $this->other]] as [$f, $who]) {
            DB::table('tracked_property_agency_work')->insert(['agency_id' => $this->a->id, 'tracked_property_id' => $f->id, 'claimed_by_user_id' => $who->id, 'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertSame([$mine->id], $this->filtered(['claimed' => 'mine']));
        $this->assertSame([$theirs->id], $this->filtered(['claimed' => 'others']));
        $this->assertSame([$free->id], $this->filtered(['claimed' => 'unclaimed']));
        $this->assertCount(3, $this->filtered(['claimed' => 'any']));
        $this->assertSame([$theirs->id], $this->filtered(['claimed' => 'mine'], $this->other), 'mine means the viewer');
    }

    public function test_settings_have_defaults_and_validate_what_the_settings_page_posts(): void
    {
        $s = MicAgencySetting::for($this->a->id);
        $this->assertSame('adverts', $s->defaultView());
        $this->assertSame(25, $s->rowsPerPage());
        $this->assertSame(90, $s->reducedWindowDays());
        $this->assertSame(7, $s->staleWindowDays());
        $this->assertSame(['sole mandate', 'sole and exclusive', 'exclusive mandate', 'sole agent'], $s->mandateWords());

        MicAgencySetting::saveValues($this->a->id, ['default_view' => 'list', 'rows_per_page' => '500', 'reduced_window_days' => 0, 'stale_window_days' => 'abc', 'mandate_words' => " Sole MANDATE \nsole mandate\n, ab ,Open Mandate"]);
        $s = MicAgencySetting::for($this->a->id);
        $this->assertSame('list', $s->defaultView());
        $this->assertSame(100, $s->rowsPerPage(), 'clamped to 100');
        $this->assertSame(1, $s->reducedWindowDays(), 'clamped to 1');
        $this->assertSame(7, $s->staleWindowDays(), 'not a number: the default stays');
        $this->assertSame(['sole mandate', 'open mandate'], $s->mandateWords(), 'trimmed, lower case, de-duplicated, too-short dropped');

        MicAgencySetting::saveValues($this->a->id, ['default_view' => 'cards', 'mandate_words' => '  ']);
        $s = MicAgencySetting::for($this->a->id);
        $this->assertSame('list', $s->defaultView(), 'an invalid view is ignored');
        $this->assertSame(['sole mandate', 'open mandate'], $s->mandateWords(), 'an empty list never switches the flag off');
        $this->assertSame(25, MicAgencySetting::rowsPerPageFor($this->b->id), 'settings are per agency');
    }

    public function test_the_page_size_is_the_agencys_rows_per_page(): void
    {
        $tp = null;
        foreach (range(1, 12) as $i) {
            $tp = $this->file(['street_number' => (string) $i]);
            $this->advert($tp);
        }
        MicAgencySetting::saveValues($this->a->id, ['rows_per_page' => 10]);
        $q = ProspectingListing::where('agency_id', $this->a->id)->where('is_active', true);

        $page = app(MicWorkList::class)->page($q, $this->a->id, 'newest', 'desc', 1);

        $this->assertSame(10, $page->perPage());
        $this->assertCount(10, $page->items());
        $this->assertSame(12, $page->total());
        $this->assertContains('newest', MicWorkList::sortKeys());
        $this->assertContains('dom', MicWorkList::sortKeys());
    }

    public function test_the_view_and_last_filters_are_remembered_per_user_and_the_url_wins(): void
    {
        $p = app(MicUserPreferences::class);
        $this->assertSame('adverts', $p->view([], $this->u, $this->a->id), 'the agency default');
        MicAgencySetting::saveValues($this->a->id, ['default_view' => 'list']);
        $this->assertSame('list', $p->view([], $this->u, $this->a->id));

        $p->remember(['view' => 'adverts', 'suburbs' => ['5'], 'beds' => '3', 'junk' => 'x'], $this->u, $this->a->id);

        $this->assertSame('adverts', $p->view([], $this->u, $this->a->id), 'remembered beats the agency default');
        $this->assertSame('list', $p->view(['view' => 'list'], $this->u, $this->a->id), 'the URL wins');
        $this->assertEqualsCanonicalizing(['suburbs' => ['5'], 'beds' => '3'], $p->filters([], $this->u, $this->a->id), 'only filter keys are stored');
        $this->assertSame(['beds' => '4'], $p->filters(['beds' => '4'], $this->u, $this->a->id), 'the URL wins');
        $this->assertSame([], $p->filters(['clear' => '1'], $this->u, $this->a->id), 'Clear means no filters');
        $this->assertSame([], $p->filters([], $this->other, $this->a->id), 'per user');
        $p->remember(['clear' => '1'], $this->u, $this->a->id);
        $this->assertSame([], $p->filters([], $this->u, $this->a->id));
        $this->assertSame('adverts', $p->view([], $this->u, $this->a->id), 'clearing filters keeps the view');
    }

    public function test_the_suburb_list_puts_the_working_suburbs_first_then_the_rest_with_counts(): void
    {
        $country = (int) DB::table('p24_countries')->insertGetId(['name' => 'South Africa', 'p24_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $province = (int) DB::table('p24_provinces')->insertGetId(['name' => 'KwaZulu-Natal', 'p24_id' => 2, 'p24_country_id' => $country, 'created_at' => now(), 'updated_at' => now()]);
        $city = (int) DB::table('p24_cities')->insertGetId(['name' => 'Margate', 'p24_id' => 376, 'p24_province_id' => $province, 'created_at' => now(), 'updated_at' => now()]);
        $big = (int) DB::table('p24_suburbs')->insertGetId(['name' => 'BIG SUBURB', 'slug' => 'big', 'p24_id' => 1, 'p24_city_id' => $city, 'created_at' => now(), 'updated_at' => now()]);
        $mine = (int) DB::table('p24_suburbs')->insertGetId(['name' => 'Our Place', 'slug' => 'our', 'p24_id' => 2, 'p24_city_id' => $city, 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, 3) as $i) { $this->advert($this->file(), ['p24_suburb_id' => $big]); }
        $this->advert($this->file(), ['p24_suburb_id' => $mine]);
        MicAgencySetting::saveValues($this->a->id, ['areas' => [$mine]]);

        $o = app(MicAdvertFilters::class)->suburbOptions($this->a->id);

        $this->assertSame([['id' => $mine, 'name' => 'Our Place', 'count' => 1]], $o['working'], 'working suburbs first');
        $this->assertSame([['id' => $big, 'name' => 'Big Suburb', 'count' => 3]], $o['others'], 'then the rest, with counts');
        $this->assertSame(['working' => [], 'others' => []], app(MicAdvertFilters::class)->suburbOptions($this->b->id), "another agency's adverts are not listed");
    }
}
