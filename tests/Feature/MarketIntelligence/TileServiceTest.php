<?php

declare(strict_types=1);

namespace Tests\Feature\MarketIntelligence;

use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\MarketIntelligence\Pockets\TileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/mic-work-tab.md 11.1, step 5.6 (AT-519): tiles 1, 4, 5, 6, 7, 8, 9 read stored numbers, are agency-walled, and say
 * "too little data" instead of a number they cannot stand behind.
 */
final class TileServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $a;
    private int $b;
    private User $admin;
    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->agency();
        $this->b = $this->agency();
        $this->admin = User::factory()->create(['agency_id' => $this->a, 'branch_id' => $this->a, 'role' => 'admin', 'is_active' => true]);
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

    private function fileStat(int $agency, int $tp, array $o = []): void
    {
        DB::table('mic_file_stats')->insert(array_merge([
            'agency_id' => $agency, 'tracked_property_id' => $tp, 'p24_suburb_id' => $this->sub, 'type_key' => 'house', 'beds' => 3, 'band' => 'lt1m', 'price' => 800_000,
            'has_active_advert' => 1, 'own_stock' => 0, 'computed_at' => now(),
        ], $o));
    }

    private function pocket(int $agency, array $o = []): void
    {
        DB::table('mic_pocket_stats')->insert(array_merge([
            'agency_id' => $agency, 'stat_date' => now()->toDateString(), 'p24_suburb_id' => $this->sub, 'type_key' => 'house', 'beds' => 3, 'band' => 'lt1m',
            'created_at' => now(), 'updated_at' => now(),
        ], $o));
    }

    private function tp(int $agency): int
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(['agency_id' => $agency, 'status' => 'active', 'suburb' => 'Uvongo'])->id;
    }

    public function test_stuck_tile_counts_cuts_relisted_and_long_dom_for_this_agency_only_and_filters_the_list(): void
    {
        [$cut, $relisted, $old, $fresh] = [$this->tp($this->a), $this->tp($this->a), $this->tp($this->a), $this->tp($this->a)];
        $this->fileStat($this->a, $cut, ['cuts_count' => 2, 'cuts_any' => 2, 'cuts_30d' => 1, 'dom_days' => 10]);
        $this->fileStat($this->a, $relisted, ['relisted' => 1, 'dom_days' => 10]);
        $this->fileStat($this->a, $old, ['dom_days' => 120]);
        $this->fileStat($this->a, $fresh, ['dom_days' => 5]);
        $this->fileStat($this->a, $this->tp($this->a), ['own_stock' => 1, 'cuts_count' => 1, 'cuts_any' => 1, 'dom_days' => 200]);   // our own: not "stuck on the market"
        $this->fileStat($this->b, $this->tp($this->b), ['cuts_count' => 5, 'cuts_any' => 5]);

        $t = app(TileService::class)->tile(1, $this->admin);
        $this->assertSame(3, $t->count);
        $this->assertSame(['tile' => 'stuck'], $t->filter);
        $this->assertSame(1, $t->meta['relisted']);
        $this->assertSame(1, $t->meta['with_cut'], 'our own stock and another agency are not counted');

        $ids = app(TileService::class)->fileIds('stuck', $this->admin)->pluck('tracked_property_id')->all();
        sort($ids);
        $this->assertSame([$cut, $relisted, $old], $ids);
    }

    public function test_owner_tile_counts_live_owner_adverts_unclaimed_first_for_this_agency_only(): void
    {
        $provider = app(\App\Services\MarketIntelligence\Pockets\OwnerAdvertTileProvider::class);
        $this->assertSame(2, $provider->tile());

        [$free, $claimed, $notOwner, $own] = [$this->tp($this->a), $this->tp($this->a), $this->tp($this->a), $this->tp($this->a)];
        $this->fileStat($this->a, $free, ['owner_advert' => 1, 'claimed' => 0, 'dom_days' => 90]);
        $this->fileStat($this->a, $claimed, ['owner_advert' => 1, 'claimed' => 1, 'dom_days' => 200]);
        $this->fileStat($this->a, $notOwner, ['owner_advert' => 0]);
        $this->fileStat($this->a, $own, ['owner_advert' => 1, 'own_stock' => 1]);
        $this->fileStat($this->b, $this->tp($this->b), ['owner_advert' => 1]);

        $s = $provider->summary($this->a, $this->admin);
        $this->assertSame(2, $s['count']);
        $this->assertSame('2 owners advertising privately', $s['reason']);
        $this->assertSame('1 not yet claimed', $s['note']);

        $ids = $provider->propertyIds($this->a, $this->admin)->pluck('tracked_property_id')->all();
        sort($ids);
        $this->assertSame([$free, $claimed], $ids);
    }

    public function test_fast_sellers_says_too_little_data_below_the_minimum_and_a_number_above_it(): void
    {
        $this->pocket($this->a, ['own_sales_trusted' => 1, 'own_fast_sales' => 1, 'own_avg_days_to_sale' => 20, 'market_stock' => 7]);
        $t = app(TileService::class)->tile(5, $this->admin);
        $this->assertTrue($t->tooLittleData);
        $this->assertSame('1 sale — too few to rank', $t->tooLittleMessage);
        $this->assertNull($t->count);

        DB::table('mic_pocket_stats')->where('agency_id', $this->a)->update(['own_sales_trusted' => 4, 'own_fast_sales' => 3, 'own_avg_days_to_sale' => 25.0]);
        $t = app(TileService::class)->tile(5, $this->admin);
        $this->assertFalse($t->tooLittleData);
        $this->assertSame(7, $t->count);
        $this->assertStringContainsString('based on 4 sales', $t->reason);

        $tp = $this->tp($this->a);
        $this->fileStat($this->a, $tp);
        $this->assertSame([$tp], app(TileService::class)->fileIds('fast_sellers', $this->admin)->pluck('tracked_property_id')->all());
    }

    public function test_buyers_waiting_counts_pockets_with_three_plus_buyers_and_flags_short_stock(): void
    {
        $this->pocket($this->a, ['buyers' => 5, 'market_stock' => 2, 'own_on_market' => 1]);
        $this->pocket($this->a, ['beds' => 4, 'buyers' => 2, 'market_stock' => 9]);
        $this->pocket($this->b, ['buyers' => 9]);

        $t = app(TileService::class)->tile(7, $this->admin);
        $this->assertSame(1, $t->count);
        $this->assertSame(1, $t->meta['stock_short']);
        $this->assertSame(5, $t->meta['pockets'][0]['buyers']);
    }

    public function test_alerts_tile_reports_honestly_when_nothing_new_and_counts_a_fresh_one(): void
    {
        $this->assertTrue(app(TileService::class)->tile(8, $this->admin)->tooLittleData, 'no alert rows at all');

        DB::table('p24_listings')->insert(['agency_id' => $this->a, 'p24_listing_number' => 'P24-1', 'asking_price' => 900_000, 'first_seen_date' => now()->subDays(30)->toDateString(), 'last_seen_date' => now()->subDays(30)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $t = app(TileService::class)->tile(8, $this->admin);
        $this->assertSame(0, $t->count);
        $this->assertStringContainsString('No new alerts in 7 days', $t->reason);
        $this->assertStringContainsString('last alert ' . now()->subDays(30)->format('j M'), $t->reason);

        DB::table('p24_listings')->insert(['agency_id' => $this->a, 'p24_listing_number' => 'P24-2', 'asking_price' => 900_000, 'first_seen_date' => now()->toDateString(), 'last_seen_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('p24_listings')->insert(['agency_id' => $this->b, 'p24_listing_number' => 'P24-3', 'asking_price' => 900_000, 'first_seen_date' => now()->toDateString(), 'last_seen_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, app(TileService::class)->tile(8, $this->admin)->count);
    }

    public function test_leads_and_market_prices_say_too_little_when_empty_and_the_settings_hide_tiles(): void
    {
        $this->assertTrue(app(TileService::class)->tile(6, $this->admin)->tooLittleData);
        $this->assertTrue(app(TileService::class)->tile(9, $this->admin)->tooLittleData);
        $this->assertTrue(app(TileService::class)->tile(1, $this->admin)->tooLittleData, 'no live adverts read yet');

        $this->assertSame([1, 4, 5, 6, 7, 8, 9], array_keys(app(TileService::class)->forViewer($this->admin)));
        DB::table('mic_agency_settings')->insert(['agency_id' => $this->a, 'values' => json_encode(['tiles_shown' => [1, 9]]), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([1, 9], array_keys(app(TileService::class)->forViewer($this->admin)));
    }
}
