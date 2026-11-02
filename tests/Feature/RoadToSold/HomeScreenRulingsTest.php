<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoadToSold\OutcomeDefinitionService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/** The home page after the first-time-user audit (Johan, 2 Nov): one counter, the tiles are the belt, a tile filters the one list, plain words, dd/mm/yyyy. */
final class HomeScreenRulingsTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
        SettingsService::forget();
        OutcomeDefinitionService::forgetMemo();
        Cache::flush();
        $this->setUpAgency();
        app(OutcomeDefinitionService::class)->ensureDefaults($this->agency->id);
        foreach (['agent' => 'own'] as $role => $scope) {
            RolePermission::create(['role' => $role, 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
            RolePermission::create(['role' => $role, 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => $scope]);
        }
        PermissionService::clearCache();
        app(ProspectService::class)->place($this->agent, $this->tp(['street_number' => '1', 'street_name' => 'Start Road'])->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function page(array $q = [])
    {
        return $this->actingAs($this->agent)->get(route('road-to-sold.index', $q));
    }

    public function test_the_old_v3_summary_cards_and_the_pace_card_are_gone(): void
    {
        $h = $this->page()->assertOk()->getContent();
        foreach (['data-tour="smk-rts-summary"', 'data-qa="bar-line"', 'data-qa="bar-actual"', 'Should be on'] as $old) {
            $this->assertStringNotContainsString($old, $h, "'{$old}' belonged to the v3 summary cards");
        }
        $this->assertStringContainsString('data-qa="belt-tiles"', $h, 'the tiles are the belt');
    }

    public function test_a_tile_opens_the_home_list_filtered_to_that_station_not_the_prospects_page(): void
    {
        $h = $this->page()->getContent();
        $this->assertMatchesRegularExpression('/data-qa="belt-tile-owner"/', $h);
        foreach (['claimed', 'owner', 'contacted', 'booked', 'held', 'won'] as $key) {
            $this->assertStringContainsString('station=' . $key, $h);
        }
        preg_match_all('/<a href="([^"]+)"[^>]*data-qa="belt-tile-([a-z]+)"/', $h, $m);
        $this->assertCount(11, $m[2], 'eleven tiles');
        foreach ($m[1] as $href) {
            $this->assertStringNotContainsString('block=prospects', html_entity_decode($href), 'no tile opens the My prospects page');
        }
        $this->page(['station' => 'owner'])->assertOk()->assertSee('data-qa="station-list"', false)->assertSee('data-qa="rts-blocks"', false);
        $this->page(['station' => 'nonsense'])->assertNotFound();
    }

    public function test_my_prospects_has_no_21_box_strip_any_more(): void
    {
        $this->page(['block' => 'prospects'])->assertOk()->assertDontSee('data-station=', false)->assertDontSee('class="rts-grid18 rts-stations"', false);
    }

    public function test_one_name_per_selection_and_no_stuck_anywhere(): void
    {
        $svc = app(\App\Services\RoadToSold\CallListService::class);
        foreach (\App\Models\RoadToSold\RtsSetting::selectionCatalogue() as $key => $label) {
            $this->assertStringNotContainsStringIgnoringCase('stuck', $label);
            if (in_array($key, ['my_buyers_sourcing', 'agency_buyers_sourcing', 'hot_pockets'], true)) {
                $this->assertSame($label, $svc->selectionLabel($key), "{$key} has one name everywhere (settings, add-to-day, the list filter)");
            }
        }
        $this->assertSame("Colleagues' buyers need stock", $svc->selectionLabel('agency_buyers_sourcing'));
        $this->assertSame('Hottest pockets (long on market and reduced)', $svc->selectionLabel('hot_pockets'));
        $this->assertStringNotContainsStringIgnoringCase('stuck', $this->page()->getContent());
    }

    public function test_dates_on_the_day_filter_read_dd_mm_yyyy_and_post_iso(): void
    {
        $h = $this->page()->getContent();
        $this->assertStringContainsString('data-dmy-date', $h);
        $this->assertStringContainsString('placeholder="dd/mm/yyyy"', $h);
        $this->assertMatchesRegularExpression('#shown: .14.{1,2}10.{1,2}2026.#', $h, 'the shown value is dd/mm/yyyy');
        $this->assertStringContainsString('name="date"', $h);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="date"[^>]*name="date"/', $h, 'no browser-language date box on the Day filter');
        // the filter still reads the ISO value it posts
        $this->page(['date' => '2026-10-13'])->assertOk();
    }

    public function test_the_tab_reads_the_loaded_tile_not_a_count_of_its_own(): void
    {
        $h = $this->page()->getContent();
        $this->assertStringNotContainsString(' new</small>', $h);
        $this->assertMatchesRegularExpression('/data-block="intake">Today(?:\'|&#039;)s intake<small>\d+ loaded/', $h);
    }

    public function test_the_loaded_tile_popover_carries_the_by_source_split(): void
    {
        $json = $this->actingAs($this->agent)->getJson(route('road-to-sold.belt.scopes', ['station' => 'loaded']))->assertOk()->json();
        $labels = array_column($json['sources'], 'label');
        $this->assertSame(["My buyers' stock", "Colleagues' buyers' stock", 'Hot pockets', 'Fill-ups in my areas'], $labels);
        $other = $this->actingAs($this->agent)->getJson(route('road-to-sold.belt.scopes', ['station' => 'claimed']))->assertOk()->json();
        $this->assertArrayNotHasKey('sources', $other);
    }

    public function test_the_not_started_paused_panel_is_for_managers_only(): void
    {
        $this->assertStringNotContainsString('Not started', $this->page()->getContent(), 'an agent sees only themselves, so no panel about others');
    }
}
