<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\MicAgencySetting;
use App\Models\P24City;
use App\Models\P24Country;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Observers\MicSuburbIdObserver;
use App\Services\Address\SuburbResolver;
use App\Services\MarketIntelligence\Dom\DomEstimator;
use App\Services\MarketIntelligence\Pockets\MicStatsBuilder;
use App\Services\MarketIntelligence\Pockets\PriceBand;
use App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/mic-work-tab.md 11.1, step 5.5 (AT-519): stored per-file counts + pocket stats, agency-walled, cold/lost buyers not counted,
 * and the days-on-market rule (exact / ~approx / N+ minimum).
 */
final class MicStatsBuilderTest extends TestCase
{
    use RefreshDatabase;

    private int $a;
    private int $b;
    private User $agent;
    private int $uvongo;

    protected function setUp(): void
    {
        parent::setUp();
        MicSuburbIdObserver::flush();
        SuburbResolver::flush();
        PropertyTypeResolver::flush();

        $country = P24Country::create(['p24_id' => 1, 'name' => 'South Africa']);
        $province = P24Province::create(['p24_id' => 2, 'p24_country_id' => $country->id, 'name' => 'KwaZulu Natal']);
        $city = P24City::create(['p24_id' => 376, 'p24_province_id' => $province->id, 'name' => 'Ray Nkonyeni']);
        $this->uvongo = P24Suburb::create(['p24_id' => 6359, 'p24_city_id' => $city->id, 'name' => 'Uvongo', 'slug' => 'uvongo'])->id;

        $this->a = $this->agency();
        $this->b = $this->agency();
        $this->agent = User::factory()->create(['agency_id' => $this->a, 'branch_id' => $this->a, 'role' => 'admin']);
    }

    private function agency(): int
    {
        $id = (int) DB::table('agencies')->insertGetId(['name' => 'T ' . Str::random(6), 'slug' => 't-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $id, 'agency_id' => $id, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function file(int $agency): int
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(['agency_id' => $agency, 'status' => 'active', 'suburb' => 'Uvongo', 'p24_suburb_id' => $this->uvongo, 'property_type' => 'House', 'bedrooms' => 3])->id;
    }

    private function advert(int $agency, int $tp, string $ref, array $extra = []): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id' => $agency, 'captured_by_user_id' => $this->agent->id, 'tracked_property_id' => $tp, 'portal_source' => 'p24', 'portal_ref' => $ref,
            'listing_type' => 'sale', 'suburb' => 'Uvongo', 'p24_suburb_id' => $this->uvongo, 'price' => 800_000, 'bedrooms' => 3, 'property_type' => 'House',
            'is_active' => 1, 'first_seen_at' => now()->subDays(100), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function buyer(int $agency, string $state): int
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agency, 'branch_id' => $agency, 'created_by_user_id' => $this->agent->id, 'is_buyer' => true, 'buyer_state' => $state,
            'first_name' => 'B', 'last_name' => 'Buyer ' . Str::random(4), 'phone' => '082' . random_int(1000000, 9999999), 'email' => Str::random(6) . '@e.co.za',
        ])->id;
    }

    private function match(int $listingId, int $contactId, int $agency, int $score): void
    {
        DB::table('prospecting_buyer_matches')->insert([
            'prospecting_listing_id' => $listingId, 'contact_id' => $contactId, 'agency_id' => $agency, 'score' => $score,
            'tier' => $score >= 80 ? 'strong' : 'approximate', 'matched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_file_counts_are_stored_per_agency_and_cold_buyers_are_not_demand(): void
    {
        $tp = $this->file($this->a);
        $live = $this->advert($this->a, $tp, 'P24-1');
        $dead = $this->advert($this->a, $tp, 'P24-0', ['is_active' => 0, 'first_seen_at' => now()->subDays(200)]);
        DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $live, 'old_price' => 900_000, 'new_price' => 800_000, 'changed_at' => now()->subDays(10), 'created_at' => now(), 'updated_at' => now()]);

        $this->match($live, $this->buyer($this->a, 'new'), $this->a, 85);
        $this->match($live, $this->buyer($this->a, 'warm'), $this->a, 60);
        $this->match($live, $this->buyer($this->a, 'cold'), $this->a, 90);   // cold: not demand

        $other = $this->file($this->b);
        $this->advert($this->b, $other, 'P24-9');

        $r = app(MicStatsBuilder::class)->refreshAgency($this->a);
        $this->assertSame(1, $r['files']);

        $s = DB::table('mic_file_stats')->where('agency_id', $this->a)->where('tracked_property_id', $tp)->first();
        $this->assertSame(1, (int) $s->buyers_strong);
        $this->assertSame(1, (int) $s->buyers_mid);
        $this->assertSame(1, (int) $s->cuts_count);
        $this->assertSame(100_000, (int) $s->cut_amount);
        $this->assertSame(1, (int) $s->cuts_30d);
        $this->assertSame(1, (int) $s->relisted, 'a dead advert and a live one on the same portal = re-listed');
        $this->assertSame(1, (int) $s->adverts_active);
        $this->assertSame('house', $s->type_key);
        $this->assertSame(3, (int) $s->beds);
        $this->assertSame('lt1m', $s->band);
        $this->assertSame('minimum', $s->dom_mode, 'no crawl record yet: every advert is an N+ minimum');
        $this->assertSame(100, (int) $s->dom_days);

        $this->assertSame(0, DB::table('mic_file_stats')->where('agency_id', $this->b)->count(), 'another agency is never touched');
    }

    public function test_pocket_stats_count_market_stock_aged_cuts_and_buyers_waiting(): void
    {
        $tp = $this->file($this->a);
        $live = $this->advert($this->a, $tp, 'P24-1');
        DB::table('prospecting_price_history')->insert(['prospecting_listing_id' => $live, 'old_price' => 900_000, 'new_price' => 800_000, 'changed_at' => now()->subDays(10), 'created_at' => now(), 'updated_at' => now()]);
        $buyer = $this->buyer($this->a, 'new');
        ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $this->a, 'contact_id' => $buyer, 'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
            'p24_suburb_ids' => [$this->uvongo], 'property_types' => ['House'], 'price_min' => 600_000, 'price_max' => 950_000, 'beds_min' => 2,
        ]);
        $coldBuyer = $this->buyer($this->a, 'lost');
        ContactMatch::withoutGlobalScopes()->create([
            'agency_id' => $this->a, 'contact_id' => $coldBuyer, 'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
            'p24_suburb_ids' => [$this->uvongo], 'property_types' => ['House'], 'price_max' => 950_000,
        ]);

        app(MicStatsBuilder::class)->refreshAgency($this->a);

        $p = DB::table('mic_pocket_stats')->where('agency_id', $this->a)->where('p24_suburb_id', $this->uvongo)->where('type_key', 'house')->where('beds', 3)->where('band', 'lt1m')->first();
        $this->assertNotNull($p);
        $this->assertSame(1, (int) $p->market_stock);
        $this->assertSame(1, (int) $p->aged_90, '100 days >= 90');
        $this->assertSame(0, (int) $p->aged_180);
        $this->assertSame(1, (int) $p->cut_30);
        $this->assertSame(1, (int) $p->buyers, 'the lost buyer is not demand');
        $this->assertSame(0, DB::table('mic_pocket_stats')->where('agency_id', $this->b)->count());

        // running again the same day replaces, never duplicates
        app(MicStatsBuilder::class)->refreshAgency($this->a);
        $this->assertSame(1, DB::table('mic_pocket_stats')->where('agency_id', $this->a)->where('type_key', 'house')->count());
    }

    public function test_days_on_market_rule_exact_approx_and_minimum(): void
    {
        $dom = app(DomEstimator::class);
        $now = Carbon::parse('2026-10-31 12:00:00');
        $mk = fn (array $o) => (object) array_merge(['portal_source' => 'p24', 'listed_date' => null, 'first_seen_at' => null, 'p24_suburb_id' => $this->uvongo], $o);

        $exact = $dom->forAdvert($this->a, $mk(['listed_date' => '2026-10-01', 'first_seen_at' => '2026-10-20 08:00:00']), 7, $now);
        $this->assertSame(['days' => 30, 'mode' => 'listed', 'label' => '30 days'], array_intersect_key($exact->toArray(), array_flip(['days', 'mode', 'label'])));

        $none = $dom->forAdvert($this->a, $mk(['first_seen_at' => '2026-09-21 08:00:00']), 7, $now);
        $this->assertSame('minimum', $none->mode);
        $this->assertSame('40+ days', $none->label);

        DB::table('mic_crawls')->insert([
            'agency_id' => $this->a, 'user_id' => $this->agent->id, 'session_id' => 's1', 'portal' => 'p24', 'listing_type' => 'sale',
            'search_url' => 'https://www.property24.com/for-sale/uvongo/x', 'started_at' => '2026-09-20 08:00:00', 'finished_at' => '2026-09-20 09:00:00',
            'pages_read' => 5, 'pages_total' => 5, 'adverts_seen' => 100, 'is_complete' => 1, 'is_filtered' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $crawlId = (int) DB::table('mic_crawls')->value('id');
        DB::table('mic_crawl_suburbs')->insert(['mic_crawl_id' => $crawlId, 'agency_id' => $this->a, 'portal' => 'p24', 'suburb_id' => $this->uvongo, 'suburb_name' => 'Uvongo', 'created_at' => now(), 'updated_at' => now()]);

        $dom = app()->make(DomEstimator::class);   // a fresh run: the estimator memoises crawl lookups per run
        $approx = $dom->forAdvert($this->a, $mk(['first_seen_at' => '2026-09-21 08:00:00']), 7, $now);
        $this->assertSame('approx', $approx->mode, 'a complete crawl finished 23 h before it first appeared');
        $this->assertSame('~40 days', $approx->label);

        $gap = $dom->forAdvert($this->a, $mk(['first_seen_at' => '2026-10-05 08:00:00']), 7, $now);
        $this->assertSame('minimum', $gap->mode, 'crawl gap longer than the window');
        $this->assertSame('26+ days', $gap->label);

        $this->assertSame('unknown', $dom->forAdvert($this->a, $mk([]), 7, $now)->mode);
    }

    public function test_price_bands_and_agency_settings_defaults(): void
    {
        $edges = [1_000_000, 2_000_000, 3_000_000, 5_000_000];
        $this->assertSame(['lt1m', '1mto2m', '2mto3m', '3mto5m', 'gte5m'], PriceBand::keys($edges));
        $this->assertSame('lt1m', PriceBand::key(999_999, $edges));
        $this->assertSame('1mto2m', PriceBand::key(1_000_000, $edges));
        $this->assertSame('gte5m', PriceBand::key(9_000_000, $edges));
        $this->assertSame('none', PriceBand::key(null, $edges));

        $s = MicAgencySetting::for($this->a);
        $this->assertSame(['seller' => 30, 'buyer' => 25, 'pocket' => 20, 'known' => 15, 'reachable' => 10], $s->scoreWeights());
        $this->assertSame(40, $s->detailPagesPerSession());
        $this->assertSame(45, $s->fastSaleDays());
        $this->assertSame(range(1, 11), $s->tilesShown());
        $this->assertSame(0, MicAgencySetting::query()->count(), 'reading settings never writes');

        $bad = new MicAgencySetting(['agency_id' => $this->a, 'values' => ['score_weights' => ['seller' => 50, 'buyer' => 50, 'pocket' => 20, 'known' => 15, 'reachable' => 10]]]);
        $this->assertSame(30, $bad->scoreWeights()['seller'], 'weights that do not sum to 100 fall back to the defaults');
    }
}
