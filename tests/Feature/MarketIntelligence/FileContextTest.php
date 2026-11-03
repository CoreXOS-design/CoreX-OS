<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Observers\MicSuburbIdObserver;
use App\Services\Address\SuburbResolver;
use App\Services\MarketIntelligence\Context\FileContext;
use App\Services\MarketIntelligence\Pockets\MicStatsBuilder;
use App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** .ai/specs/mic-work-tab.md 11.1b, step 5.9 (AT-519): market context per file - stored, agency-walled, never guessed below n = 3. */
final class FileContextTest extends TestCase
{
    use RefreshDatabase;

    private int $a;
    private int $b;
    private User $agent;
    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();
        MicSuburbIdObserver::flush();
        SuburbResolver::flush();
        PropertyTypeResolver::flush();
        $this->a = $this->agency();
        $this->b = $this->agency();
        $this->agent = User::factory()->create(['agency_id' => $this->a, 'branch_id' => $this->a, 'role' => 'admin', 'is_active' => true]);
        $country = DB::table('p24_countries')->insertGetId(['p24_id' => 1, 'name' => 'SA', 'created_at' => now(), 'updated_at' => now()]);
        $prov = DB::table('p24_provinces')->insertGetId(['p24_id' => 2, 'p24_country_id' => $country, 'name' => 'KZN', 'created_at' => now(), 'updated_at' => now()]);
        $city = DB::table('p24_cities')->insertGetId(['p24_id' => 376, 'p24_province_id' => $prov, 'name' => 'Ray', 'created_at' => now(), 'updated_at' => now()]);
        $this->sub = DB::table('p24_suburbs')->insertGetId(['p24_id' => 6359, 'p24_city_id' => $city, 'name' => 'Uvongo', 'slug' => 'uvongo', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function agency(): int
    {
        $id = (int) DB::table('agencies')->insertGetId(['name' => 'T ' . Str::random(6), 'slug' => 't-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $id, 'agency_id' => $id, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function comp(int $agency, array $o = []): int
    {
        return (int) DB::table('market_report_comp_rows')->insertGetId(array_merge([
            'agency_id' => $agency, 'row_index' => 0, 'row_type' => 'comp', 'p24_suburb_id' => $this->sub, 'address' => '5 Newport Road', 'property_type' => 'Residential',
            'extent_m2' => 800, 'sale_date' => now()->subMonths(3)->toDateString(), 'sale_price' => 800_000, 'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function tracked(array $attrs): int
    {
        $id = TrackedProperty::queryWithoutAgencyScope()->create($attrs)->id;
        DB::table('tracked_properties')->where('id', $id)->update(['p24_suburb_id' => $this->sub]);   // derived column: set by the 5.1 backfill, not mass-assignable

        return $id;
    }

    private function file(int $agency, array $tp = []): int
    {
        return $this->tracked(array_merge(['agency_id' => $agency, 'status' => 'active', 'suburb' => 'Uvongo', 'p24_suburb_id' => $this->sub,
            'property_type' => 'House', 'bedrooms' => 3, 'street_number' => '13', 'street_name' => 'Newport Road'], $tp));
    }

    private function advert(int $agency, int $tp, array $o = []): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id' => $agency, 'captured_by_user_id' => $this->agent->id, 'tracked_property_id' => $tp, 'portal_source' => 'p24', 'portal_ref' => 'P24-' . Str::random(6),
            'listing_type' => 'sale', 'suburb' => 'Uvongo', 'p24_suburb_id' => $this->sub, 'price' => 1_200_000, 'bedrooms' => 3, 'property_type' => 'House', 'erf_size_m2' => 1000,
            'is_active' => 1, 'first_seen_at' => now()->subDays(10), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function row(int $agency, int $tp): ?array
    {
        return app(FileContext::class)->forFiles($agency, [$tp])[$tp] ?? null;
    }

    public function test_suburb_figures_own_r_per_m2_and_nearby_sales_for_a_freehold_house(): void
    {
        // 3 canonical CMA sales (R800k/800m2, R900k/800m2, R1m/800m2) + 1 duplicate (excluded) + 1 older than 12 m + one deeds sale
        $first = $this->comp($this->a, ['sale_price' => 800_000, 'sale_date' => now()->subMonths(5)->toDateString()]);
        $this->comp($this->a, ['sale_price' => 900_000, 'sale_date' => now()->subMonths(4)->toDateString(), 'address' => '7 Newport Road']);
        $this->comp($this->a, ['sale_price' => 1_000_000, 'sale_date' => now()->subMonths(2)->toDateString(), 'address' => '9 Other Street']);
        $this->comp($this->a, ['sale_price' => 800_000, 'sale_date' => now()->subMonths(5)->toDateString(), 'duplicate_of_id' => $first]);
        $this->comp($this->a, ['sale_price' => 700_000, 'sale_date' => now()->subMonths(18)->toDateString(), 'address' => '1 Newport Road']);
        $this->tracked(['agency_id' => $this->a, 'status' => 'active', 'p24_suburb_id' => $this->sub, 'property_type' => 'House', 'street_number' => '3', 'street_name' => 'Newport Road',
            'deeds_captured_at' => now(), 'last_known_sold_price' => 1_100_000, 'last_known_sold_date' => now()->subMonth()->toDateString(), 'erf_size_m2' => 1000]);
        $other = $this->comp($this->b, ['sale_price' => 5_000_000]);   // another agency's report: never used

        $tp = $this->file($this->a);
        $this->advert($this->a, $tp);
        app(MicStatsBuilder::class)->refreshAgency($this->a);

        $c = $this->row($this->a, $tp);
        $this->assertSame('freehold', $c['sale_class']);
        $this->assertSame(4, $c['suburb_avg_sold_n'], '3 canonical CMA + 1 deeds sale in 12 months; the duplicate and the 18-month-old sale are out');
        $this->assertSame(950_000, $c['suburb_avg_sold_12m']);
        $this->assertSame(1119, $c['suburb_r_per_m2']);    // (1000 + 1125 + 1250 + 1100) / 4
        $this->assertSame('erf', $c['suburb_r_per_m2_basis']);
        $this->assertSame(1000, $c['own_size_m2']);
        $this->assertSame(1200, $c['own_r_per_m2']);
        $this->assertSame(7.2, $c['own_vs_suburb_pct']);
        $this->assertSame(26.3, $c['asking_vs_avg_sold_pct']);

        $this->assertCount(3, $c['nearby_sales']);
        $this->assertSame(['street', 'street', 'street'], array_column($c['nearby_sales'], 'match'), 'same street comes first');
        $dates = array_column($c['nearby_sales'], 'sold_on');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates, 'newest first');
        $this->assertContains('deeds', array_column($c['nearby_sales'], 'source'));
        $this->assertNotContains(5_000_000, array_column($c['nearby_sales'], 'price'));
        $this->assertNull(app(FileContext::class)->forFiles($this->b, [$tp])[$tp] ?? null, 'another agency cannot read this agency\'s context');
        $this->assertNotNull($other);
    }

    public function test_below_three_sales_the_figures_are_null_and_never_guessed(): void
    {
        $this->comp($this->a, ['scheme_name' => 'SS Sea Breeze', 'section_number' => '4', 'extent_m2' => 70, 'sale_price' => 700_000, 'address' => 'Unit 4 Sea Breeze']);
        $this->comp($this->a, ['scheme_name' => 'SS Sea Breeze', 'section_number' => '9', 'extent_m2' => 80, 'sale_price' => 800_000, 'address' => 'Unit 9 Sea Breeze']);

        $tp = $this->file($this->a, ['property_type' => 'Apartment', 'complex_name' => 'Sea Breeze', 'street_name' => 'Beach Road']);
        $this->advert($this->a, $tp, ['price' => 750_000, 'property_type' => 'Apartment', 'property_size_m2' => 75, 'erf_size_m2' => null]);
        app(MicStatsBuilder::class)->refreshAgency($this->a);

        $c = $this->row($this->a, $tp);
        $this->assertSame('sectional', $c['sale_class']);
        $this->assertSame(2, $c['suburb_avg_sold_n']);
        $this->assertNull($c['suburb_avg_sold_12m']);
        $this->assertNull($c['suburb_r_per_m2']);
        $this->assertNull($c['own_vs_suburb_pct']);
        $this->assertNull($c['asking_vs_avg_sold_pct']);
        $this->assertSame(10000, $c['own_r_per_m2']);
        $this->assertSame('floor', $c['own_size_basis']);
        $this->assertSame(['complex', 'complex'], array_column($c['nearby_sales'], 'match'), 'same complex, still shown even though the average is withheld');
    }

    public function test_deeds_buyers_and_our_history_are_stored_as_ids_without_names(): void
    {
        $tp = $this->file($this->a, ['deeds_captured_at' => now(), 'last_known_sold_price' => 990_000, 'last_known_sold_date' => now()->subYears(2)->toDateString(), 'deeds_registered_date' => now()->subYears(2)->toDateString(), 'sale_type' => 'PRIVATE TREATY']);
        DB::table('tracked_property_owners')->insert(['agency_id' => $this->a, 'tracked_property_id' => $tp, 'name' => 'Secret Owner', 'id_type' => 'trust_reg', 'role' => 'owner', 'ownership_status' => 'current', 'created_at' => now(), 'updated_at' => now()]);
        $ad = $this->advert($this->a, $tp);

        $buyers = [];
        foreach ([['new', 95], ['warm', 85], ['new', 70], ['new', 55], ['cold', 99]] as [$state, $score]) {
            $c = Contact::withoutGlobalScopes()->create(['agency_id' => $this->a, 'branch_id' => $this->a, 'created_by_user_id' => $this->agent->id, 'is_buyer' => true, 'buyer_state' => $state,
                'first_name' => 'B', 'last_name' => 'Buyer ' . Str::random(4), 'phone' => '082' . random_int(1000000, 9999999), 'email' => Str::random(6) . '@e.co.za']);
            DB::table('prospecting_buyer_matches')->insert(['prospecting_listing_id' => $ad, 'contact_id' => $c->id, 'agency_id' => $this->a, 'score' => $score, 'tier' => $score >= 80 ? 'strong' : 'approximate', 'matched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $buyers[$score] = $c->id;
        }
        DB::table('prospecting_claims')->insert(['agency_id' => $this->a, 'prospecting_listing_id' => $ad, 'user_id' => $this->agent->id, 'status' => 'claimed', 'claimed_at' => now()->subDays(40), 'is_active' => 0, 'release_reason' => 'not_selling', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tracked_property_agency_work')->insert(['agency_id' => $this->a, 'tracked_property_id' => $tp, 'prospecting_status' => 'not_selling', 'prospecting_reason' => 'Owner is not selling', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('properties')->insert(['agency_id' => $this->a, 'address' => '13 Newport Road', 'suburb' => 'Uvongo', 'status' => 'expired', 'title' => '13 Newport Road', 'agent_id' => $this->agent->id, 'branch_id' => $this->a,
            'external_id' => (string) Str::uuid(), 'listing_type' => 'sale', 'tracked_property_id' => $tp, 'price' => 1_150_000, 'listed_date' => '2025-02-01', 'expiry_date' => '2025-08-01', 'mandate_type' => 'Sole', 'created_at' => now(), 'updated_at' => now()]);

        app(MicStatsBuilder::class)->refreshAgency($this->a);
        $c = $this->row($this->a, $tp);

        $this->assertEquals(['sold_on' => now()->subYears(2)->toDateString(), 'registered_on' => now()->subYears(2)->toDateString(), 'price' => 990_000, 'sale_type' => 'PRIVATE TREATY', 'buyer_type' => 'trust'], $c['last_deeds_sale']);
        $this->assertSame(2, $c['matched_buyers']['strong']);
        $this->assertSame(2, $c['matched_buyers']['mid']);
        $this->assertEquals([['contact_id' => $buyers[95], 'score' => 95], ['contact_id' => $buyers[85], 'score' => 85], ['contact_id' => $buyers[70], 'score' => 70]], $c['matched_buyers']['top'], 'best three, cold buyer excluded');
        $this->assertSame(1, $c['our_history']['claims']);
        $this->assertSame($this->agent->id, $c['our_history']['last_claim']['agent_id']);
        $this->assertSame('not_selling', $c['our_history']['last_claim']['reason']);
        $this->assertSame('not_selling', $c['our_history']['work_status']);
        $this->assertCount(1, $c['our_history']['mandates']);
        $this->assertEquals(['listed_on' => '2025-02-01', 'expires_on' => '2025-08-01', 'price' => 1_150_000, 'mandate_type' => 'Sole', 'status' => 'expired'], array_diff_key($c['our_history']['mandates'][0], ['property_id' => 1]));

        $stored = json_encode(DB::table('mic_file_context')->where('agency_id', $this->a)->where('tracked_property_id', $tp)->first());
        $this->assertStringNotContainsString('Secret Owner', $stored, 'no personal names in the stats table');
    }

    public function test_the_files_own_street_address_is_never_one_of_its_nearby_sales(): void
    {
        $this->comp($this->a, ['address' => '13 NEWPORT ROAD, UVONGO', 'sale_price' => 2_000_000, 'sale_date' => now()->subMonth()->toDateString()]);   // this very property
        $this->comp($this->a, ['address' => '15 Newport Road', 'sale_price' => 900_000, 'sale_date' => now()->subMonths(2)->toDateString()]);
        $tp = $this->file($this->a);   // 13 Newport Road
        $this->advert($this->a, $tp);

        app(MicStatsBuilder::class)->refreshAgency($this->a);

        $nearby = $this->row($this->a, $tp)['nearby_sales'];
        $this->assertSame([900_000], array_column($nearby, 'price'));
    }

    public function test_r_per_m2_uses_the_register_backed_size_when_there_is_one_and_the_advert_size_otherwise(): void
    {
        // deeds-captured file: the file's 1,212 m2 wins over the advert's 2,227 m2
        $deeded = $this->file($this->a, ['deeds_captured_at' => now(), 'erf_size_m2' => 1212]);
        $this->advert($this->a, $deeded, ['erf_size_m2' => 2227, 'price' => 1_020_000]);
        // plain file whose size only ever came from an advert: the lead advert's size is used, the file record is the last resort
        $plain = $this->file($this->a, ['street_number' => '20', 'erf_size_m2' => 1212]);
        $this->advert($this->a, $plain, ['erf_size_m2' => 2227, 'price' => 1_020_000]);
        // no advert size at all: falls back to the file record
        $bare = $this->file($this->a, ['street_number' => '30', 'erf_size_m2' => 900]);
        $this->advert($this->a, $bare, ['erf_size_m2' => null, 'price' => 900_000]);
        // a CMA entry in the source chain that contributed the size also counts as register-backed
        $cma = $this->file($this->a, ['street_number' => '40', 'erf_size_m2' => 1000, 'source_chain' => [['type' => 'cmainfo', 'ref' => 'report:1', 'fields_contributed' => ['erf_size_m2']]]]);
        $this->advert($this->a, $cma, ['erf_size_m2' => 2000, 'price' => 1_000_000]);

        app(MicStatsBuilder::class)->refreshAgency($this->a);

        $d = $this->row($this->a, $deeded);
        $this->assertSame(1212, $d['own_size_m2']);
        $this->assertSame('deeds', $d['own_size_source']);
        $this->assertSame(842, $d['own_r_per_m2']);

        $p = $this->row($this->a, $plain);
        $this->assertSame(2227, $p['own_size_m2']);
        $this->assertSame('advert', $p['own_size_source']);

        $b = $this->row($this->a, $bare);
        $this->assertSame(900, $b['own_size_m2']);
        $this->assertSame('file', $b['own_size_source']);

        $c = $this->row($this->a, $cma);
        $this->assertSame(1000, $c['own_size_m2']);
        $this->assertSame('deeds', $c['own_size_source']);
    }
}
