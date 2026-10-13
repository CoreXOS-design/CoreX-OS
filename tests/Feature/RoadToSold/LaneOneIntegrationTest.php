<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RoadToSold\RtsAgentArea;
use App\Models\RoadToSold\RtsListItem;
use App\Models\RoadToSold\RtsSetting;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\Support\BuilderSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * AT-454 — lane 2 against lane 1's REAL classes (SettingsService, AreaService, rts_settings,
 * rts_agent_areas), through the container exactly as the nightly command builds it. The other tests
 * use fakes for the other lanes; this one proves the adapters really connect.
 */
final class LaneOneIntegrationTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private int $uvongoId;
    private int $ramsgateId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        SettingsService::forget();
        Cache::flush();

        $country = DB::table('p24_countries')->insertGetId(['p24_id' => 9001, 'name' => 'South Africa']);
        $province = DB::table('p24_provinces')->insertGetId(['p24_id' => 9001, 'p24_country_id' => $country, 'name' => 'KwaZulu-Natal']);
        $city = DB::table('p24_cities')->insertGetId(['p24_id' => 9011, 'p24_province_id' => $province, 'name' => 'Margate']);
        $mk = fn (string $n, int $p24) => DB::table('p24_suburbs')->insertGetId([
            'name' => $n, 'slug' => strtolower(str_replace(' ', '-', $n)), 'p24_id' => $p24, 'p24_city_id' => $city,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->uvongoId = $mk('Uvongo', 900101);
        $this->ramsgateId = $mk('Ramsgate', 900103);
        DB::table('towns')->insert([
            'agency_id' => $this->agency->id, 'name' => 'Margate', 'slug' => 'margate', 'p24_city_id' => $city,
            'display_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function settings(array $attrs): void
    {
        RtsSetting::create(array_merge(['agency_id' => $this->agency->id, 'track' => 'sales'], $attrs));
        SettingsService::forget();
    }

    private function assign(int $suburbId): void
    {
        RtsAgentArea::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'track' => 'sales',
            'user_id' => $this->agent->id, 'p24_suburb_id' => $suburbId,
        ]);
    }

    public function test_builder_settings_read_what_the_agency_saved(): void
    {
        $this->settings([
            'lock_agents_to_areas' => true, 'missed_items_mode' => 'roll_over',
            'new_on_market_days' => 21, 'long_on_market_days' => 120, 'owner_known_quantity' => 7,
            'stuck_stock_min_days' => 45, 'stuck_stock_max_views' => 12, 'stuck_stock_max_leads' => 4,
            'hot_pocket_lookback_days' => 365, 'price_drop_lookback_days' => 14, 'stuck_stock_lookback_days' => 60,
            'selections' => [
                ['key' => 'long_on_market', 'enabled' => true], ['key' => 'price_drops', 'enabled' => true],
                ['key' => 'new_on_market', 'enabled' => false],
            ],
        ]);

        $s = BuilderSettings::forUser($this->agent);

        $this->assertTrue($s->lockAgentsToAreas);
        $this->assertTrue($s->rollsOver);
        $this->assertSame([21, 120, 7], [$s->newOnMarketDays, $s->longOnMarketDays, $s->ownerKnownQuantity]);
        $this->assertSame([45, 12, 4], [$s->stuckStockMinDaysOnMarket, $s->stuckStockMaxViews, $s->stuckStockMaxLeads]);
        $this->assertSame([365, 14, 60], [$s->hotPocketLookbackDays, $s->priceDropLookbackDays, $s->stuckStockLookbackDays]);
        $this->assertSame('long_on_market', $s->enabledSelectionKeys[0]);
        $this->assertSame('price_drops', $s->enabledSelectionKeys[1]);
        $this->assertNotContains('new_on_market', $s->enabledSelectionKeys);
    }

    public function test_an_agency_with_no_saved_settings_gets_the_documented_defaults(): void
    {
        $s = BuilderSettings::forUser($this->agent);

        $this->assertFalse($s->lockAgentsToAreas);
        $this->assertFalse($s->rollsOver);
        $this->assertSame([14, 90, 3], [$s->newOnMarketDays, $s->longOnMarketDays, $s->ownerKnownQuantity]);
        $this->assertSame(BuilderSettings::DEFAULT_SELECTION_ORDER, $s->enabledSelectionKeys);
        $this->assertSame(0, RtsSetting::withoutGlobalScopes()->count(), 'reading never writes');
    }

    public function test_the_container_built_builder_honours_real_areas_and_the_real_lock(): void
    {
        $this->settings(['lock_agents_to_areas' => true, 'selections' => [['key' => 'long_on_market', 'enabled' => true]]]);
        $this->assign($this->uvongoId);
        $in = $this->listing(['first_seen_at' => '2026-03-01', 'suburb' => 'Uvongo'], $this->tp(['suburb_normalised' => 'uvongo']));
        $this->listing(['first_seen_at' => '2026-03-01', 'suburb' => 'Ramsgate'], $this->tp(['suburb_normalised' => 'ramsgate']));

        $items = app(ListBuilderService::class)->buildFor($this->agent, '2026-10-14');

        $this->assertSame([$in], $items->pluck('prospecting_listing_id')->all(), 'locked to Uvongo');
    }

    public function test_with_the_lock_off_the_agents_real_areas_still_come_first(): void
    {
        $this->settings(['lock_agents_to_areas' => false, 'selections' => [['key' => 'long_on_market', 'enabled' => true]]]);
        $this->assign($this->ramsgateId);
        $uv = $this->listing(['first_seen_at' => '2026-03-01', 'suburb' => 'Uvongo'], $this->tp(['suburb_normalised' => 'uvongo']));
        $rams = $this->listing(['first_seen_at' => '2026-03-01', 'suburb' => 'Ramsgate'], $this->tp(['suburb_normalised' => 'ramsgate']));

        $items = app(ListBuilderService::class)->buildFor($this->agent, '2026-10-14');

        $this->assertSame([$rams, $uv], $items->pluck('prospecting_listing_id')->all());
    }

    public function test_a_locked_agent_with_no_areas_gets_no_list_and_an_unlocked_one_gets_the_pool(): void
    {
        $this->settings(['lock_agents_to_areas' => true, 'selections' => [['key' => 'long_on_market', 'enabled' => true]]]);
        $this->listing(['first_seen_at' => '2026-03-01']);

        $this->assertCount(0, app(ListBuilderService::class)->buildFor($this->agent, '2026-10-14'));

        RtsSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->update(['lock_agents_to_areas' => false]);
        SettingsService::forget();
        Cache::flush();
        $this->assertCount(1, app(ListBuilderService::class)->buildFor($this->agent, '2026-10-14'));
    }

    public function test_rolling_over_follows_the_real_agency_setting(): void
    {
        $this->settings(['missed_items_mode' => 'roll_over']);
        $old = RtsListItem::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id, 'list_date' => '2026-10-12', 'track' => 'sales',
            'tracked_property_id' => 4242, 'selection' => 'long_on_market', 'origin' => 'auto', 'position' => 1, 'status' => 'open',
        ]);

        $r = app(ListBuilderService::class)->closePastDays($this->agent, '2026-10-14');

        $this->assertSame(['missed' => 0, 'rolled' => 1], $r);
        $this->assertSame('rolled', $old->fresh()->status);

        // a second agency that never touched the setting still gets the default (missed, not rolled)
        $other = \App\Models\Agency::create(['name' => 'Cape ' . uniqid(), 'slug' => 'cape-' . uniqid()]);
        $ob = \App\Models\Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $capeAgent = $this->makeAgent($other, $ob);
        $capeOld = RtsListItem::withoutGlobalScopes()->create([
            'agency_id' => $other->id, 'user_id' => $capeAgent->id, 'list_date' => '2026-10-12', 'track' => 'sales',
            'tracked_property_id' => 4243, 'selection' => 'long_on_market', 'origin' => 'auto', 'position' => 1, 'status' => 'open',
        ]);
        $this->assertSame(['missed' => 1, 'rolled' => 0], app(ListBuilderService::class)->closePastDays($capeAgent, '2026-10-14'));
        $this->assertSame('missed', $capeOld->fresh()->status);
    }
}
